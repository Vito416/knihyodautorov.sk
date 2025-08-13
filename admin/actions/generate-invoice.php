<?php
// /admin/actions/generate-invoice.php  (PATCHed)
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
require __DIR__ . '/../bootstrap.php';
require_admin();

// helpery
require_once __DIR__ . '/../libs/qr_helper.php';
require_once __DIR__ . '/../admin/lib/invoice_template.php';

// CSRF + POST check
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo "Only POST"; exit; }
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf'] ?? '')) {
    http_response_code(403); echo "Neplatný CSRF"; exit;
}

$orderId = (int)($_POST['order_id'] ?? 0);
if ($orderId <= 0) { $_SESSION['flash_error']='Neplatné ID objednávky.'; header('Location: /admin/orders.php'); exit; }

try {
    $stmt = $pdo->prepare("SELECT o.*, u.meno AS user_name, u.email AS user_email FROM orders o LEFT JOIN users u ON o.user_id=u.id WHERE o.id = ? LIMIT 1");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) throw new Exception('Objednávka nenájdená.');

    $itemsStmt = $pdo->prepare("SELECT oi.*, b.nazov AS book_name FROM order_items oi LEFT JOIN books b ON oi.book_id=b.id WHERE oi.order_id=?");
    $itemsStmt->execute([$orderId]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$items) $items = [];

    // compute totals and tax
    $subtotal = 0.0;
    $itemsForTemplate = [];
    foreach ($items as $it) {
        $line = (float)$it['unit_price'] * (int)$it['quantity'];
        $subtotal += $line;
        $itemsForTemplate[] = [
            'name' => $it['book_name'] ?? ('ID:' . (int)$it['book_id']),
            'qty' => (int)$it['quantity'],
            'unit_price' => (float)$it['unit_price'],
        ];
    }
    $taxRate = 20.0; // default
    $tax = $subtotal * ($taxRate/100.0);
    $total = $subtotal + $tax;
    $currency = $order['currency'] ?? 'EUR';

    // invoice fields
    $variableSymbol = (string)time() . random_int(100,999);
    $invoiceNumber = date('Y') . '-' . str_pad((string)$orderId, 6, '0', STR_PAD_LEFT);
    $createdAt = date('Y-m-d H:i');

    // generate QR (simple payload)
    $qrPayload = "VS:$variableSymbol;SUM:" . number_format($total,2,'.','') . ";CUR:$currency";
    $qrDataUri = qr_png_datauri($qrPayload, 4, 2);

    // optional stamp (if you have stamp.png in assets)
    $stampPath = realpath(__DIR__ . '/../assets/stamp.png');
    $stampDataUri = null;
    if ($stampPath && file_exists($stampPath)) {
        $stampDataUri = 'data:image/png;base64,' . base64_encode(file_get_contents($stampPath));
    }

    // compose template
    $company = [
        'name' => 'Knihy od autorov s.r.o.',
        'address' => "Ulica 1\n010 01 Mesto\nSlovensko"
    ];
    $client = [
        'name' => $order['user_name'] ?? ($order['email'] ?? ''),
        'address' => $order['delivery_address'] ?? ''
    ];
    $meta = [
        'invoice_number' => $invoiceNumber,
        'created_at' => $createdAt,
        'company' => $company,
        'client' => $client,
        'items' => $itemsForTemplate,
        'subtotal' => $subtotal,
        'tax_rate' => $taxRate,
        'tax' => $tax,
        'total' => $total,
        'currency' => $currency,
        'variable_symbol' => $variableSymbol,
        'qr_datauri' => $qrDataUri,
        'stamp_datauri' => $stampDataUri
    ];

    $html = render_invoice_html($meta);

    // ensure invoice table exists and invoices dir
    $pdo->exec("CREATE TABLE IF NOT EXISTS invoices (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      order_id INT UNSIGNED NOT NULL,
      invoice_number VARCHAR(100) NOT NULL,
      pdf_file VARCHAR(255) DEFAULT NULL,
      total DECIMAL(10,2) DEFAULT 0.00,
      currency CHAR(3) DEFAULT 'EUR',
      tax_rate DECIMAL(5,2) DEFAULT 0.00,
      variable_symbol VARCHAR(50) DEFAULT NULL,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $ins = $pdo->prepare("INSERT INTO invoices (order_id, invoice_number, total, currency, tax_rate, variable_symbol) VALUES (?, ?, ?, ?, ?, ?)");
    $ins->execute([$orderId, $invoiceNumber, number_format($total,2,'.',''), $currency, number_format($taxRate,2,'.',''), $variableSymbol]);
    $invoiceId = (int)$pdo->lastInsertId();

    $invoicesDir = realpath(__DIR__ . '/../eshop/invoices') ?: (__DIR__ . '/../eshop/invoices');
    @mkdir($invoicesDir, 0755, true);

    $pdfFileName = "invoice_{$invoiceId}_" . time() . ".pdf";
    $pdfPath = $invoicesDir . '/' . $pdfFileName;

    // Try to create PDF with mPDF — robustly (konštanty fallback)
    $generatedPdf = false;
    if (class_exists('\Mpdf\Mpdf')) {
        try {
            $tmp = __DIR__ . '/../tmp';
            @mkdir($tmp, 0755, true);
            $mpdf = new \Mpdf\Mpdf(['tempDir' => $tmp]);
            $mpdf->WriteHTML($html);
            try {
                // prefer constant if exists
                if (defined('\Mpdf\Output\Destination::FILE')) {
                    $mpdf->Output($pdfPath, \Mpdf\Output\Destination::FILE);
                } else {
                    // fallback to short string 'F' (save to file)
                    $mpdf->Output($pdfPath, 'F');
                }
            } catch (Throwable $e) {
                // fallback to 'F'
                $mpdf->Output($pdfPath, 'F');
            }
            $generatedPdf = true;
        } catch (Throwable $e) {
            // mPDF error -> fallback to save HTML
            file_put_contents($invoicesDir . "/invoice_{$invoiceId}_" . time() . ".html", $html);
            $generatedPdf = false;
        }
    } else {
        // no mPDF -> save HTML fallback
        $htmlFileName = "invoice_{$invoiceId}_" . time() . ".html";
        file_put_contents($invoicesDir . '/' . $htmlFileName, $html);
        $pdfFileName = $htmlFileName;
        $generatedPdf = false;
    }

    if ($generatedPdf) {
        $pdo->prepare("UPDATE invoices SET pdf_file = ? WHERE id = ?")->execute([$pdfFileName, $invoiceId]);
    } else {
        // update to point to the HTML fallback
        $pdo->prepare("UPDATE invoices SET pdf_file = ? WHERE id = ?")->execute([$pdfFileName, $invoiceId]);
    }

    // audit
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS audit_log (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT DEFAULT NULL, action VARCHAR(255), meta TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $admin = admin_user($pdo);
        $metaJson = json_encode(['invoice_id'=>$invoiceId,'order_id'=>$orderId], JSON_UNESCAPED_UNICODE);
        $pdo->prepare("INSERT INTO audit_log (user_id, action, meta) VALUES (?, ?, ?)")->execute([$admin['id'] ?? null, 'generate_invoice', $metaJson]);
    } catch (Throwable $_e) {}

    $_SESSION['flash_message'] = 'Faktúra vytvorená: ' . $invoiceNumber;
    header('Location: /admin/invoices.php');
    exit;

} catch (Throwable $e) {
    $_SESSION['flash_error'] = 'Chyba pri generovaní faktúry: ' . $e->getMessage();
    header('Location: /admin/orders.php');
    exit;
}
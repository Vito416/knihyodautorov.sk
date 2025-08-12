<?php
// /admin/invoice-generate.php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
if (!admin_is_logged()) { header('Location: login.php'); exit; }

$order_id = isset($_REQUEST['order_id']) ? (int)$_REQUEST['order_id'] : 0;
$regen = isset($_REQUEST['regen']) ? (int)$_REQUEST['regen'] : 0;

if ($order_id <= 0 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    // show small form to enter order id
    ?>
    <!doctype html><html lang="sk"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Generovať faktúru — Admin</title>
    <link rel="stylesheet" href="/admin/css/admin.css"><link rel="stylesheet" href="/admin/css/invoices.css">
    </head><body>
      <main class="admin-shell">
        <header class="admin-top"><h1>Generovať faktúru</h1></header>
        <section class="panel">
          <form method="post">
            <label>Order ID <input type="number" name="order_id" required></label>
            <label>Menujúci poznámku pre faktúru (voliteľné) <input type="text" name="note" placeholder="Dodacia poznámka"></label>
            <div style="margin-top:12px;"><button class="btn" type="submit">Vygenerovať</button> <a class="btn ghost" href="/admin/invoices.php">Späť</a></div>
          </form>
        </section>
      </main>
    </body></html>
    <?php
    exit;
}

// POST alebo order_id v GET
if ($_SERVER['REQUEST_METHOD'] === 'POST' || $order_id > 0) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $order_id = (int)($_POST['order_id'] ?? 0);
    }
    $note = trim((string)($_REQUEST['note'] ?? ''));

    // load order
    $s = $pdo->prepare("SELECT * FROM orders WHERE id = ? LIMIT 1");
    $s->execute([$order_id]);
    $order = $s->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        die("Objednávka nenájdená.");
    }

    // load order items
    $si = $pdo->prepare("SELECT oi.*, b.nazov, b.pdf_file FROM order_items oi JOIN books b ON b.id = oi.book_id WHERE oi.order_id = ?");
    $si->execute([$order_id]);
    $items = $si->fetchAll(PDO::FETCH_ASSOC);

    // load user
    $su = $pdo->prepare("SELECT id, meno, email, telefon, adresa FROM users WHERE id = ? LIMIT 1");
    $su->execute([$order['user_id']]);
    $user = $su->fetch(PDO::FETCH_ASSOC);

    // company info from settings
    $settings = [];
    $rs = $pdo->query("SELECT k,v FROM settings")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rs as $_r) $settings[$_r['k']] = $_r['v'] ?? '';

    // ensure invoices table exists (robust check)
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS invoices (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        invoice_number VARCHAR(60) NOT NULL,
        order_id INT UNSIGNED NOT NULL,
        total_amount DECIMAL(10,2) NOT NULL,
        currency CHAR(3) DEFAULT 'EUR',
        pdf_file VARCHAR(255) DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // generate invoice number: YYYY/0000ID
    $prefix = $settings['company_vat_prefix'] ?? date('Y');
    $invoiceNumber = sprintf("%s/%05d", $prefix, (int)$order_id);

    $total = (float)$order['total_price'];
    $currency = $order['currency'] ?? ($settings['currency'] ?? 'EUR');

    // insert invoice record (pdf_file will be filled after PDF generation)
    $stmt = $pdo->prepare("INSERT INTO invoices (invoice_number, order_id, total_amount, currency) VALUES (?, ?, ?, ?)");
    $stmt->execute([$invoiceNumber, $order_id, number_format($total,2,'.',''), $currency]);
    $invoiceId = (int)$pdo->lastInsertId();

    // render HTML invoice (use invoice-view template but capture output)
    ob_start();
    include __DIR__ . '/invoice-template-html.php'; // we'll create this below
    $html = ob_get_clean();

    // ensure invoice folders exist
    $invoicesDir = realpath(__DIR__ . '/../eshop/invoices') ?: __DIR__ . '/../eshop/invoices';
    if (!is_dir($invoicesDir)) @mkdir($invoicesDir, 0755, true);

    $pdfFilename = 'invoice_' . $invoiceId . '.pdf';
    $pdfPath = $invoicesDir . DIRECTORY_SEPARATOR . $pdfFilename;

    // try to generate PDF via mpdf if available
    $mpdfPath = __DIR__ . '/libs/mpdf/autoload.php';
    $pdfGenerated = false;
    if (file_exists($mpdfPath)) {
        try {
            require_once $mpdfPath;
            $mpdf = new \Mpdf\Mpdf(['tempDir' => __DIR__ . '/tmp']);
            $mpdf->WriteHTML($html);
            $mpdf->Output($pdfPath, \Mpdf\Output\Destination::FILE);
            $pdfGenerated = true;
        } catch (Throwable $e) {
            error_log("MPDF error: ".$e->getMessage());
            $pdfGenerated = false;
        }
    }

    if (!$pdfGenerated) {
        // fallback: save HTML version as .html and set pdf_file empty
        $htmlPath = $invoicesDir . DIRECTORY_SEPARATOR . 'invoice_' . $invoiceId . '.html';
        @file_put_contents($htmlPath, $html);
        // optionally convert by external tool later
    }

    // update invoice record with pdf_file if generated
    if ($pdfGenerated) {
        $pdo->prepare("UPDATE invoices SET pdf_file = ? WHERE id = ?")->execute([$pdfFilename, $invoiceId]);
        $message = 'Faktúra vygenerovaná a uložená ako PDF.';
    } else {
        $pdo->prepare("UPDATE invoices SET pdf_file = NULL WHERE id = ?")->execute([$invoiceId]);
        $message = 'Faktúra vygenerovaná (HTML). Pre PDF nainštalujte mpdf do /admin/libs/mpdf alebo použite externý konvertor.';
    }

    // redirect na prehľad alebo zobrazenie
    header('Location: /admin/invoice-view.php?id=' . $invoiceId . '&note=' . urlencode($message));
    exit;
}
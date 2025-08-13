<?php
declare(strict_types=1);
/**
 * /eshop/prints/invoice-template.php
 *
 * Generuje HTML + PDF faktúru pro dané order_id.
 * Uloží HTML a PDF do /eshop/prints/generated/ a vloží záznam do invoices.
 *
 * Volání:
 * - web: /eshop/prints/invoice-template.php?order_id=123 (bez autentifikace - používej zabezpečené prostředí)
 * - CLI: php invoice-template.php 123
 *
 * POZNÁMKA: mPDF a phpqrcode musí být dostupné v /libs a zaregistrované v autoload.php
 */

require_once __DIR__ . '/../_init.php';

$pdoLocal = $GLOBALS['pdo'] ?? null;
if (!($pdoLocal instanceof PDO)) {
    eshop_log('ERROR', '/prints/invoice-template: PDO není dostupné.');
    http_response_code(500);
    echo "Interní chyba DB.";
    exit;
}

// Získání order_id (CLI nebo GET)
$orderId = 0;
if (PHP_SAPI === 'cli') {
    $orderId = isset($argv[1]) ? (int)$argv[1] : 0;
} else {
    $orderId = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
}

if ($orderId <= 0) {
    eshop_log('ERROR', '/prints/invoice-template: neplatné order_id');
    http_response_code(400);
    echo "Neplatné order_id.";
    exit;
}

try {
    // Načíst objednávku + user
    $stmt = $pdoLocal->prepare("SELECT o.*, u.meno AS user_meno, u.email AS user_email FROM orders o LEFT JOIN users u ON o.user_id = u.id WHERE o.id = ? LIMIT 1");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        eshop_log('ERROR', "invoice-template: objednávka {$orderId} nenalezena");
        http_response_code(404);
        echo "Objednávka nenalezena.";
        exit;
    }

    // Načíst položky
    $stmt = $pdoLocal->prepare("SELECT oi.book_id, oi.quantity, oi.unit_price, b.nazov, b.author_id FROM order_items oi LEFT JOIN books b ON oi.book_id = b.id WHERE oi.order_id = ?");
    $stmt->execute([$orderId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Vypočítat částky
    $amount = (float)$order['total_price'];
    $vat_amount = isset($order['dph_rate']) ? round($amount * ((float)$order['dph_rate'] / 100), 2) : 0.0;
    $currency = $order['currency'] ?? 'EUR';

    // Generovat číslo faktury: INV-YYYYMMDD-orderId (můžeš změnit dle účetních požadavků)
    $invoiceNumber = 'INV-' . date('Ymd') . '-' . $orderId;

    // Příprava adresářů
    $printsDir = realpath(__DIR__) ?: __DIR__;
    $generatedDir = ESHOP_ROOT . '/prints/generated';
    if (!is_dir($generatedDir)) {
        @mkdir($generatedDir, 0775, true);
        @chmod($generatedDir, 0775);
    }

    // Vytvoříme jednoduchý QR payload (můžeš nahradit IBAN/platební instrukcí)
    $qrText = "Objednavka: {$orderId}\nVS: {$order['variabilny_symbol']}\nSuma: " . number_format($amount, 2, ',', '') . " {$currency}";
    $qrFile = TMP_DIR . '/invoice_qr_' . $invoiceNumber . '.png';

    // Pokus o vytvoření QR přes phpqrcode (pokud je dostupné)
    if (class_exists('QRcode')) {
        // phpqrcode: QRcode::png($text, $outfile=false, $level=QR_ECLEVEL_L, $size=3, $margin=4);
        \QRcode::png($qrText, $qrFile, 'L', 6, 2);
    } elseif (function_exists('png')) {
        // fallback - nic
    } else {
        // pokud není phpqrcode, necháme $qrFile neexistovat — faktura bude i bez obrázku
        $qrFile = null;
        eshop_log('WARN', 'invoice-template: phpqrcode nenalezen, QR nebude vložen');
    }

    $qrBase = '';
    if (!empty($qrFile) && is_file($qrFile)) {
        $qrBase = base64_encode((string)file_get_contents($qrFile));
    }

    // Vytvoř HTML faktury (jednoduchá šablona, můžeš upravit styly)
    $companyName = 'Knihy od Autorov';
    $companyAddress = 'Ulica 1, 010 01 Mesto';
    $companyIco = '12345678';
    $companyDic = 'SK1234567890';
    $bankIban = 'SK0000000000000000000000'; // uprav dle potřeby

    $html = '<!doctype html><html lang="sk"><head><meta charset="utf-8"><title>Faktúra ' . htmlspecialchars($invoiceNumber, ENT_QUOTES | ENT_HTML5) . '</title>';
    $html .= '<style>
        :root{ --paper:#fffaf1; --ink:#3b2a1a; --accent:#c08a2e; --muted:#6b6155; }
        body{ font-family: DejaVu Sans, Arial, sans-serif; color:var(--ink); background:#fff; margin:0; padding:24px; }
        .wrap{ max-width:800px; margin:0 auto; background:var(--paper); padding:18px; border-radius:10px; }
        header{ display:flex; justify-content:space-between; align-items:center; }
        h1{ margin:0; font-size:20px; }
        table{ width:100%; border-collapse:collapse; margin-top:12px; }
        th,td{ padding:8px; border-bottom:1px solid #eee; text-align:left; }
        .right{text-align:right;}
        .total{ font-weight:800; font-size:1.05rem; }
        .meta{ color:var(--muted); font-size:0.9rem; }
    </style></head><body><div class="wrap">';
    $html .= '<header><div><h1>Faktúra ' . htmlspecialchars($invoiceNumber, ENT_QUOTES | ENT_HTML5) . '</h1><div class="meta">Vystavené: ' . htmlspecialchars((string)$order['created_at'], ENT_QUOTES | ENT_HTML5) . '</div></div>';
    if (!empty($qrBase)) {
        $html .= '<div><img src="data:image/png;base64,' . $qrBase . '" style="width:120px; height:120px;"/></div>';
    }
    $html .= '</header>';

    $html .= '<section style="margin-top:12px; display:flex; justify-content:space-between;">';
    $html .= '<div><strong>' . htmlspecialchars($companyName, ENT_QUOTES | ENT_HTML5) . '</strong><div class="meta">' . htmlspecialchars($companyAddress, ENT_QUOTES | ENT_HTML5) . '</div></div>';
    $html .= '<div><strong>Fakturované pre</strong><div class="meta">' . htmlspecialchars($order['user_meno'] ?? ($order['user_email'] ?? 'Host'), ENT_QUOTES | ENT_HTML5) . '</div></div>';
    $html .= '</section>';

    $html .= '<table><thead><tr><th>#</th><th>Názov</th><th>Množstvo</th><th>Jedn.cena</th><th class="right">Medzisúčet</th></tr></thead><tbody>';
    $i = 1;
    foreach ($items as $it) {
        $sub = (float)$it['unit_price'] * (int)$it['quantity'];
        $html .= '<tr>';
        $html .= '<td>' . $i . '</td>';
        $html .= '<td>' . htmlspecialchars($it['nazov'] ?? 'Kniha #' . (int)$it['book_id'], ENT_QUOTES | ENT_HTML5) . '</td>';
        $html .= '<td>' . (int)$it['quantity'] . '</td>';
        $html .= '<td>' . number_format((float)$it['unit_price'], 2, ',', ' ') . ' ' . htmlspecialchars($currency, ENT_QUOTES | ENT_HTML5) . '</td>';
        $html .= '<td class="right">' . number_format($sub, 2, ',', ' ') . ' ' . htmlspecialchars($currency, ENT_QUOTES | ENT_HTML5) . '</td>';
        $html .= '</tr>';
        $i++;
    }
    $html .= '</tbody><tfoot>';
    $html .= '<tr><td colspan="4" class="right">Spolu</td><td class="right total">' . number_format($amount, 2, ',', ' ') . ' ' . htmlspecialchars($currency, ENT_QUOTES | ENT_HTML5) . '</td></tr>';
    if ($vat_amount > 0) {
        $html .= '<tr><td colspan="4" class="right">DPH</td><td class="right">' . number_format($vat_amount, 2, ',', ' ') . ' ' . htmlspecialchars($currency, ENT_QUOTES | ENT_HTML5) . '</td></tr>';
    }
    $html .= '</tfoot></table>';

    $html .= '<p style="margin-top:12px;">Platba na účet: <strong>' . htmlspecialchars($bankIban, ENT_QUOTES | ENT_HTML5) . '</strong><br>Variabilný symbol: <strong>' . htmlspecialchars((string)$order['variabilny_symbol'], ENT_QUOTES | ENT_HTML5) . '</strong></p>';

    $html .= '<footer style="margin-top:18px; font-size:0.85rem; color:var(--muted);">Ďakujeme za váš nákup.</footer>';
    $html .= '</div></body></html>';

    // Uložíme HTML soubor
    $safeInvoiceFileName = preg_replace('/[^\w\-\.]/', '_', $invoiceNumber);
    $htmlPath = $generatedDir . '/' . $safeInvoiceFileName . '.html';
    $pdfPath = $generatedDir . '/' . $safeInvoiceFileName . '.pdf';

    file_put_contents($htmlPath, $html);

    // mPDF generace PDF
    $mpdfTmp = TMP_DIR . '/mpdf';
    if (!is_dir($mpdfTmp)) {
        @mkdir($mpdfTmp, 0775, true);
    }
    // instantiate mPDF
    if (!class_exists('\Mpdf\Mpdf')) {
        eshop_log('ERROR', 'mPDF není dostupný v libs — nelze generovat PDF.');
        // uložíme HTML a skončíme (HTML je přesto uložené)
        http_response_code(500);
        echo "mPDF není dostupný na serveru. HTML bylo uloženo: {$htmlPath}";
        exit;
    }

    $mpdf = new \Mpdf\Mpdf(['tempDir' => $mpdfTmp, 'mode' => 'utf-8']);
    $mpdf->WriteHTML($html);
    $mpdf->Output($pdfPath, \Mpdf\Output\Destination::FILE);

    // Uložíme záznam do invoices
    $stmt = $pdoLocal->prepare("INSERT INTO invoices (order_id, invoice_number, html_path, pdf_path, amount, vat_amount, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
    $stmt->execute([$orderId, $invoiceNumber, $htmlPath, $pdfPath, number_format($amount, 2, '.', ''), number_format($vat_amount, 2, '.', '')]);
    $invoiceId = (int)$pdoLocal->lastInsertId();

    eshop_log('INFO', "Vytvořena faktura id={$invoiceId} order_id={$orderId} pdf={$pdfPath}");

    // Vyčistit dočasný QR
    if (!empty($qrFile) && is_file($qrFile)) {
        @unlink($qrFile);
    }

    // Výsledek
    if (PHP_SAPI === 'cli') {
        echo "OK: invoice_id={$invoiceId} html={$htmlPath} pdf={$pdfPath}\n";
    } else {
        header('Content-Type: application/json');
        echo json_encode(['ok'=>true, 'invoice_id'=>$invoiceId, 'html'=>$htmlPath, 'pdf'=>$pdfPath], JSON_UNESCAPED_UNICODE);
    }
    exit(0);

} catch (Throwable $e) {
    eshop_log('ERROR', 'Chyba při generování faktury: ' . $e->getMessage());
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    } else {
        http_response_code(500);
        echo "Chyba při generování faktury.";
    }
    exit(1);
}
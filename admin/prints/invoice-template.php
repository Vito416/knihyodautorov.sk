<?php
// /admin/prints/invoice-template.php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../admin/bootstrap.php'; // ak voláš priamo z /admin/prints, adjust
require_once __DIR__ . '/../partials/notifications.php';

require_admin();

$orderId = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
if ($orderId <= 0) {
    admin_flash_set('error','Neplatné ID objednávky.');
    header('Location: /admin/orders.php'); exit;
}

// fetch order, items, user, settings
try {
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? LIMIT 1");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) { admin_flash_set('error','Objednávka neexistuje.'); header('Location: /admin/orders.php'); exit; }

    $stmt = $pdo->prepare("SELECT oi.*, b.nazov, b.obrazok FROM order_items oi LEFT JOIN books b ON oi.book_id=b.id WHERE oi.order_id = ?");
    $stmt->execute([$orderId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $user = null;
    if (!empty($order['user_id'])) {
        $stmt = $pdo->prepare("SELECT id, meno, email, telefon, adresa, datum_registracie FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$order['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // settings (company data)
    $sArr = $pdo->query("SELECT k,v FROM settings")->fetchAll(PDO::FETCH_ASSOC);
    $settings = [];
    foreach ($sArr as $r) $settings[$r['k']] = $r['v'];

    $companyName = $settings['company_name'] ?? 'Knihy od Autorov s.r.o.';
    $companyAddress = $settings['company_address'] ?? '';
    $companyIban = $settings['company_iban'] ?? '';
    $vatRate = floatval($settings['vat_rate'] ?? 20.0);
    $invoicePrefix = $settings['invoice_prefix'] ?? date('Y').'/';
} catch (Throwable $e) {
    admin_flash_set('error','Chyba DB: '.$e->getMessage());
    header('Location: /admin/orders.php'); exit;
}

// compute amounts
$subTotal = 0.0;
foreach ($items as $it) {
    $qty = (int)($it['quantity'] ?? 1);
    $unit = (float)($it['unit_price'] ?? 0.0);
    $subTotal += $qty * $unit;
}
$vat = round($subTotal * ($vatRate/100.0), 2);
$total = round($subTotal + $vat, 2);

// invoice number: YYYY/NNNN (naivny autoincrement)
try {
    // insert placeholder into invoices to get id
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("INSERT INTO invoices (order_id, invoice_number, html_path, amount) VALUES (?, ?, ?, ?)");
    // temporary invoice_number (we'll update after id)
    $tmpNum = 'TMP';
    $stmt->execute([$orderId, $tmpNum, '', $total]);
    $invId = (int)$pdo->lastInsertId();
    // generate invoice number by format prefix + zero-padded id
    $invoiceNumber = $invoicePrefix . str_pad((string)$invId, 5, '0', STR_PAD_LEFT);
    // prepare invoice folder
    $invDir = __DIR__ . '/../../eshop/invoices';
    if (!is_dir($invDir)) @mkdir($invDir, 0755, true);
    $htmlPathRel = '/eshop/invoices/invoice-' . $invId . '.html';
    $stmt = $pdo->prepare("UPDATE invoices SET invoice_number = ?, html_path = ? WHERE id = ?");
    $stmt->execute([$invoiceNumber, $htmlPathRel, $invId]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    admin_flash_set('error','Chyba pri vytváraní faktúry: '.$e->getMessage());
    header('Location: /admin/orders.php'); exit;
}

// QR: obsah môže byť link na prevod (iba príklad): IBAN + suma + VS (variabilný symbol = invId)
$qrPayload = "SPD*1.0*ACC:{$companyIban}*AM:".number_format($total,2,'.','')."*CC:EUR*VS:".(string)$invId;
$qrBase64 = '';
$qrTmp = sys_get_temp_dir() . '/inv_qr_'.$invId.'.png';
if (function_exists('QRcode::png')) {
    try {
        // phpqrcode usage: QRcode::png($text, $outfile, $level, $size, $margin)
        \QRcode::png($qrPayload, $qrTmp, 'M', 4, 2);
        if (file_exists($qrTmp)) $qrBase64 = base64_encode(file_get_contents($qrTmp));
    } catch (Throwable $e) { $qrBase64 = ''; }
}

// render HTML invoice
$html = '<!doctype html><html lang="sk"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Faktúra '.$invoiceNumber.'</title>';
$html .= '<style>
body{font-family:Arial,Helvetica,sans-serif;color:#222;background:#fff;padding:30px}
.invoice-box{max-width:800px;margin:0 auto;border:1px solid #eee;padding:28px;border-radius:8px;box-shadow:0 30px 60px rgba(0,0,0,0.06)}
.header{display:flex;justify-content:space-between;align-items:center}
.h1{font-size:1.6rem;font-weight:900;color:#3e2a12}
.company{font-weight:700;color:#6b5a49}
.table{width:100%;border-collapse:collapse;margin-top:18px}
.table th{background:#fbf7ec;padding:10px;border-bottom:1px solid #eee;text-align:left}
.table td{padding:10px;border-bottom:1px solid #f2efe7}
.right{text-align:right}
.small{font-size:.9rem;color:#6b5a49}
.footer-note{margin-top:18px;padding-top:12px;border-top:1px dashed #efe7d8;color:#6b5a49}
.stamp{float:right;padding:8px 12px;border-radius:6px;background:linear-gradient(180deg,#fff7e6,#fff);border:1px solid rgba(0,0,0,0.04);font-weight:800;color:#c08a2e}
</style></head><body>';
$html .= '<div class="invoice-box">';
$html .= '<div class="header"><div><div class="h1">Faktúra '.$invoiceNumber.'</div><div class="small">Dátum: '.date('Y-m-d').'</div></div>';
$html .= '<div class="company"><strong>'.htmlspecialchars($companyName).'</strong><div class="small">'.nl2br(htmlspecialchars($companyAddress)).'</div></div></div>';
$html .= '<div style="margin-top:18px"><strong>Odběratel:</strong><div class="small">'.htmlspecialchars($user['meno'] ?? 'Neznámy').'<br>'.htmlspecialchars($user['email'] ?? '') .'</div></div>';

$html .= '<table class="table" aria-label="Položky">';
$html .= '<thead><tr><th>#</th><th>Názov</th><th>Množ.</th><th class="right">Cena / ks</th><th class="right">Spolu</th></tr></thead><tbody>';
$idx = 1;
foreach ($items as $it) {
    $qty = (int)$it['quantity'];
    $unit = number_format((float)$it['unit_price'],2,',','.');
    $sum = number_format($qty * (float)$it['unit_price'],2,',','.');
    $html .= '<tr><td>'.$idx++.'</td><td>'.htmlspecialchars($it['nazov'] ?? '—').'</td><td>'.$qty.'</td><td class="right">'.$unit.' €</td><td class="right">'.$sum.' €</td></tr>';
}
$html .= '</tbody></table>';

$html .= '<div style="margin-top:14px;display:flex;justify-content:flex-end;gap:14px;align-items:center">';
$html .= '<div style="text-align:right"><div class="small">Medzisúčet</div><div><strong>'.number_format($subTotal,2,',','.').' €</strong></div></div>';
$html .= '<div style="text-align:right"><div class="small">DPH ('.$vatRate.'%)</div><div><strong>'.number_format($vat,2,',','.').' €</strong></div></div>';
$html .= '<div style="text-align:right"><div class="small">Spolu</div><div class="stamp">'.number_format($total,2,',','.').' €</div></div>';
$html .= '</div>';

if ($qrBase64) {
    $html .= '<div class="footer-note"><div style="display:flex;gap:18px;align-items:center"><div><img src="data:image/png;base64,'.$qrBase64.'" alt="QR" style="width:120px;border-radius:6px;border:1px solid #eee"></div><div class="small">Údaje pre platbu: IBAN '.htmlspecialchars($companyIban).' Variabilný symbol: '.htmlspecialchars((string)$invId).'</div></div></div>';
} else {
    $html .= '<div class="footer-note small">QR kód pre platbu nebol vygenerovaný (chýba phpqrcode alebo zápis do tmp).</div>';
}

$html .= '</div></body></html>';

// save HTML to file
$invoiceHtmlPathAbs = __DIR__ . '/../../eshop/invoices/invoice-' . $invId . '.html';
@file_put_contents($invoiceHtmlPathAbs, $html);

// If pdf requested and mPDF available -> render PDF
if (isset($_GET['pdf']) && (class_exists('\Mpdf\Mpdf') || class_exists('Mpdf'))) {
    try {
        $mpdfTmp = sys_get_temp_dir() . '/mpdf_tmp_'.$invId;
        if (!is_dir($mpdfTmp)) @mkdir($mpdfTmp, 0755, true);
        $mpdf = new \Mpdf\Mpdf(['tempDir'=>$mpdfTmp, 'mode'=>'utf-8']);
        $mpdf->SetTitle('Faktúra '.$invoiceNumber);
        $mpdf->WriteHTML($html);
        $pdfPathRel = '/eshop/invoices/invoice-' . $invId . '.pdf';
        $pdfPathAbs = __DIR__ . '/../../eshop/invoices/invoice-' . $invId . '.pdf';
        $mpdf->Output($pdfPathAbs, \Mpdf\Output\Destination::FILE);
        // update invoices table with path (if needed)
        $pdo->prepare("UPDATE invoices SET html_path = ?, amount = ? WHERE id = ?")->execute([$htmlPathRel, $total, $invId]);
        // serve PDF for download
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="faktura-'.$invoiceNumber.'.pdf"');
        readfile($pdfPathAbs);
        exit;
    } catch (Throwable $e) {
        // failover: show HTML (and log)
        error_log('invoice pdf error: '.$e->getMessage());
    }
}

// Default: show HTML in browser
echo $html;
exit;
<?php
// /eshop/generate_invoice.php
declare(strict_types=1);
require_once __DIR__ . '/../db/config/config.php';
require_once __DIR__ . '/../admin/inc/functions.php'; // for h()

$order_id = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
if (!$order_id) { http_response_code(400); echo "order_id missing"; exit; }

$stmt = $pdo->prepare("SELECT o.*, u.meno AS uname, u.email AS uemail FROM orders o LEFT JOIN users u ON o.user_id=u.id WHERE o.id = ? LIMIT 1");
$stmt->execute([$order_id]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$order) { http_response_code(404); echo "Objednávka nenájdená"; exit; }

$itemsStmt = $pdo->prepare("SELECT oi.*, b.nazov, b.pdf_file FROM order_items oi JOIN books b ON oi.book_id=b.id WHERE oi.order_id = ?");
$itemsStmt->execute([$order_id]);
$items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

$invNumber = 'INV-' . date('Ymd') . '-' . str_pad((string)$order_id,4,'0',STR_PAD_LEFT);
$sum = 0.0;
foreach($items as $it) { $sum += (float)$it['unit_price'] * (int)$it['quantity']; }

$publicInvoicePath = '/eshop/invoices/' . $invNumber . '.html';
$fsPath = __DIR__ . '/invoices/' . $invNumber . '.html';

// QR data — napr. môže obsahovať odkaz na faktúru + údaje pre platbu
$qrPayload = "Faktura: {$invNumber}\nSuma: " . number_format($sum,2,'.','') . " EUR\nObjednávka: {$order_id}";
$qrEncoded = rawurlencode($qrPayload);

// Google Chart QR (fallback)
$qrUrl = "https://chart.googleapis.com/chart?cht=qr&chs=300x300&chl={$qrEncoded}&choe=UTF-8";

// HTML invoice content
$html = '<!doctype html><html lang="sk"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
$html .= '<title>' . h($invNumber) . '</title>';
$html .= '<style>body{font-family:Inter, Arial;color:#222;background:#fff} .inv{max-width:900px;margin:20px auto;padding:26px;border-radius:10px;box-shadow:0 20px 60px rgba(0,0,0,0.12)}';
$html .= '.inv-header{display:flex;justify-content:space-between;align-items:center} .inv-table{width:100%;border-collapse:collapse;margin-top:18px} .inv-table th, .inv-table td{padding:8px;border-bottom:1px solid #eee;text-align:left} .total{font-weight:900;font-size:1.1rem;margin-top:12px}</style>';
$html .= '</head><body><div class="inv"><div class="inv-header"><div><h1>Faktúra ' . h($invNumber) . '</h1><p>Objednávka: ' . h($order_id) . '</p></div>';
$html .= '<div><img src="' . $qrUrl . '" alt="QR faktúry" style="width:140px;height:140px;border-radius:6px"></div></div>';
$html .= '<p>Odberateľ: ' . h($order['uname']) . ' (' . h($order['uemail']) . ')</p>';
$html .= '<table class="inv-table"><thead><tr><th>Produkt</th><th>Množ</th><th>Jedn. cena</th><th>Spolu</th></tr></thead><tbody>';
foreach ($items as $it) {
    $lineTotal = (float)$it['unit_price'] * (int)$it['quantity'];
    $html .= '<tr><td>' . h($it['nazov']) . '</td><td>' . (int)$it['quantity'] . '</td><td>' . number_format($it['unit_price'],2,'.','') . ' €</td><td>' . number_format($lineTotal,2,'.','') . ' €</td></tr>';
}
$html .= '</tbody></table>';
$html .= '<div class="total">SPOLU: ' . number_format($sum,2,'.','') . ' EUR</div>';
$html .= '<p style="margin-top:18px">Platba: bankový prevod. Údaje o platbe budú zaslané e-mailom. Po pripísaní platby označíme objednávku ako zaplatenú a sprístupníme stiahnutie PDF.</p>';
$html .= '</div></body></html>';

// uloz do filesystému
if (!is_dir(__DIR__ . '/invoices')) @mkdir(__DIR__ . '/invoices', 0755, true);
file_put_contents($fsPath, $html);

// vlozenie/aktualizacia do invoices tabuľky
$stmtInv = $pdo->prepare("INSERT INTO invoices (order_id, invoice_number, html_path, amount) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE html_path = VALUES(html_path), amount = VALUES(amount)");
$stmtInv->execute([$order_id, $invNumber, $publicInvoicePath, number_format($sum,2,'.','')]);

echo json_encode(['ok'=>true, 'invoice'=>$publicInvoicePath, 'number'=>$invNumber, 'qr'=>$qrUrl], JSON_UNESCAPED_UNICODE);
exit;

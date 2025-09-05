<?php
// admin/modules/billing/invoice_pdf.php
require __DIR__ . '/../../inc/bootstrap.php';
require __DIR__ . '/../../../../libs/PDFHelper.php';
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: invoices.php'); exit; }
$stmt = $db->prepare('SELECT i.*, o.user_id, o.currency FROM invoices i LEFT JOIN orders o ON o.id=i.order_id WHERE i.id = ? LIMIT 1');
$stmt->execute([$id]); $inv = $stmt->fetch();
if (!$inv) { http_response_code(404); echo 'Faktúra nenájdená'; exit; }
$items = $db->prepare('SELECT ii.* FROM invoice_items ii WHERE ii.invoice_id = ? ORDER BY ii.line_no');
$items->execute([$id]); $items = $items->fetchAll();
// build HTML
$html = '<html><head><meta charset="utf-8"><style>body{font-family:Arial,Helvetica,sans-serif}table{width:100%;border-collapse:collapse}td,th{border:1px solid #ccc;padding:6px}</style></head><body>';
$html .= '<h1>Faktúra '.htmlspecialchars($inv['invoice_number']).'</h1>';
$html .= '<p>Objednávka: '.htmlspecialchars($inv['order_id']).' | Vystavená: '.htmlspecialchars($inv['issue_date']).'</p>';
$html .= '<table><tr><th>Popis</th><th>Množstvo</th><th>Jedn. cena</th><th>Riadok</th></tr>';
foreach($items as $it){
    $html .= '<tr><td>'.htmlspecialchars($it['description']).'</td><td>'.htmlspecialchars($it['quantity']).'</td><td>'.number_format($it['unit_price'],2,',',' ').' '.$inv['currency'].'</td><td>'.number_format($it['line_total'] ?? ($it['unit_price']*$it['quantity']),2,',',' ').' '.$inv['currency'].'</td></tr>';
}
$html .= '</table>';
$html .= '<p>Celkom: '.number_format($inv['total'],2,',',' ').' '.$inv['currency'].'</p>';
$html .= '</body></html>';
PDFHelper::outputPdfFromHtml($html, 'faktura_'.$inv['invoice_number'].'.pdf', true);
exit;
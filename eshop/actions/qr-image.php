<?php
// /eshop/actions/qr-image.php
require __DIR__ . '/../_init.php';
$order = (int)($_GET['order'] ?? 0);
if (!$order) { http_response_code(400); exit; }

// find invoice/order
$inv = $pdo->prepare("SELECT i.id AS invoice_id, i.invoice_number, o.total_price, o.id AS order_id FROM invoices i JOIN orders o ON i.order_id = o.id WHERE o.id = ? LIMIT 1");
$inv->execute([$order]);
$row = $inv->fetch(PDO::FETCH_ASSOC);
if (!$row) { http_response_code(404); exit; }

// build SPAY payload (simple): SPD*1.0*ACC:IBAN*AM:amount*CC:EUR*X-VS:vs
$iban = eshop_settings($pdo, 'company_iban') ?? '';
$amount = number_format((float)$row['total_price'], 2, '.', '');
$vs = str_pad((string)$row['order_id'], 10, '0', STR_PAD_LEFT);
$payload = "SPD*1.0*ACC:{$iban}*AM:{$amount}*CC:EUR*X-VS:{$vs}";
// generate QR via PHP QR Code if available
if (class_exists('QRcode')) {
    // send PNG
    header('Content-Type: image/png');
    QRcode::png($payload, false, 'L', 6, 2);
    exit;
} else {
    // fallback: return 1x1 gif
    header('Content-Type: image/gif');
    echo base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==');
    exit;
}
<?php
// improved GoPay webhook handler - uses GoPayAdvanced->verifyWebhook
require __DIR__ . '/inc/bootstrap.php';
$headers = [];
foreach ($_SERVER as $k=>$v) if (substr($k,0,5)==='HTTP_') $headers[str_replace('HTTP_','', $k)] = $v;
$payload = json_decode(file_get_contents('php://input'), true);
require_once __DIR__ . '/../../libs/GoPayAdvanced.php';
$g = new GoPayAdvanced($cfg['gopay']);
$ok = $g->verifyWebhook($payload ?: [], $headers);
if (!$ok) { http_response_code(403); echo 'Invalid signature'; exit; }
$order_id = $payload['order_id'] ?? null;
$status = $payload['status'] ?? null;
if ($order_id && $status === 'PAID') {
    $db->prepare('UPDATE orders SET status=?, updated_at=NOW() WHERE id=?')->execute(['paid',$order_id]);
    $db->prepare('INSERT INTO payments (order_id, gateway, transaction_id, status, amount, currency, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())')
       ->execute([$order_id, 'gopay', $payload['transaction_id'] ?? '', 'paid', $payload['amount'] ?? 0, $payload['currency'] ?? 'EUR']);
    echo 'OK';
    exit;
}
http_response_code(200); echo 'IGNORED';
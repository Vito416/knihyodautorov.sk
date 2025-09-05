<?php
// simplified endpoint for GoPay notifications
require __DIR__ . '/inc/bootstrap.php';
// read POST payload
$payload = file_get_contents('php://input');
$data = json_decode($payload, true);
if (!$data){ http_response_code(400); echo 'Invalid payload'; exit; }
// verify signature / authenticity in production
$order_id = $data['order_id'] ?? null;
$status = $data['status'] ?? null;
if (!$order_id) { http_response_code(400); exit; }
if ($status === 'PAID'){
    $stmt = $db->prepare('UPDATE orders SET status = ? WHERE id = ?');
    $stmt->execute(['paid', $order_id]);
    // create payment record
    $stmt = $db->prepare('INSERT INTO payments (order_id, gateway, transaction_id, status, amount, currency, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())');
    $stmt->execute([$order_id, 'gopay', $data['transaction_id'] ?? '', 'paid', $data['amount'] ?? 0, $data['currency'] ?? 'EUR']);
}
http_response_code(200);
echo 'OK';
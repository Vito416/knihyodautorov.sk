<?php
// create a GoPay payment session and redirect user
require __DIR__ . '/inc/bootstrap.php';
$cart = $_SESSION['cart'] ?? [];
if (empty($cart)) { header('Location: cart.php'); exit; }
// compute total
$in = implode(',', array_map('intval', array_keys($cart)));
$stmt = $db->query("SELECT id, price FROM books WHERE id IN ($in)");
$sum = 0; $rows = $stmt->fetchAll();
foreach($rows as $r) $sum += $r['price'] * $cart[$r['id']];
$user_id = $_SESSION['user_id'] ?? null;
// create order in DB
$stmt = $db->prepare('INSERT INTO orders (user_id, status, currency, subtotal, total, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
$stmt->execute([$user_id, 'pending', 'EUR', $sum, $sum]);
$order_id = $db->lastInsertId();
foreach($rows as $r){
    $stmt = $db->prepare('INSERT INTO order_items (order_id, book_id, title_snapshot, unit_price, quantity, tax_rate, currency) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$order_id, $r['id'], 'produkt '.$r['id'], $r['price'], $cart[$r['id']], 0.20, 'EUR']);
}
// Use GoPayAdvanced to create session (or stub)
require_once __DIR__ . '/../../libs/GoPayAdvanced.php';
$g = new GoPayAdvanced($cfg['gopay']);
$res = $g->createPayment(['order_id'=>$order_id,'amount'=>$sum,'currency'=>'EUR','return_url'=>$cfg['gopay']['return_url']]);
header('Location: '.$res['redirect_url']); exit;
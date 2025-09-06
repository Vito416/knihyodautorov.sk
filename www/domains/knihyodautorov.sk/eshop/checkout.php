<?php
require __DIR__ . '/inc/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: cart.php'); exit; }
if (!CSRF::validate($_POST['csrf_token'] ?? '')) { http_response_code(400); echo 'CSRF token invalid'; exit; }
$cart = $_SESSION['cart'] ?? [];
if (empty($cart)) { header('Location: catalog.php'); exit; }
// compute totals
$in = implode(',', array_map('intval', array_keys($cart)));
$stmt = $db->query("SELECT id, title, price FROM books WHERE id IN ($in)");
$rows = $stmt->fetchAll(); $sum = 0; foreach($rows as $r) $sum += $r['price'] * $cart[$r['id']];
// create order (status pending)
$stmt = $db->prepare('INSERT INTO orders (user_id, status, currency, subtotal, total, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
$user_id = $_SESSION['user_id'] ?? null;
$stmt->execute([$user_id, 'pending', 'EUR', $sum, $sum]);
$order_id = $db->lastInsertId();
foreach($rows as $r){
    $stmt = $db->prepare('INSERT INTO order_items (order_id, book_id, title_snapshot, unit_price, quantity, tax_rate, currency) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$order_id, $r['id'], $r['title'], $r['price'], $cart[$r['id']], 0.20, 'EUR']);
}
// Prepare GoPay redirect (simplified)
$gopay = $cfg['gopay'];
// In production you would call GoPay API to create payment session. Here we redirect to a placeholder.
header('Location: '.$gopay['return_url'].'?order_id='.$order_id.'&amount='.$sum);
exit;
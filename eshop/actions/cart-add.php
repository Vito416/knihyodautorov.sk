<?php
// /eshop/actions/cart-add.php
require __DIR__ . '/../_init.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

$csrf = $_POST['csrf'] ?? '';
if (!eshop_verify_csrf($csrf)) { http_response_code(403); echo "CSRF"; exit; }

$book_id = (int)($_POST['book_id'] ?? 0);
$qty = max(1, (int)($_POST['qty'] ?? 1));

if ($book_id <= 0) { header('Location: /eshop/index.php'); exit; }

// verify book exists
$stmt = $pdo->prepare("SELECT id FROM books WHERE id = ? AND is_active = 1 LIMIT 1");
$stmt->execute([$book_id]);
if (!$stmt->fetchColumn()) {
    header('Location: /eshop/index.php'); exit;
}

$cart = eshop_get_cart();
$cart[$book_id] = ($cart[$book_id] ?? 0) + $qty;
eshop_set_cart($cart);

if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    echo json_encode(['ok'=>true,'count'=>eshop_cart_count()]);
    exit;
}
header('Location: /eshop/cart.php');
exit;
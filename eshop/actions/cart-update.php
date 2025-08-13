<?php
// /eshop/actions/cart-update.php
require __DIR__ . '/../_init.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
$csrf = $_POST['csrf'] ?? '';
if (!eshop_verify_csrf($csrf)) { http_response_code(403); echo "CSRF"; exit; }

$cart = eshop_get_cart();
if (isset($_POST['remove']) && is_numeric($_POST['remove'])) {
    $rid = (int)$_POST['remove'];
    unset($cart[$rid]);
} elseif (!empty($_POST['qty']) && is_array($_POST['qty'])) {
    foreach ($_POST['qty'] as $id => $q) {
        $id = (int)$id;
        $q = max(0, (int)$q);
        if ($q <= 0) unset($cart[$id]); else $cart[$id] = $q;
    }
}
eshop_set_cart($cart);
header('Location: /eshop/cart.php');
exit;
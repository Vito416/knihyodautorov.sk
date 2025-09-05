<?php
require __DIR__ . '/inc/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST'){
    if (!CSRF::validate($_POST['csrf_token'] ?? '')) { http_response_code(400); echo 'CSRF token invalid'; exit; }
    $book_id = (int)($_POST['book_id'] ?? 0);
    if ($book_id<=0) { header('Location: catalog.php'); exit; }
    // simple cart in session
    if (!isset($_SESSION['cart'])) $_SESSION['cart'] = [];
    $_SESSION['cart'][$book_id] = ($_SESSION['cart'][$book_id] ?? 0) + 1;
    header('Location: cart.php'); exit;
}
$cart = $_SESSION['cart'] ?? [];
$items = [];
$sum = 0.0;
if ($cart){
    $in = implode(',', array_map('intval', array_keys($cart)));
    $stmt = $db->query("SELECT id, title, price FROM books WHERE id IN ($in)");
    $rows = $stmt->fetchAll();
    foreach($rows as $r){ $q = $cart[$r['id']]; $items[] = ['book'=>$r,'qty'=>$q]; $sum += $r['price']*$q; }
}
?><!doctype html>
<html lang="sk"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Košík</title>
<link rel="stylesheet" href="assets/css/base.css">
</head><body>
<?php include __DIR__.'/templates/layout-header.php'; ?>
<main>
<h1>Váš košík</h1>
<?php if (empty($items)): ?> <p>Košík je prázdny.</p>
<?php else: ?>
<table>
<tr><th>Kniha</th><th>Množstvo</th><th>Cena</th></tr>
<?php foreach($items as $it): ?>
<tr>
<td><?=e($it['book']['title'])?></td>
<td><?=e($it['qty'])?></td>
<td><?=number_format($it['book']['price']*$it['qty'],2,',',' ')?> €</td>
</tr>
<?php endforeach; ?>
</table>
<p>Celkom: <?=number_format($sum,2,',',' ')?> €</p>
<form method="post" action="checkout.php">
  <input type="hidden" name="csrf_token" value="<?=e(CSRF::token())?>">
  <button type="submit">Prejsť k platbe</button>
</form>
<?php endif; ?>
</main>
<?php include __DIR__.'/templates/layout-footer.php'; ?>
</body></html>
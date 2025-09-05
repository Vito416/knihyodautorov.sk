<?php
require __DIR__ . '/inc/bootstrap.php';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$stmt = $db->prepare('SELECT b.*, a.meno AS author FROM books b LEFT JOIN authors a ON a.id=b.author_id WHERE b.id = ? LIMIT 1');
$stmt->execute([$id]);
$b = $stmt->fetch();
if (!$b) { http_response_code(404); echo 'Kniha nenájdená'; exit; }
?><!doctype html>
<html lang="sk"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=e($b['title'])?></title>
<link rel="stylesheet" href="assets/css/base.css">
</head><body>
<?php include __DIR__.'/templates/layout-header.php'; ?>
<main>
<h1><?=e($b['title'])?></h1>
<p>Autor: <?=e($b['author'])?></p>
<p><?=nl2br(e($b['description']))?></p>
<p>Cena: <?=number_format($b['price'],2,',',' ')?> €</p>
<form method="post" action="cart.php">
  <input type="hidden" name="book_id" value="<?=e($b['id'])?>">
  <input type="hidden" name="csrf_token" value="<?=e(CSRF::token())?>">
  <button type="submit">Pridať do košíka</button>
</form>
</main>
<?php include __DIR__.'/templates/layout-footer.php'; ?>
</body></html>
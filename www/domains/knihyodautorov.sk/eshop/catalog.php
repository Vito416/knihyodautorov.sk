<?php
require __DIR__ . '/inc/bootstrap.php';
// fetch books
$stmt = $db->query('SELECT b.id, b.title, b.slug, b.price, a.meno AS author FROM books b LEFT JOIN authors a ON a.id=b.author_id WHERE b.is_active=1');
$books = $stmt->fetchAll();
?><!doctype html>
<html lang="sk"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Knihy - katalog</title>
<link rel="stylesheet" href="assets/css/base.css">
</head><body>
<?php include __DIR__.'/templates/layout-header.php'; ?>
<main>
<h1>Katalog kníh</h1>
<div class="grid">
<?php foreach($books as $b): ?>
  <article class="card">
    <h2><?=e($b['title'])?></h2>
    <p>Autor: <?=e($b['author'])?></p>
    <p>Cena: <?=number_format($b['price'],2,',',' ')?> €</p>
    <p><a href="book.php?id=<?=urlencode($b['id'])?>">Detail</a></p>
  </article>
<?php endforeach; ?>
</div>
</main>
<?php include __DIR__.'/templates/layout-footer.php'; ?>
</body></html>
<?php
require __DIR__ . '/inc/bootstrap.php';
?><!doctype html><html><head><meta charset="utf-8"><title>Admin</title><link rel="stylesheet" href="/eshop/assets/css/base.css"></head><body>
<?php include __DIR__.'/templates/admin-header.php'; ?>
<main>
<h1>Administrácia</h1>
<ul>
<li><a href="modules/users/list.php">Užívatelia</a></li>
<li><a href="modules/catalog/books.php">Knihy</a></li>
<li><a href="modules/orders/orders.php">Objednávky</a></li>
</ul>
</main>
</body></html>
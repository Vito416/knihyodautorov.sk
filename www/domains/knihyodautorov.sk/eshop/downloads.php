<?php
require __DIR__ . '/inc/bootstrap.php';
Auth::requireLogin();
$user_id = $_SESSION['user_id'];
// list downloads available (order_items joined with order status=paid)
$stmt = $db->prepare('SELECT oi.*, b.title FROM order_items oi JOIN orders o ON o.id=oi.order_id JOIN books b ON b.id=oi.book_id WHERE o.user_id = ? AND o.status = ?');
$stmt->execute([$user_id,'paid']);
$rows = $stmt->fetchAll();
?><!doctype html>
<html lang="sk"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Moje súbory</title>
<link rel="stylesheet" href="assets/css/base.css">
</head><body>
<?php include __DIR__.'/templates/layout-header.php'; ?>
<main>
<h1>Vaše zakúpené knihy</h1>
<?php if (!$rows) echo '<p>Žiadne súbory na stiahnutie.</p>'; else: ?>
<ul>
<?php foreach($rows as $r): ?>
<li><?=e($r['title'])?> — <a href="download_file.php?order_item_id=<?=e($r['id'])?>">Stiahnuť</a></li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
</main>
<?php include __DIR__.'/templates/layout-footer.php'; ?>
</body></html>
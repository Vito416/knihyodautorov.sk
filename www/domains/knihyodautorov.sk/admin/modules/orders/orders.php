<?php
require __DIR__ . '/../../inc/bootstrap.php';
$stmt = $db->query("SELECT o.id, o.status, o.total, o.created_at, COALESCE(u.email,'host') AS buyer FROM orders o LEFT JOIN pouzivatelia u ON u.id=o.user_id ORDER BY o.created_at DESC LIMIT 200");
$rows = $stmt->fetchAll();
?><!doctype html><html lang="sk"><head><meta charset="utf-8"><title>Objednávky</title><link rel="stylesheet" href="/eshop/assets/css/base.css"></head><body>
<?php include __DIR__ . '/../templates/admin-header.php'; ?>
<main>
  <h1>Objednávky</h1>
  <table border="1" cellpadding="6">
    <tr><th>ID</th><th>Stav</th><th>Celkom</th><th>Vytvorená</th><th>Zákazník</th><th>Akcie</th></tr>
    <?php foreach($rows as $r): ?>
      <tr>
        <td><?=e($r['id'])?></td>
        <td><?=e($r['status'])?></td>
        <td><?=number_format($r['total'],2,',',' ')?> <?=e($r['currency'] ?? 'EUR')?></td>
        <td><?=e($r['created_at'])?></td>
        <td><?=e($r['buyer'])?></td>
        <td><a href="order_detail.php?id=<?=urlencode($r['id'])?>">Detail</a></td>
      </tr>
    <?php endforeach; ?>
  </table>
</main>
</body></html>
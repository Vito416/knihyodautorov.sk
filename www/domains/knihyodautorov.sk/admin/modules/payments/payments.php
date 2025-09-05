<?php
require __DIR__ . '/../../inc/bootstrap.php';
$rows = $db->query('SELECT p.*, o.id AS order_nr FROM payments p LEFT JOIN orders o ON o.id=p.order_id ORDER BY p.created_at DESC LIMIT 200')->fetchAll();
?><!doctype html><html lang="sk"><head><meta charset="utf-8"><title>Platby</title></head><body>
<?php include __DIR__ . '/../templates/admin-header.php'; ?>
<main>
  <h1>Platby</h1>
  <table border="1"><tr><th>ID</th><th>Order</th><th>Gateway</th><th>Transaction</th><th>Amount</th><th>Status</th><th>At</th></tr>
  <?php foreach($rows as $r): ?>
    <tr>
      <td><?=e($r['id'])?></td>
      <td><?=e($r['order_id'])?></td>
      <td><?=e($r['gateway'])?></td>
      <td><?=e($r['transaction_id'])?></td>
      <td><?=number_format($r['amount'],2,',',' ')?> <?=e($r['currency'])?></td>
      <td><?=e($r['status'])?></td>
      <td><?=e($r['created_at'])?></td>
    </tr>
  <?php endforeach; ?>
  </table>
</main>
</body></html>
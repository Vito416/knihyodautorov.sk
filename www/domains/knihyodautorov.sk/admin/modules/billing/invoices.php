<?php
require __DIR__ . '/../../inc/bootstrap.php';
$rows = $db->query('SELECT i.*, o.id AS order_nr FROM invoices i LEFT JOIN orders o ON o.id=i.order_id ORDER BY i.created_at DESC LIMIT 200')->fetchAll();
?><!doctype html><html lang="sk"><head><meta charset="utf-8"><title>Faktúry</title></head><body>
<?php include __DIR__ . '/../templates/admin-header.php'; ?>
<main>
  <h1>Faktúry</h1>
  <table border="1"><tr><th>ID</th><th>Číslo</th><th>Order</th><th>Issue</th><th>Total</th></tr>
  <?php foreach($rows as $r): ?>
    <tr>
      <td><?=e($r['id'])?></td>
      <td><?=e($r['invoice_number'])?></td>
      <td><?=e($r['order_id'])?></td>
      <td><?=e($r['issue_date'])?></td>
      <td><?=number_format($r['total'],2,',',' ')?> <?=e($r['currency'])?></td>
    </tr>
  <?php endforeach; ?>
  </table>
</main>
</body></html>
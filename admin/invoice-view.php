<?php
// /admin/invoice-view.php (view used by order-action view)
if (!$order) { echo 'Objednávka nenájdená'; exit; }
?><!doctype html><html lang="sk"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Objednávka #<?=esc($order['id'])?></title>
<link rel="stylesheet" href="/admin/css/admin.css"></head>
<body><?php include __DIR__ . '/header.php'; ?>
<main class="admin-main">
  <h1>Objednávka #<?=esc($order['id'])?></h1>
  <p>Užívateľ: <?=esc($order['meno'] ?? '-')?> (<?=esc($order['email'] ?? '-')?>)</p>
  <p>Status: <strong><?=esc($order['status'])?></strong></p>
  <h2>Položky</h2>
  <table class="admin-table"><thead><tr><th>Produkt</th><th>Množstvo</th><th>Cena</th></tr></thead><tbody>
    <?php foreach($items as $it): ?>
      <tr>
        <td><?=esc($it['nazov'])?></td>
        <td><?=esc($it['quantity'])?></td>
        <td><?=esc(number_format($it['unit_price'],2,'.',''))?> €</td>
      </tr>
    <?php endforeach; ?>
  </tbody></table>

  <div style="margin-top:12px;">
    <?php if($order['status'] !== 'paid'): ?>
      <form method="post" action="/admin/order-action.php">
        <input type="hidden" name="csrf" value="<?=esc(csrf_get_token())?>">
        <input type="hidden" name="id" value="<?=esc($order['id'])?>">
        <input type="hidden" name="act" value="mark_paid">
        <button class="btn btn-primary" type="submit">Označiť zaplatené</button>
      </form>
    <?php else: ?>
      <div class="alert alert-success">Objednávka je zaplatená.</div>
    <?php endif; ?>
  </div>
</main>
<?php include __DIR__ . '/footer.php'; ?></body></html>

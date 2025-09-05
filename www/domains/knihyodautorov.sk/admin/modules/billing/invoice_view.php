<?php
// admin/modules/billing/invoice_view.php
require __DIR__ . '/../../inc/bootstrap.php';
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: invoices.php'); exit; }
$stmt = $db->prepare('SELECT i.*, o.user_id, o.currency FROM invoices i LEFT JOIN orders o ON o.id=i.order_id WHERE i.id = ? LIMIT 1');
$stmt->execute([$id]); $inv = $stmt->fetch();
if (!$inv) { http_response_code(404); echo 'Faktúra nenájdená'; exit; }
$items = $db->prepare('SELECT ii.* FROM invoice_items ii WHERE ii.invoice_id = ? ORDER BY ii.line_no');
$items->execute([$id]); $items = $items->fetchAll();
// simple HTML invoice
$company = ['name'=>'Knihy od autorov','address'=>'Ulica 1, 01001 Mesto','ico'=>'12345678','dic'=>'SK1234567890'];
$customer = [$inv['bill_full_name'] ?? '', $inv['bill_company'] ?? '', $inv['bill_street'] ?? '', $inv['bill_city'] ?? ''];
$qr_img = '';
if (!empty($inv['qr_data'])) {
    // Use QuickChart to render QR as fallback
    $qr_payload = rawurlencode($inv['qr_data']);
    $qr_img = "https://quickchart.io/qr?text={$qr_payload}&size=200";
}
?>
<!doctype html><html lang="sk"><head><meta charset="utf-8"><title>Faktúra <?=e($inv['invoice_number'])?></title></head><body>
<?php include __DIR__ . '/../templates/admin-header.php'; ?>
<main>
  <h1>Faktúra <?=e($inv['invoice_number'])?></h1>
  <p>Objednávka: <?=e($inv['order_id'])?> | Vystavená: <?=e($inv['issue_date'])?> | Splatná: <?=e($inv['due_date'])?></p>
  <section>
    <h2>Dodávateľ</h2>
    <p><?=e($company['name'])?><br><?=e($company['address'])?><br>IČO: <?=e($company['ico'])?>, DIČ: <?=e($company['dic'])?></p>
  </section>
  <section>
    <h2>Odberateľ</h2>
    <p><?=e(implode(', ', array_filter($customer)))?></p>
  </section>
  <section>
    <h2>Položky</h2>
    <table border="1" cellpadding="6"><tr><th>Popis</th><th>Množstvo</th><th>Cena</th><th>Riadok</th></tr>
    <?php foreach($items as $it): ?>
      <tr>
        <td><?=e($it['description'])?></td>
        <td><?=e($it['quantity'])?></td>
        <td><?=number_format($it['unit_price'],2,',',' ')?> <?=e($inv['currency'])?></td>
        <td><?=number_format($it['line_total'] ?? ($it['unit_price']*$it['quantity']),2,',',' ')?> <?=e($inv['currency'])?></td>
      </tr>
    <?php endforeach; ?>
    </table>
    <p>Subtotal: <?=number_format($inv['subtotal'],2,',',' ')?> <?=e($inv['currency'])?></p>
    <p>DPH: <?=number_format($inv['tax_total'],2,',',' ')?> <?=e($inv['currency'])?></p>
    <p><strong>Celkom: <?=number_format($inv['total'],2,',',' ')?> <?=e($inv['currency'])?></strong></p>
  </section>
  <?php if ($qr_img): ?><p><img src="<?=e($qr_img)?>" alt="QR"></p><?php endif; ?>
  <p>
    <a href="invoice_pdf.php?id=<?=e($id)?>" target="_blank">Stiahnuť PDF</a>
  </p>
</main>
</body></html>
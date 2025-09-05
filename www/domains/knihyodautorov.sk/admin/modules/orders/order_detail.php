<?php
require __DIR__ . '/../../inc/bootstrap.php';
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: orders.php'); exit; }
$stmt = $db->prepare('SELECT o.*, u.email FROM orders o LEFT JOIN pouzivatelia u ON u.id=o.user_id WHERE o.id=? LIMIT 1');
$stmt->execute([$id]); $order = $stmt->fetch();
if (!$order) { http_response_code(404); echo 'Objednávka nenájdená'; exit; }
$items = $db->prepare('SELECT oi.*, b.title FROM order_items oi JOIN books b ON b.id=oi.book_id WHERE oi.order_id=?');
$items->execute([$id]); $items = $items->fetchAll();
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::validate($_POST['csrf_token'] ?? '')) { $err = 'CSRF token neplatný'; }
    else {
        if (isset($_POST['set_status'])) {
            $new = $_POST['status'] ?? $order['status'];
            $db->prepare('UPDATE orders SET status=?, updated_at=NOW() WHERE id=?')->execute([$new,$id]);
            header('Location: order_detail.php?id='.$id); exit;
        } elseif (isset($_POST['generate_invoice'])) {
            // very basic invoice creation (invoice_number auto)
            $invnum = 'F' . time() . rand(10,99);
            $stmt = $db->prepare('INSERT INTO invoices (order_id, invoice_number, issue_date, due_date, subtotal, tax_total, total, currency, created_at) VALUES (?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 14 DAY), ?, ?, ?, ?, NOW())');
            $stmt->execute([$id, $invnum, $order['subtotal'], $order['tax_total'] ?? 0, $order['total'], $order['currency']]);
            header('Location: order_detail.php?id='.$id); exit;
        } elseif (isset($_POST['create_tokens'])) {
            // create download tokens for each order_item
            foreach($items as $it){
                $token = bin2hex(random_bytes(16));
                $db->prepare('INSERT INTO order_item_downloads (order_id, book_id, asset_id, download_token, max_uses, used, expires_at, created_at) VALUES (?, ?, ?, ?, ?, 0, DATE_ADD(NOW(), INTERVAL 30 DAY), NOW())')
                   ->execute([$id, $it['book_id'], null, $token, 5]);
            }
            header('Location: order_detail.php?id='.$id); exit;
        }
    }
}
?><!doctype html><html lang="sk"><head><meta charset="utf-8"><title>Objednávka #<?=e($id)?></title></head><body>
<?php include __DIR__ . '/../templates/admin-header.php'; ?>
<main>
  <h1>Objednávka #<?=e($id)?></h1>
  <p>Stav: <?=e($order['status'])?> | Celkom: <?=number_format($order['total'],2,',',' ')?> <?=e($order['currency'])?></p>
  <h2>Položky</h2>
  <ul><?php foreach($items as $it): ?><li><?=e($it['title_snapshot'])?> — <?=e($it['quantity'])?> × <?=number_format($it['unit_price'],2,',',' ')?> </li><?php endforeach;?></ul>

  <h3>Akcie</h3>
  <?php if ($err) echo '<p class="error">'.e($err).'</p>'; ?>
  <form method="post">
    <label>Stav:
      <select name="status">
        <option value="pending" <?=($order['status']=='pending'?'selected':'')?>>pending</option>
        <option value="paid" <?=($order['status']=='paid'?'selected':'')?>>paid</option>
        <option value="completed" <?=($order['status']=='completed'?'selected':'')?>>completed</option>
        <option value="cancelled" <?=($order['status']=='cancelled'?'selected':'')?>>cancelled</option>
      </select>
    </label>
    <input type="hidden" name="csrf_token" value="<?=e(CSRF::token())?>">
    <button type="submit" name="set_status">Uložiť stav</button>
    <button type="submit" name="generate_invoice">Vystaviť faktúru</button>
    <button type="submit" name="create_tokens">Vytvoriť tokeny na sťahovanie</button>
  </form>
</main>
</body></html>
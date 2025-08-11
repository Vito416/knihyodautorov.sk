<?php
// /admin/order-view.php
require_once __DIR__ . '/bootstrap.php';
require_admin();
require_once __DIR__ . '/inc/helpers.php';

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: orders.php'); exit; }

$order = $pdo->prepare("SELECT o.*, u.meno,u.email FROM orders o LEFT JOIN users u ON o.user_id = u.id WHERE o.id = ? LIMIT 1");
$order->execute([$id]); $order = $order->fetch(PDO::FETCH_ASSOC);
$items = $pdo->prepare("SELECT oi.*, b.nazov FROM order_items oi LEFT JOIN books b ON oi.book_id = b.id WHERE oi.order_id = ?");
$items->execute([$id]); $items = $items->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/header.php';
?>
<section class="adm-section">
  <h1>Objednávka #<?= adm_esc($id) ?></h1>

  <div class="card">
    <h3>Detaily objednávky</h3>
    <p><strong>Užívateľ:</strong> <?= adm_esc($order['meno'] ?? 'Hosť') ?> — <?= adm_esc($order['email'] ?? '-') ?></p>
    <p><strong>Suma:</strong> <?= adm_esc(adm_money($order['total_price'])) ?></p>
    <p><strong>Stav:</strong> <?= adm_esc($order['status']) ?></p>
    <p><strong>Platba:</strong> <?= adm_esc($order['payment_method']) ?></p>
    <p><strong>Dátum:</strong> <?= adm_esc($order['created_at']) ?></p>

    <h4>Položky</h4>
    <ul>
      <?php foreach ($items as $it): ?>
        <li><?= adm_esc($it['nazov']) ?> — x<?= adm_esc($it['quantity']) ?> — <?= adm_esc(adm_money($it['unit_price'])) ?></li>
      <?php endforeach; ?>
    </ul>
    <div style="margin-top:12px;">
      <a class="adm-btn" href="/admin/orders.php">Späť</a>
      <a class="adm-btn" href="/admin/invoice-create.php?order=<?= adm_esc($id) ?>">Vytvoriť faktúru</a>
    </div>
  </div>
</section>
<?php include __DIR__ . '/footer.php'; ?>

<?php
// /eshop/my-orders.php
require __DIR__ . '/_init.php';
$u = current_user($pdo);
if (!$u) {
  // můžeš nahradit za vaši login stránku
  header('Location: /login.php?next=/eshop/my-orders.php'); exit;
}

$st = $pdo->prepare("SELECT id, total_price, status, created_at FROM orders WHERE user_id = ? ORDER BY created_at DESC LIMIT 200");
$st->execute([(int)$u['id']]);
$orders = $st->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="sk">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Moje objednávky</title>
  <link rel="stylesheet" href="<?php echo eshop_asset('eshop/css/eshop.css'); ?>">
</head>
<body>
<header class="eshop-header"><div class="wrap"><a href="/eshop/index.php">Knihy</a><span>Prihlásený: <?php echo eshop_esc($u['meno'] ?? $u['email']); ?></span></div></header>

<main class="eshop-wrap">
  <div class="container">
    <h1>Moje objednávky</h1>
    <?php if (empty($orders)): ?>
      <div class="empty">Zatiaľ nemáte žiadne objednávky.</div>
    <?php else: ?>
      <table class="cart-table">
        <thead><tr><th>ID</th><th>Dátum</th><th>Stav</th><th>Suma</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($orders as $o): ?>
          <tr>
            <td>#<?php echo (int)$o['id']; ?></td>
            <td><?php echo eshop_esc($o['created_at']); ?></td>
            <td><?php echo eshop_esc($o['status']); ?></td>
            <td><?php echo number_format((float)$o['total_price'],2,',','.'); ?> €</td>
            <td><a class="btn" href="/eshop/order.php?id=<?php echo (int)$o['id']; ?>">Detail</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</main>

<script src="<?php echo eshop_asset('eshop/js/eshop.js'); ?>"></script>
</body>
</html>
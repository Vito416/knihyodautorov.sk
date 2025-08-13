<?php
// /eshop/cart.php
require __DIR__ . '/_init.php';

$cart = eshop_get_cart();
$items = [];
$total = 0.0;
if (!empty($cart)) {
    $ids = array_map('intval', array_keys($cart));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT id, nazov, slug, cena, obrazok FROM books WHERE id IN ($placeholders)");
    $stmt->execute($ids);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $qty = (int)($cart[$r['id']] ?? 0);
        $r['qty'] = $qty;
        $r['line'] = $qty * (float)$r['cena'];
        $items[] = $r;
        $total += $r['line'];
    }
}

// handle update/delete via POST? We'll provide action endpoints, show UI
?>
<!doctype html>
<html lang="sk">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Košík — Knihy od autorov</title>
  <link rel="stylesheet" href="<?php echo eshop_asset('eshop/css/eshop.css'); ?>">
</head>
<body>
  <header class="eshop-header"><div class="wrap"><a href="/eshop/index.php">Knihy</a><a href="/eshop/checkout.php">Checkout</a></div></header>

  <main class="eshop-wrap">
    <div class="container">
      <h1>Košík</h1>
      <?php if (empty($items)): ?>
        <div class="empty">Váš košík je prázdny.</div>
      <?php else: ?>
        <form method="post" action="/eshop/actions/cart-update.php">
          <input type="hidden" name="csrf" value="<?php echo eshop_csrf_token(); ?>">
          <table class="cart-table">
            <thead><tr><th>Kniha</th><th>Množstvo</th><th>Cena</th><th>Spolu</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($items as $it): ?>
              <tr>
                <td><a href="/eshop/book.php?slug=<?php echo eshop_esc($it['slug']); ?>"><?php echo eshop_esc($it['nazov']); ?></a></td>
                <td><input type="number" name="qty[<?php echo (int)$it['id']; ?>]" value="<?php echo (int)$it['qty']; ?>" min="0" max="99"></td>
                <td><?php echo number_format((float)$it['cena'],2,',','.'); ?> €</td>
                <td><?php echo number_format((float)$it['line'],2,',','.'); ?> €</td>
                <td><button name="remove" value="<?php echo (int)$it['id']; ?>" class="btn-ghost">Odstrániť</button></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>

          <div class="cart-footer">
            <div class="total">Spolu: <strong><?php echo number_format($total,2,',','.'); ?> €</strong></div>
            <div class="actions">
              <a class="btn" href="/eshop/index.php">Pokračovať v nákupe</a>
              <button class="btn-primary" formaction="/eshop/checkout.php">Prejsť k platbe</button>
              <button type="submit" class="btn">Aktualizovať</button>
            </div>
          </div>
        </form>
      <?php endif; ?>
    </div>
  </main>
  <script src="<?php echo eshop_asset('eshop/js/eshop.js'); ?>"></script>
</body>
</html>
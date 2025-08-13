<?php
// /eshop/checkout.php
require __DIR__ . '/_init.php';

$cart = eshop_get_cart();
if (empty($cart)) {
    header('Location: /eshop/cart.php'); exit;
}

// fetch items details
$ids = array_map('intval', array_keys($cart));
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$stmt = $pdo->prepare("SELECT id, nazov, cena FROM books WHERE id IN ($placeholders)");
$stmt->execute($ids);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$items = [];
$total = 0.0;
foreach ($rows as $r) {
    $qty = (int)$cart[$r['id']];
    $r['qty'] = $qty;
    $r['line'] = $qty * (float)$r['cena'];
    $items[] = $r;
    $total += $r['line'];
}

// Prefill form if logged in
$user = current_user($pdo);

$default_name = $user['meno'] ?? '';
$default_email = $user['email'] ?? '';
$company = eshop_settings($pdo, 'company_name') ?? '';

?>
<!doctype html>
<html lang="sk">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Checkout — Knihy od autorov</title>
  <link rel="stylesheet" href="<?php echo eshop_asset('eshop/css/eshop.css'); ?>">
</head>
<body>
  <header class="eshop-header"><div class="wrap"><a href="/eshop/index.php">Knihy</a><a href="/eshop/cart.php">Košík (<?php echo eshop_cart_count(); ?>)</a></div></header>

  <main class="eshop-wrap">
    <div class="container checkout">
      <h1>Objednávka</h1>

      <section class="order-summary">
        <h3>Objednávka</h3>
        <ul>
          <?php foreach ($items as $it): ?>
            <li><?php echo eshop_esc($it['nazov']); ?> &times; <?php echo (int)$it['qty']; ?> — <?php echo number_format($it['line'],2,',','.'); ?> €</li>
          <?php endforeach; ?>
        </ul>
        <div class="total">Celkom: <strong><?php echo number_format($total,2,',','.'); ?> €</strong></div>
      </section>

      <section class="billing">
        <h3>Fakturačné údaje</h3>
        <form id="checkout-form" action="/eshop/actions/checkout-create.php" method="post">
          <input type="hidden" name="csrf" value="<?php echo eshop_csrf_token(); ?>">
          <label>Celé meno / Firma <input type="text" name="name" required value="<?php echo eshop_esc($default_name); ?>"></label>
          <label>Email <input type="email" name="email" required value="<?php echo eshop_esc($default_email); ?>"></label>
          <label>Adresa <textarea name="address" required><?php echo eshop_esc($user['adresa'] ?? ''); ?></textarea></label>

          <label>Spôsob platby
            <select name="payment_method">
              <option value="bank_transfer">Bankový prevod</option>
              <option value="card">Platobná karta (niekedy neskôr)</option>
            </select>
          </label>

          <div class="actions">
            <a class="btn" href="/eshop/cart.php">Späť do košíka</a>
            <button class="btn-primary" type="submit">Potvrdiť objednávku</button>
          </div>
        </form>
      </section>
    </div>
  </main>

  <script src="<?php echo eshop_asset('/eshop/js/eshop.js'); ?>"></script>
</body>
</html>
<?php
// /eshop/order.php
require __DIR__ . '/_init.php';

$orderId = (int)($_GET['id'] ?? 0);
if ($orderId <= 0) { http_response_code(400); echo "Chýba ID objednávky."; exit; }

$u = current_user($pdo);
$st = $pdo->prepare("SELECT o.*, u.meno AS user_name, u.email AS user_email FROM orders o LEFT JOIN users u ON u.id = o.user_id WHERE o.id = ? LIMIT 1");
$st->execute([$orderId]);
$o = $st->fetch(PDO::FETCH_ASSOC);
if (!$o) { http_response_code(404); echo "Objednávka nenájdená."; exit; }

// oprávnenie: ak je order priradená k userovi a user je prihlásený, musí sedieť
if (!empty($o['user_id'])) {
  if (!$u || (int)$u['id'] !== (int)$o['user_id']) {
    http_response_code(403); echo "Nemáte prístup k tejto objednávke."; exit;
  }
}

$its = $pdo->prepare("SELECT oi.*, b.nazov, b.slug FROM order_items oi LEFT JOIN books b ON b.id = oi.book_id WHERE oi.order_id = ?");
$its->execute([$orderId]);
$items = $its->fetchAll(PDO::FETCH_ASSOC);

// ak je paid, načítame download tokeny
$tokens = [];
if ($o['status'] === 'paid') {
  $tk = $pdo->prepare("SELECT dt.token, dt.book_id, b.nazov FROM download_tokens dt LEFT JOIN books b ON b.id = dt.book_id WHERE dt.user_id = ? AND dt.book_id IN (SELECT book_id FROM order_items WHERE order_id = ?) AND dt.used = 0 ORDER BY dt.created_at DESC");
  $tk->execute([(int)$o['user_id'], $orderId]);
  $tokens = $tk->fetchAll(PDO::FETCH_ASSOC);
}

$iban = eshop_settings($pdo, 'company_iban') ?? '';
$vs = str_pad((string)$orderId, 10, '0', STR_PAD_LEFT);
?>
<!doctype html>
<html lang="sk">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Objednávka #<?php echo (int)$orderId; ?></title>
  <link rel="stylesheet" href="<?php echo eshop_asset('eshop/css/eshop.css'); ?>">
</head>
<body>
<header class="eshop-header"><div class="wrap"><a href="/eshop/index.php">Knihy</a><a href="/eshop/my-orders.php">Moje objednávky</a></div></header>

<main class="eshop-wrap">
  <div class="container checkout">
    <h1>Objednávka #<?php echo (int)$orderId; ?> <small class="muted">(<?php echo eshop_esc($o['status']); ?>)</small></h1>

    <div class="order-summary">
      <h3>Položky</h3>
      <ul>
        <?php foreach ($items as $it): $line = $it['quantity'] * (float)$it['unit_price']; ?>
          <li>
            <a href="/eshop/book.php?slug=<?php echo eshop_esc($it['slug']); ?>"><?php echo eshop_esc($it['nazov']); ?></a>
            &times; <?php echo (int)$it['quantity']; ?>
            — <?php echo number_format($line,2,',','.'); ?> €
          </li>
        <?php endforeach; ?>
      </ul>
      <div class="total">Celkom: <strong><?php echo number_format((float)$o['total_price'],2,',','.'); ?> €</strong></div>
    </div>

    <?php if ($o['status'] !== 'paid'): ?>
      <div class="billing">
        <h3>Platobné údaje</h3>
        <ul>
          <li>Suma: <strong><?php echo number_format((float)$o['total_price'],2,',','.'); ?> €</strong></li>
          <li>IBAN: <strong><?php echo eshop_esc($iban ?: '—'); ?></strong></li>
          <li>Variabilný symbol (VS): <strong><?php echo eshop_esc($vs); ?></strong></li>
        </ul>
        <img src="/eshop/actions/qr-image.php?order=<?php echo (int)$orderId; ?>" alt="QR" width="140" height="140">
        <p class="muted">Po pripísaní platby sa stav zmení na paid a sprístupní sa sťahovanie.</p>
      </div>
    <?php else: ?>
      <div class="billing">
        <h3>Stiahnutie zakúpených titulov</h3>
        <?php if (empty($tokens)): ?>
          <p>Zatiaľ tu nie sú dostupné odkazy. Ak chyba pretrváva, kontaktujte podporu.</p>
        <?php else: ?>
          <ul>
            <?php foreach ($tokens as $t): ?>
              <li><?php echo eshop_esc($t['nazov']); ?> —
                <a class="btn" href="/eshop/download.php?token=<?php echo eshop_esc($t['token']); ?>">Stiahnuť PDF</a>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
        <form action="/eshop/actions/resend-invoice.php" method="post" style="margin-top:10px">
          <input type="hidden" name="csrf" value="<?php echo eshop_csrf_token(); ?>">
          <input type="hidden" name="order_id" value="<?php echo (int)$orderId; ?>">
          <button class="btn">Znova poslať faktúru na email</button>
        </form>
      </div>
    <?php endif; ?>
  </div>
</main>

<script src="<?php echo eshop_asset('eshop/js/eshop.js'); ?>"></script>
</body>
</html>
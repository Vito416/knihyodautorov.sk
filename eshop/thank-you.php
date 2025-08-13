<?php
// /eshop/thank-you.php
require __DIR__ . '/_init.php';

$orderId = (int)($_GET['order'] ?? 0);
if ($orderId <= 0) { header('Location: /eshop/index.php'); exit; }

// načteme objednávku + fakturu
$st = $pdo->prepare("SELECT o.*, i.invoice_number FROM orders o LEFT JOIN invoices i ON i.order_id = o.id WHERE o.id = ? LIMIT 1");
$st->execute([$orderId]);
$o = $st->fetch(PDO::FETCH_ASSOC);
if (!$o) { http_response_code(404); echo "Objednávka nenájdená."; exit; }

$iban = eshop_settings($pdo, 'company_iban') ?? '';
$vs = str_pad((string)$orderId, 10, '0', STR_PAD_LEFT);
$amount = number_format((float)$o['total_price'], 2, ',', '.');
?>
<!doctype html>
<html lang="sk">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Ďakujeme za objednávku — #<?php echo (int)$orderId; ?></title>
  <link rel="stylesheet" href="<?php echo eshop_asset('eshop/css/eshop.css'); ?>">
</head>
<body>
<header class="eshop-header"><div class="wrap"><a href="/eshop/index.php">Knihy</a><a href="/eshop/cart.php">Košík</a></div></header>

<main class="eshop-wrap">
  <div class="container">
    <div class="checkout">
      <h1>Ďakujeme za objednávku! <small class="muted">#<?php echo (int)$orderId; ?></small></h1>
      <p>Na email vám príde potvrdenie a faktúra (ak je dostupná). Pre úhradu môžete použiť údaje nižšie:</p>

      <div class="order-summary">
        <ul>
          <li>Číslo objednávky / VS: <strong><?php echo eshop_esc($vs); ?></strong></li>
          <li>Suma: <strong><?php echo eshop_esc($amount); ?> €</strong></li>
          <li>IBAN: <strong><?php echo eshop_esc($iban ?: '—'); ?></strong></li>
          <li>Variabilný symbol: <strong><?php echo eshop_esc($vs); ?></strong></li>
        </ul>
        <div style="display:flex;align-items:center;gap:16px;margin-top:12px">
          <img src="/eshop/actions/qr-image.php?order=<?php echo (int)$orderId; ?>" alt="QR" width="160" height="160" style="border-radius:8px;border:1px solid rgba(0,0,0,.06)">
          <div class="muted">Naskenujte QR kód vo vašej bankovej aplikácii.</div>
        </div>
      </div>

      <div class="billing">
        <p>Po prijatí platby zmeníme stav na <strong>paid</strong> a sprístupníme sťahovanie zakúpených titulov. Ak ste prihlásený/á, objednávku nájdete v sekcii <a href="/eshop/my-orders.php">Moje objednávky</a>.</p>
        <a class="btn" href="/eshop/index.php">Späť do obchodu</a>
        <?php if (!empty($o['invoice_number'])): ?>
          <a class="btn" href="/admin/prints/invoice-template.php?order=<?php echo (int)$orderId; ?>" target="_blank" rel="noopener">Zobraziť faktúru (admin šablóna)</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</main>

<script src="<?php echo eshop_asset('eshop/js/eshop.js'); ?>"></script>
</body>
</html>
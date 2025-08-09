<?php
// eshop/checkout.php
session_start();
require_once __DIR__ . '/../db/config/config.php';

// pre demo: ak nie je user prihlásený, presmeruj na login
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php?next=' . urlencode('/eshop/checkout.php'));
    exit;
}

$cart = $_SESSION['cart'] ?? [];
if (empty($cart)) {
    header('Location: cart.php');
    exit;
}

// načítame knihy z košíka
$ids = array_map('intval', array_keys($cart));
$in = implode(',', array_fill(0, count($ids), '?'));
$stmt = $pdo->prepare("SELECT id, cena FROM books WHERE id IN ($in)");
$stmt->execute($ids);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total = 0.0;
foreach ($rows as $r) {
    $qty = $cart[$r['id']] ?? 1;
    $total += (float)$r['cena'] * $qty;
}

// pri POST vytvoríme objednávku (simulované zaplatenie)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userId = (int)$_SESSION['user_id'];
    $stmtOrder = $pdo->prepare("INSERT INTO orders (user_id, total_price, currency, status, payment_method) VALUES (?, ?, ?, ?, ?)");
    // pre demo: status 'paid' a payment_method 'card'
    $stmtOrder->execute([$userId, number_format($total,2,'.',''), 'EUR', 'paid', 'card']);
    $orderId = (int)$pdo->lastInsertId();
    $stmtItem = $pdo->prepare("INSERT INTO order_items (order_id, book_id, quantity, unit_price) VALUES (?, ?, ?, ?)");
    foreach ($rows as $r) {
        $qid = $cart[$r['id']] ?? 1;
        $stmtItem->execute([$orderId, $r['id'], $qid, number_format($r['cena'],2,'.','')]);
    }
    // vyprázdni košík
    unset($_SESSION['cart']);
    // presmeruj na potvrdenie a umožni stiahnutie
    header('Location: ../eshop/order-success.php?id=' . $orderId);
    exit;
}

// include header
if (file_exists(__DIR__ . '/partials/header.php')) include __DIR__ . '/partials/header.php';
echo '<link rel="stylesheet" href="css/checkout.css">';
?>

<section class="checkout-page">
  <div class="checkout-inner">
    <h1>Pokladňa</h1>
    <p>Celková suma: <strong><?= htmlspecialchars(number_format($total,2,',','')) ?> €</strong></p>
    <form method="post">
      <p>Pre demo simulujeme platbu kartou — kliknite "Zaplať a objednať".</p>
      <button type="submit" class="checkout-pay">Zaplať a objednať</button>
    </form>
  </div>
</section>

<?php if (file_exists(__DIR__ . '/partials/footer.php')) include __DIR__ . '/partials/footer.php'; ?>

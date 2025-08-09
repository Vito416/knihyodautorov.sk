<?php
// eshop/cart.php
session_start();
require_once __DIR__ . '/../db/config/config.php';

if (!isset($_SESSION['cart'])) $_SESSION['cart'] = []; // book_id => qty

$action = $_REQUEST['action'] ?? '';

function redirectBack() {
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'eshop.php'));
    exit;
}

if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $bookId = (int)($_POST['book_id'] ?? 0);
    $qty = max(1, min(100, (int)($_POST['qty'] ?? 1)));
    if ($bookId > 0) {
        if (!isset($_SESSION['cart'][$bookId])) $_SESSION['cart'][$bookId] = 0;
        $_SESSION['cart'][$bookId] += $qty;
    }
    redirectBack();
}

if ($action === 'remove' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $bookId = (int)($_POST['book_id'] ?? 0);
    unset($_SESSION['cart'][$bookId]);
    redirectBack();
}

if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($_POST['qty'] as $bookId => $q) {
        $bookId = (int)$bookId; $q = max(0, (int)$q);
        if ($q <= 0) { unset($_SESSION['cart'][$bookId]); } else { $_SESSION['cart'][$bookId] = $q; }
    }
    redirectBack();
}

// show cart
if (file_exists(__DIR__ . '/partials/header.php')) include __DIR__ . '/partials/header.php';
echo '<link rel="stylesheet" href="/eshop/css/cart.css">';

$cart = $_SESSION['cart'];
$books = [];
$total = 0.0;
if (!empty($cart)) {
    $ids = array_map('intval', array_keys($cart));
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT * FROM books WHERE id IN ($in)");
    $stmt->execute($ids);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $r['qty'] = $cart[$r['id']] ?? 0;
        $r['line'] = $r['qty'] * (float)$r['cena'];
        $total += $r['line'];
        $books[] = $r;
    }
}
?>

<section class="cart-page">
  <div class="cart-inner">
    <h1>Košík</h1>
    <?php if (empty($books)): ?>
      <p>Váš košík je prázdny. <a href="eshop.php">Štart shop</a></p>
    <?php else: ?>
      <form method="post" action="cart.php?action=update" class="cart-form">
        <table class="cart-table">
          <thead><tr><th>Kniha</th><th>Cena</th><th>Množstvo</th><th>Spolu</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($books as $b): ?>
              <tr>
                <td class="cart-title"><?= htmlspecialchars($b['nazov']) ?><br><small><?= htmlspecialchars($b['qty']) ?>× autor: <?= htmlspecialchars($b['author_id']) ?></small></td>
                <td><?= htmlspecialchars(number_format($b['cena'],2,',','')) ?> €</td>
                <td><input type="number" name="qty[<?= (int)$b['id'] ?>]" value="<?= (int)$b['qty'] ?>" min="0" max="99"></td>
                <td><?= htmlspecialchars(number_format($b['line'],2,',','')) ?> €</td>
                <td>
                  <form method="post" action="cart.php?action=remove" style="display:inline">
                    <input type="hidden" name="book_id" value="<?= (int)$b['id'] ?>">
                    <button type="submit" class="cart-remove">Odstrániť</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <div class="cart-actions">
          <button type="submit" class="cart-update">Aktualizovať košík</button>
          <a href="checkout.php" class="cart-checkout">Pokračovať k platbe — <?= htmlspecialchars(number_format($total,2,',','')) ?> €</a>
        </div>
      </form>
    <?php endif; ?>
  </div>
</section>

<?php if (file_exists(__DIR__ . '/partials/footer.php')) include __DIR__ . '/partials/footer.php'; ?>

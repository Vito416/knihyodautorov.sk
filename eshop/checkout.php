<?php
// /eshop/checkout.php
require __DIR__ . '/bootstrap.php'; // obsahuje $pdo, helpery
// session cart structure: $_SESSION['cart'] = [ book_id => qty, ... ]
$pdoLocal = $pdo;

// helper: load book by id
function loadBook(PDO $pdo, int $id) {
    $stmt = $pdo->prepare("SELECT id,nazov,cena,pdf_file,obrazok,author_id FROM books WHERE id = ? AND COALESCE(is_active,1)=1 LIMIT 1");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// init cart if not present
if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) $_SESSION['cart'] = [];

// actions: add/remove/update
if (isset($_GET['add'])) {
    $id = (int)$_GET['add'];
    if ($id > 0) {
        $_SESSION['cart'][$id] = ($_SESSION['cart'][$id] ?? 0) + 1;
    }
    header('Location: checkout.php'); exit;
}
if (isset($_GET['remove'])) {
    $id = (int)$_GET['remove'];
    unset($_SESSION['cart'][$id]);
    header('Location: checkout.php'); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update') {
    foreach ($_POST['qty'] ?? [] as $k => $v) {
        $k = (int)$k; $v = (int)$v;
        if ($v <= 0) unset($_SESSION['cart'][$k]); else $_SESSION['cart'][$k] = $v;
    }
    header('Location: checkout.php'); exit;
}

// proceed to create order
$errors = [];
$success = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['action']) && $_POST['action'] === 'checkout')) {
    if (!verify_csrf($_POST['csrf'] ?? '')) $errors[] = 'Neplatný CSRF token.';
    // require at least one item in cart
    if (empty($_SESSION['cart'])) $errors[] = 'Košík je prázdny.';
    // basic billing fields
    $billing_name  = trim((string)($_POST['billing_name'] ?? ''));
    $billing_email = trim((string)($_POST['billing_email'] ?? ''));
    $billing_address = trim((string)($_POST['billing_address'] ?? ''));
    if ($billing_name === '' || !filter_var($billing_email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Vyplňte meno a platný e-mail.';

    if (empty($errors)) {
        // ensure user exists or create guest user
        $userId = null;
        if (is_logged_in()) {
            $userId = (int)$_SESSION['user_id'];
        } else {
            // create lightweight user entry (guest) or reuse email
            $stmt = $pdoLocal->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
            $stmt->execute([$billing_email]);
            $existing = $stmt->fetchColumn();
            if ($existing) {
                $userId = (int)$existing;
            } else {
                $pwd = bin2hex(random_bytes(8));
                $stmt = $pdoLocal->prepare("INSERT INTO users (meno, email, heslo, adresa) VALUES (?, ?, ?, ?)");
                $stmt->execute([$billing_name, $billing_email, password_hash($pwd, PASSWORD_DEFAULT), $billing_address]);
                $userId = (int)$pdoLocal->lastInsertId();
                // note: not sending password - guest account created
            }
        }

        // compute total
        $total = 0.0;
        $items = [];
        foreach ($_SESSION['cart'] as $bookId => $qty) {
            $book = loadBook($pdoLocal, (int)$bookId);
            if (!$book) continue;
            $qty = max(1,(int)$qty);
            $unit = (float)$book['cena'];
            $total += $unit * $qty;
            $items[] = ['book' => $book, 'qty' => $qty, 'unit' => $unit];
        }
        if (empty($items)) { $errors[] = 'V košíku nie sú platné položky.'; }
    }

    if (empty($errors)) {
        // create order
        $stmt = $pdoLocal->prepare("INSERT INTO orders (user_id, total_price, currency, status, payment_method, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$userId, number_format($total,2,'.',''), 'EUR', 'pending', 'bank_transfer']);
        $orderId = (int)$pdoLocal->lastInsertId();

        // insert items
        $stmtItem = $pdoLocal->prepare("INSERT INTO order_items (order_id, book_id, quantity, unit_price) VALUES (?, ?, ?, ?)");
        foreach ($items as $it) {
            $stmtItem->execute([$orderId, (int)$it['book']['id'], (int)$it['qty'], number_format($it['unit'],2,'.','')]);
        }

        // create invoice and redirect to invoice generator
        header('Location: generate-invoice.php?order=' . $orderId);
        exit;
    }
}

// render page
$cartDetails = [];
$total = 0.0;
foreach ($_SESSION['cart'] as $bookId => $qty) {
    $book = loadBook($pdoLocal, (int)$bookId);
    if (!$book) continue;
    $subtotal = (float)$book['cena'] * (int)$qty;
    $total += $subtotal;
    $cartDetails[] = ['book' => $book, 'qty' => (int)$qty, 'subtotal' => $subtotal];
}

// prefill billing from logged user
$user = is_logged_in() ? current_user($pdoLocal) : null;
?><!doctype html>
<html lang="sk">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Košík & Platba — Knihy od Autorov</title>
  <link rel="stylesheet" href="/eshop/css/checkout.css">
</head>
<body class="eshop">
  <main class="eshop-wrap">
    <h1>Košík</h1>

    <?php if ($errors): ?>
      <div class="msg error"><?php foreach ($errors as $e) echo '<div>' . esc($e) . '</div>'; ?></div>
    <?php endif; ?>

    <form method="post" action="checkout.php">
      <input type="hidden" name="csrf" value="<?php echo esc(csrf_token()); ?>">
      <input type="hidden" name="action" value="update">
      <div class="card">
        <h2>Položky</h2>
        <?php if (empty($cartDetails)): ?>
          <p>Košík je prázdny. Pre pridanie klikni na "Pridať do košíka" pri knihe.</p>
        <?php else: ?>
          <table class="cart-table">
            <thead><tr><th>Kniha</th><th>Množstvo</th><th>Jednotka</th><th>Spolu</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($cartDetails as $row): ?>
                <tr>
                  <td><?php echo esc($row['book']['nazov']); ?></td>
                  <td><input type="number" name="qty[<?php echo (int)$row['book']['id']; ?>]" value="<?php echo (int)$row['qty']; ?>" min="0" /></td>
                  <td><?php echo esc(number_format((float)$row['book']['cena'],2,',',' ')); ?> €</td>
                  <td><?php echo esc(number_format($row['subtotal'],2,',',' ')); ?> €</td>
                  <td><a class="btn small" href="checkout.php?remove=<?php echo (int)$row['book']['id']; ?>">Odstrániť</a></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <div class="cart-actions">
            <button type="submit" class="btn">Aktualizovať košík</button>
            <a class="btn muted" href="/books.php">Pokračovať v nákupe</a>
          </div>
        <?php endif; ?>
      </div>
    </form>

    <div class="card">
      <h2>Fakturačné údaje a spôsob platby</h2>
      <form method="post" action="checkout.php">
        <input type="hidden" name="csrf" value="<?php echo esc(csrf_token()); ?>">
        <input type="hidden" name="action" value="checkout">
        <div class="form-row"><label>Meno</label><input name="billing_name" value="<?php echo esc($user['meno'] ?? ''); ?>" required></div>
        <div class="form-row"><label>E-mail</label><input name="billing_email" type="email" value="<?php echo esc($user['email'] ?? ''); ?>" required></div>
        <div class="form-row"><label>Adresa (fakulta/ulica)</label><textarea name="billing_address"><?php echo esc($user['adresa'] ?? ''); ?></textarea></div>

        <div class="summary">
          <div>Celkom: <strong><?php echo esc(number_format($total,2,',',' ')); ?> €</strong></div>
        </div>

        <div class="form-row">
          <button class="btn" type="submit">Vytvoriť objednávku a vystaviť faktúru</button>
        </div>
      </form>
    </div>
  </main>
  <script src="/eshop/js/checkout.js" defer></script>
</body>
</html>

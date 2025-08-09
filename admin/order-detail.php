<?php
// admin/order-detail.php
session_start();
require_once __DIR__ . '/../db/config/config.php';
if (!isset($_SESSION['admin_id'])) {
    header('Location: /auth/login.php?next=' . urlencode('/admin/order-detail.php'));
    exit;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { header('Location: orders.php'); exit; }

// CSRF
if (!isset($_SESSION['admin_csrf'])) $_SESSION['admin_csrf'] = bin2hex(random_bytes(24));
$csrf = $_SESSION['admin_csrf'];

// POST: zmena stavu
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_status') {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($csrf, $token)) {
        $error = "Neplatný CSRF token.";
    } else {
        $new = $_POST['status'] ?? '';
        $allowed = ['pending','paid','fulfilled','cancelled','refunded'];
        if (!in_array($new, $allowed, true)) $error = "Neplatný stav.";
        else {
            $stmt = $pdo->prepare("UPDATE orders SET status = ? WHERE id = ?");
            $stmt->execute([$new, $id]);
            header("Location: order-detail.php?id={$id}&updated=1");
            exit;
        }
    }
}

// načítanie objednávky
$stmt = $pdo->prepare("SELECT o.*, u.meno AS customer_name, u.email AS customer_email, u.telefon AS customer_phone FROM orders o LEFT JOIN users u ON o.user_id = u.id WHERE o.id = ?");
$stmt->execute([$id]);
$order = $stmt->fetch();

if (!$order) { http_response_code(404); echo "Objednávka nenájdená."; exit; }

// položky
$stmt = $pdo->prepare("SELECT oi.*, b.nazov, b.pdf_file, b.obrazok FROM order_items oi JOIN books b ON oi.book_id = b.id WHERE oi.order_id = ?");
$stmt->execute([$id]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (file_exists(__DIR__ . '/partials/header.php')) include __DIR__ . '/partials/header.php';
?>
<link rel="stylesheet" href="/admin/css/admin-orders.css">

<main class="admin-main">
  <h1>Objednávka #<?= (int)$order['id'] ?> <?php if (isset($_GET['updated'])) echo '<span style="color:green">— Aktualizované</span>'; ?></h1>

  <section style="display:flex;gap:20px;flex-wrap:wrap">
    <div style="flex:1;min-width:320px">
      <h2>Informácie o zákazníkovi</h2>
      <p><strong>Meno:</strong> <?= htmlspecialchars($order['customer_name'] ?: '-') ?></p>
      <p><strong>Email:</strong> <?= htmlspecialchars($order['customer_email'] ?: '-') ?></p>
      <p><strong>Telefón:</strong> <?= htmlspecialchars($order['customer_phone'] ?: '-') ?></p>
    </div>

    <div style="min-width:320px">
      <h2>Stav a platba</h2>
      <p><strong>Stav:</strong> <?= htmlspecialchars($order['status']) ?></p>
      <p><strong>Spôsob platby:</strong> <?= htmlspecialchars($order['payment_method'] ?: '-') ?></p>
      <p><strong>Celkom:</strong> <?= htmlspecialchars(number_format($order['total_price'],2,',','')) ?> <?= htmlspecialchars($order['currency']) ?></p>

      <form method="post" style="margin-top:12px">
        <input type="hidden" name="action" value="change_status">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <label>Zmeniť stav</label>
        <select name="status">
          <?php foreach (['pending'=>'Čaká na platbu','paid'=>'Zaplatené','fulfilled'=>'Vybavené','cancelled'=>'Zrušené','refunded'=>'Vrátené'] as $k=>$label): ?>
            <option value="<?= htmlspecialchars($k) ?>" <?= $order['status'] === $k ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
          <?php endforeach; ?>
        </select>
        <div style="margin-top:8px"><button type="submit" class="btn">Uložiť</button></div>
      </form>
    </div>
  </section>

  <hr>

  <h2>Položky objednávky</h2>
  <table class="adm-table">
    <thead><tr><th>Kniha</th><th>Množstvo</th><th>Cena/ks</th><th>Spolu</th></tr></thead>
    <tbody>
      <?php foreach ($items as $it): ?>
      <tr>
        <td>
          <div style="display:flex;gap:10px;align-items:center">
            <div style="width:64px;height:90px;overflow:hidden;border-radius:6px;background:#f0f0f0">
              <img src="<?= '../books-img/' . htmlspecialchars($it['obrazok'] ?: 'placeholder.jpg') ?>" alt="<?= htmlspecialchars($it['nazov']) ?>" style="width:100%;height:100%;object-fit:cover">
            </div>
            <div>
              <strong><?= htmlspecialchars($it['nazov']) ?></strong><br>
              <small><?= htmlspecialchars($it['pdf_file']) ?></small>
            </div>
          </div>
        </td>
        <td><?= (int)$it['quantity'] ?></td>
        <td><?= htmlspecialchars(number_format($it['unit_price'],2,',','')) ?> €</td>
        <td><?= htmlspecialchars(number_format($it['unit_price'] * $it['quantity'],2,',','')) ?> €</td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

</main>

<?php if (file_exists(__DIR__ . '/partials/footer.php')) include __DIR__ . '/partials/footer.php'; ?>

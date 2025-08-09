<?php
// admin/dashboard.php
session_start();
require_once __DIR__ . '/../db/config/config.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: /auth/login.php?next=' . urlencode('/admin/dashboard.php'));
    exit;
}

// CSRF token
if (!isset($_SESSION['admin_csrf'])) $_SESSION['admin_csrf'] = bin2hex(random_bytes(24));
$csrf = $_SESSION['admin_csrf'];

// základné štatistiky
$totalOrders = (int)$pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
$totalRevenue = (float)$pdo->query("SELECT IFNULL(SUM(total_price),0) FROM orders WHERE status = 'paid' OR status = 'fulfilled'")->fetchColumn();
$totalCustomers = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$totalBooks = (int)$pdo->query("SELECT COUNT(*) FROM books")->fetchColumn();

// posledné objednávky (10)
$recentOrders = $pdo->query("SELECT o.*, u.meno AS customer_name FROM orders o LEFT JOIN users u ON o.user_id=u.id ORDER BY o.created_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);

// najpredávanejšie knihy (top 5)
$topBooks = $pdo->query("
  SELECT b.id, b.nazov, b.obrazok, SUM(oi.quantity) AS sold
  FROM order_items oi
  JOIN books b ON oi.book_id = b.id
  GROUP BY b.id
  ORDER BY sold DESC
  LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

if (file_exists(__DIR__ . '/partials/header.php')) include __DIR__ . '/partials/header.php';
?>
<link rel="stylesheet" href="/admin/css/admin-dashboard.css">

<main class="admin-main">
  <h1>Admin — Dashboard</h1>

  <section class="adm-stats">
    <div class="stat">
      <h3>Objednávky</h3>
      <p class="big"><?= number_format($totalOrders) ?></p>
    </div>
    <div class="stat">
      <h3>Výnos (platné objednávky)</h3>
      <p class="big"><?= number_format($totalRevenue,2,',','') ?> €</p>
    </div>
    <div class="stat">
      <h3>Zákazníci</h3>
      <p class="big"><?= number_format($totalCustomers) ?></p>
    </div>
    <div class="stat">
      <h3>Knihy</h3>
      <p class="big"><?= number_format($totalBooks) ?></p>
    </div>
  </section>

  <section class="adm-recent">
    <h2>Nedávne objednávky</h2>
    <table class="adm-table">
      <thead><tr><th>ID</th><th>Zákazník</th><th>Suma</th><th>Stav</th><th>Dátum</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($recentOrders as $o): ?>
          <tr>
            <td>#<?= (int)$o['id'] ?></td>
            <td><?= htmlspecialchars($o['customer_name'] ?: 'Hosť') ?></td>
            <td><?= htmlspecialchars(number_format($o['total_price'],2,',','')) ?> <?= htmlspecialchars($o['currency']) ?></td>
            <td><?= htmlspecialchars($o['status']) ?></td>
            <td><?= htmlspecialchars($o['created_at']) ?></td>
            <td><a href="order-detail.php?id=<?= (int)$o['id'] ?>">Detail</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </section>

  <section class="adm-topbooks">
    <h2>Najpredávanejšie knihy</h2>
    <div class="top-grid">
      <?php foreach ($topBooks as $b): ?>
        <div class="top-card">
          <div class="top-img"><img src="<?= '../books-img/' . htmlspecialchars($b['obrazok'] ?: 'placeholder.jpg') ?>" alt="<?= htmlspecialchars($b['nazov']) ?>"></div>
          <div class="top-info">
            <strong><?= htmlspecialchars($b['nazov']) ?></strong>
            <div class="muted">Predané: <?= (int)$b['sold'] ?></div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
</main>

<?php if (file_exists(__DIR__ . '/partials/footer.php')) include __DIR__ . '/partials/footer.php'; ?>

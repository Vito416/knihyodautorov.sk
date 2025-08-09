<?php
// admin/customer-detail.php
session_start();
require_once __DIR__ . '/../db/config/config.php';
if (!isset($_SESSION['admin_id'])) {
    header('Location: /auth/login.php?next=' . urlencode('/admin/customer-detail.php'));
    exit;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { header('Location: customers.php'); exit; }

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$id]);
$user = $stmt->fetch();
if (!$user) { http_response_code(404); echo "Užívateľ nenájdený."; exit; }

$stmt = $pdo->prepare("SELECT o.* FROM orders o WHERE o.user_id = ? ORDER BY o.created_at DESC");
$stmt->execute([$id]);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (file_exists(__DIR__ . '/partials/header.php')) include __DIR__ . '/partials/header.php';
?>
<link rel="stylesheet" href="/admin/css/admin-orders.css">

<main class="admin-main">
  <h1>Užívateľ: <?= htmlspecialchars($user['meno']) ?> (ID <?= (int)$user['id'] ?>)</h1>
  <p><strong>Email:</strong> <?= htmlspecialchars($user['email']) ?></p>
  <p><strong>Registrovaný:</strong> <?= htmlspecialchars($user['datum_registracie']) ?></p>

  <h2>Objednávky tohto užívateľa</h2>
  <table class="adm-table">
    <thead><tr><th>ID</th><th>Suma</th><th>Stav</th><th>Dátum</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($orders as $o): ?>
        <tr>
          <td>#<?= (int)$o['id'] ?></td>
          <td><?= htmlspecialchars(number_format($o['total_price'],2,',','')) ?> <?= htmlspecialchars($o['currency']) ?></td>
          <td><?= htmlspecialchars($o['status']) ?></td>
          <td><?= htmlspecialchars($o['created_at']) ?></td>
          <td><a href="order-detail.php?id=<?= (int)$o['id'] ?>">Detail</a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</main>

<?php if (file_exists(__DIR__ . '/partials/footer.php')) include __DIR__ . '/partials/footer.php'; ?>

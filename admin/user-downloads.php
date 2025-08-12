<?php
// /admin/user-downloads.php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
if (!admin_is_logged()) { header('Location: login.php'); exit; }

$user_id = (int)($_GET['user_id'] ?? 0);
if ($user_id <= 0) { header('Location: users.php'); exit; }

// vyber objednávok pre užívateľa
$stmt = $pdo->prepare("
  SELECT o.id as order_id, o.status, o.total_price, o.created_at, oi.book_id, oi.quantity, oi.unit_price, b.nazov, b.pdf_file
  FROM orders o
  JOIN order_items oi ON oi.order_id = o.id
  JOIN books b ON b.id = oi.book_id
  WHERE o.user_id = ? ORDER BY o.created_at DESC
");
$stmt->execute([$user_id]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// info o užívateľovi
$u = $pdo->prepare("SELECT id, meno, email FROM users WHERE id = ? LIMIT 1");
$u->execute([$user_id]); $user = $u->fetch(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="sk">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin — Objednávky používateľa</title>
<link rel="stylesheet" href="/admin/css/admin.css">
<script src="/admin/js/admin.js" defer></script>
</head>
<body>
<main class="admin-shell">
  <header class="admin-top">
    <h1>Objednávky používateľa — <?php echo htmlspecialchars($user['meno'] ?? '—'); ?></h1>
    <div class="actions"><a class="btn ghost" href="user-edit.php?id=<?php echo (int)$user_id; ?>">Späť</a></div>
  </header>

  <?php if (empty($rows)): ?>
    <div class="panel">Používateľ nemá žiadne objednávky.</div>
  <?php else: ?>
    <div class="panel">
      <table class="table">
        <thead><tr><th>Obj.</th><th>Dátum</th><th>Kniha</th><th>Množ.</th><th>Cena</th><th>Stav</th><th>Akcia</th></tr></thead>
        <tbody>
        <?php foreach($rows as $r): ?>
          <tr>
            <td><?php echo (int)$r['order_id']; ?></td>
            <td><?php echo htmlspecialchars($r['created_at']); ?></td>
            <td><?php echo htmlspecialchars($r['nazov']); ?></td>
            <td><?php echo (int)$r['quantity']; ?></td>
            <td><?php echo number_format((float)$r['unit_price'],2,',','.'); ?></td>
            <td><?php echo htmlspecialchars($r['status']); ?></td>
            <td>
              <?php if (!empty($r['pdf_file'])): ?>
                <a class="btn small" href="serve-book.php?book_id=<?php echo (int)$r['book_id']; ?>">Stiahnuť (admin)</a>
              <?php else: ?>
                <span class="muted">Žiadny PDF</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</main>
</body>
</html>
<?php
// /admin/dashboard.php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
if (!admin_is_logged()) { header('Location: login.php'); exit; }

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));

// načítanie základných čísel
$counts = [
    'books' => (int)$pdo->query("SELECT COUNT(*) FROM books")->fetchColumn(),
    'authors' => (int)$pdo->query("SELECT COUNT(*) FROM authors")->fetchColumn(),
    'users' => (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn(),
    'orders' => (int)$pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn(),
    'invoices' => (int)$pdo->query("SELECT COUNT(*) FROM invoices")->fetchColumn(),
];

// posledné objednávky (10)
$recentOrdersStmt = $pdo->query("SELECT o.id,o.total_price,o.currency,o.status,o.created_at,u.meno,u.email FROM orders o LEFT JOIN users u ON o.user_id=u.id ORDER BY o.created_at DESC LIMIT 10");
$recentOrders = $recentOrdersStmt->fetchAll(PDO::FETCH_ASSOC);

// top knihy podľa počtu objednaní (LIMIT 8)
$topBooksStmt = $pdo->query("
  SELECT b.id, b.nazov, b.obrazok, COUNT(oi.id) AS sold_count, SUM(oi.quantity) AS qty_sum
  FROM books b
  LEFT JOIN order_items oi ON oi.book_id = b.id
  GROUP BY b.id
  ORDER BY sold_count DESC, qty_sum DESC
  LIMIT 8
");
$topBooks = $topBooksStmt->fetchAll(PDO::FETCH_ASSOC);

// mesačný prehľad objednávok (posledných 6 mesiacov)
$monthsStmt = $pdo->query("
  SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) as cnt, SUM(total_price) as sum
  FROM orders
  GROUP BY ym
  ORDER BY ym DESC
  LIMIT 6
");
$months = array_reverse($monthsStmt->fetchAll(PDO::FETCH_ASSOC));

?><!doctype html>
<html lang="sk">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Admin — Dashboard</title>
  <link rel="stylesheet" href="/admin/css/admin.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script src="/admin/js/admin-charts.js" defer></script>
</head>
<body class="admin-dashboard">
  <main class="admin-shell">
    <header class="admin-top">
      <h1>Dashboard</h1>
      <div class="actions">
        <a class="btn" href="books.php">Spravovať knihy</a>
        <a class="btn" href="orders.php">Spracovať objednávky</a>
      </div>
    </header>

    <section class="stats-grid">
      <div class="stat card">
        <h3>Knihy</h3><p class="big"><?php echo $counts['books']; ?></p>
      </div>
      <div class="stat card">
        <h3>Autori</h3><p class="big"><?php echo $counts['authors']; ?></p>
      </div>
      <div class="stat card">
        <h3>Užívatelia</h3><p class="big"><?php echo $counts['users']; ?></p>
      </div>
      <div class="stat card">
        <h3>Objednávky</h3><p class="big"><?php echo $counts['orders']; ?></p>
      </div>
    </section>

    <section class="panels">
      <div class="panel left">
        <h2>Posledné objednávky</h2>
        <table class="table">
          <thead><tr><th>#</th><th>Užívateľ</th><th>Celkom</th><th>Status</th><th>Dátum</th></tr></thead>
          <tbody>
            <?php foreach($recentOrders as $o): ?>
              <tr>
                <td><a href="orders.php?view=<?php echo (int)$o['id']; ?>">#<?php echo (int)$o['id']; ?></a></td>
                <td><?php echo htmlspecialchars($o['meno'] ?? $o['email']); ?></td>
                <td><?php echo number_format((float)$o['total_price'],2,',','.').' '.htmlspecialchars($o['currency']); ?></td>
                <td><?php echo htmlspecialchars($o['status']); ?></td>
                <td><?php echo htmlspecialchars($o['created_at']); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <div style="margin-top:12px;">
          <a class="btn" href="orders.php">Zobraziť všetky objednávky</a>
          <a class="btn ghost" href="orders-export.php">Export CSV</a>
        </div>
      </div>

      <div class="panel right">
        <h2>Top knihy</h2>
        <div class="grid small">
          <?php foreach($topBooks as $b): ?>
            <div class="book-mini card">
              <img src="<?php echo $b['obrazok'] ? '/books-img/'.htmlspecialchars($b['obrazok']) : '/assets/books-placeholder.png'; ?>" alt="" style="width:100%;height:120px;object-fit:cover;border-radius:6px;">
              <h4><?php echo htmlspecialchars($b['nazov']); ?></h4>
              <div class="muted">Predané: <?php echo (int)$b['sold_count']; ?> (ks <?php echo (int)$b['qty_sum']; ?>)</div>
            </div>
          <?php endforeach; ?>
        </div>

        <h3 style="margin-top:18px">Mesačný prehľad</h3>
        <canvas id="ordersChart" width="400" height="200"></canvas>
      </div>
    </section>

    <section style="margin-top:18px">
      <h2>Rýchle akcie</h2>
      <div class="form-actions">
        <a class="btn" href="books.php">Spravovať knihy</a>
        <a class="btn" href="reviews.php">Moderovať recenzie</a>
        <a class="btn" href="invoice-create.php">Vytvoriť faktúru</a>
        <a class="btn ghost" href="export-books.php">Export kníh (CSV)</a>
      </div>
    </section>

  </main>

  <script>
    // dáta pre graf
    window._dashboardData = {
      months: <?php echo json_encode(array_column($months,'ym')); ?>,
      counts: <?php echo json_encode(array_map(function($m){ return (int)$m['cnt']; }, $months)); ?>,
      sums: <?php echo json_encode(array_map(function($m){ return (float)$m['sum']; }, $months)); ?>
    };
  </script>
</body>
</html>
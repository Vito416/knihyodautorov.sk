<?php
// /admin/orders.php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
if (!admin_is_logged()) { header('Location: login.php'); exit; }

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));

// pagination + filter
$perPage = 30;
$page = max(1, (int)($_GET['p'] ?? 1));
$offset = ($page-1)*$perPage;
$statusFilter = trim((string)($_GET['status'] ?? ''));

// counting
$where = "1=1";
$params = [];
if ($statusFilter !== '') { $where = "o.status = :status"; $params[':status']=$statusFilter; }

$total = (int)$pdo->prepare("SELECT COUNT(*) FROM orders o WHERE $where")->execute($params) ? 0 : 0;
// we need to compute total correctly with prepared
$totalStmt = $pdo->prepare("SELECT COUNT(*) FROM orders o WHERE $where");
$totalStmt->execute($params);
$total = (int)$totalStmt->fetchColumn();

$stmt = $pdo->prepare("SELECT o.*, u.meno as user_name, u.email as user_email FROM orders o LEFT JOIN users u ON o.user_id = u.id WHERE $where ORDER BY o.created_at DESC LIMIT :lim OFFSET :off");
foreach ($params as $k=>$v) $stmt->bindValue($k,$v);
$stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':off', $offset, PDO::PARAM_INT);
$stmt->execute();
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalPages = (int)ceil($total / $perPage);

// view single order?
$viewOrder = null;
if (!empty($_GET['view'])) {
    $oid = (int)$_GET['view'];
    $oStmt = $pdo->prepare("SELECT o.*, u.meno as user_name, u.email as user_email FROM orders o LEFT JOIN users u ON o.user_id = u.id WHERE o.id = ? LIMIT 1");
    $oStmt->execute([$oid]);
    $viewOrder = $oStmt->fetch(PDO::FETCH_ASSOC);
    if ($viewOrder) {
        $itemsStmt = $pdo->prepare("SELECT oi.*, b.nazov, b.obrazok FROM order_items oi LEFT JOIN books b ON oi.book_id = b.id WHERE oi.order_id = ?");
        $itemsStmt->execute([$oid]);
        $viewOrder['items'] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

?><!doctype html>
<html lang="sk">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Admin — Objednávky</title>
  <link rel="stylesheet" href="/admin/css/admin.css">
  <script src="/admin/js/admin.js" defer></script>
</head>
<body class="admin-orders">
  <main class="admin-shell">
    <header class="admin-top">
      <h1>Objednávky</h1>
      <div class="actions">
        <a class="btn ghost" href="orders-export.php">Export CSV</a>
      </div>
    </header>

    <section class="filters">
      <form method="get" action="orders.php" class="search-row">
        <select name="status">
          <option value="">Všetky statusy</option>
          <option value="pending" <?php if($statusFilter==='pending') echo 'selected'; ?>>pending</option>
          <option value="paid" <?php if($statusFilter==='paid') echo 'selected'; ?>>paid</option>
          <option value="fulfilled" <?php if($statusFilter==='fulfilled') echo 'selected'; ?>>fulfilled</option>
          <option value="cancelled" <?php if($statusFilter==='cancelled') echo 'selected'; ?>>cancelled</option>
          <option value="refunded" <?php if($statusFilter==='refunded') echo 'selected'; ?>>refunded</option>
        </select>
        <button class="btn" type="submit">Filtrovať</button>
      </form>
    </section>

    <section class="list">
      <table class="table">
        <thead><tr><th>#</th><th>Užívateľ</th><th>Celkom</th><th>Status</th><th>Dátum</th><th>Akcie</th></tr></thead>
        <tbody>
          <?php foreach($orders as $o): ?>
            <tr>
              <td><a href="?view=<?php echo (int)$o['id']; ?>">#<?php echo (int)$o['id']; ?></a></td>
              <td><?php echo htmlspecialchars($o['user_name'] ?? $o['user_email']); ?></td>
              <td><?php echo number_format((float)$o['total_price'],2,',','.').' '.htmlspecialchars($o['currency']); ?></td>
              <td><?php echo htmlspecialchars($o['status']); ?></td>
              <td><?php echo htmlspecialchars($o['created_at']); ?></td>
              <td>
                <form method="post" action="order-action.php" style="display:inline">
                  <input type="hidden" name="csrf" value="<?php echo $_SESSION['csrf_token']; ?>">
                  <input type="hidden" name="id" value="<?php echo (int)$o['id']; ?>">
                  <select name="status" aria-label="Zmeniť status">
                    <option value="pending" <?php if($o['status']==='pending') echo 'selected'; ?>>pending</option>
                    <option value="paid" <?php if($o['status']==='paid') echo 'selected'; ?>>paid</option>
                    <option value="fulfilled" <?php if($o['status']==='fulfilled') echo 'selected'; ?>>fulfilled</option>
                    <option value="cancelled" <?php if($o['status']==='cancelled') echo 'selected'; ?>>cancelled</option>
                    <option value="refunded" <?php if($o['status']==='refunded') echo 'selected'; ?>>refunded</option>
                  </select>
                  <button class="btn small" type="submit">Uložiť</button>
                </form>
                <form method="post" action="order-action.php" style="display:inline" onsubmit="return confirm('Naozaj vymazať objednávku?');">
                  <input type="hidden" name="csrf" value="<?php echo $_SESSION['csrf_token']; ?>">
                  <input type="hidden" name="id" value="<?php echo (int)$o['id']; ?>">
                  <input type="hidden" name="action" value="delete">
                  <button class="btn small danger" type="submit">Vymazať</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <?php if ($totalPages>1): ?>
        <nav class="pager">
          <?php for($i=1;$i<=$totalPages;$i++): ?>
            <a class="<?php echo $i===$page ? 'active' : ''; ?>" href="?p=<?php echo $i; ?><?php if($statusFilter) echo '&status='.urlencode($statusFilter); ?>"><?php echo $i; ?></a>
          <?php endfor; ?>
        </nav>
      <?php endif; ?>

    </section>

    <?php if ($viewOrder): ?>
      <section class="panel">
        <h2>Objednávka #<?php echo (int)$viewOrder['id']; ?></h2>
        <div>Užívateľ: <?php echo htmlspecialchars($viewOrder['user_name'] ?? $viewOrder['user_email']); ?></div>
        <div>Status: <?php echo htmlspecialchars($viewOrder['status']); ?></div>
        <div>Dátum: <?php echo htmlspecialchars($viewOrder['created_at']); ?></div>

        <h3>Položky</h3>
        <table class="table">
          <thead><tr><th>#</th><th>Názov</th><th>Množ.</th><th>Jedn. cena</th><th>Spolu</th></tr></thead>
          <tbody>
            <?php $i=1; $sum=0; foreach($viewOrder['items'] as $it): $line = $it['quantity']*$it['unit_price']; $sum += $line; ?>
              <tr>
                <td><?php echo $i++; ?></td>
                <td><?php echo htmlspecialchars($it['nazov']); ?></td>
                <td><?php echo (int)$it['quantity']; ?></td>
                <td><?php echo number_format((float)$it['unit_price'],2,',','.'); ?></td>
                <td><?php echo number_format($line,2,',','.'); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <div class="total">Celkom: <?php echo number_format($sum,2,',','.').' '.htmlspecialchars($viewOrder['currency']); ?></div>
      </section>
    <?php endif; ?>

  </main>
</body>
</html>
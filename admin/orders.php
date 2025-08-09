<?php
// admin/orders.php
session_start();
require_once __DIR__ . '/../db/config/config.php';
if (!isset($_SESSION['admin_id'])) {
    header('Location: /auth/login.php?next=' . urlencode('/admin/orders.php'));
    exit;
}

$filterStatus = $_GET['status'] ?? 'all';
$search = trim($_GET['s'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$where = [];
$params = [];

if ($filterStatus !== 'all') {
    $where[] = 'o.status = :status';
    $params[':status'] = $filterStatus;
}
if ($search !== '') {
    $where[] = "(o.id = :idsearch OR u.meno LIKE :s OR u.email LIKE :s)";
    $params[':idsearch'] = is_numeric($search) ? (int)$search : 0;
    $params[':s'] = '%' . $search . '%';
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// total count
$stmt = $pdo->prepare("SELECT COUNT(*) FROM orders o LEFT JOIN users u ON o.user_id = u.id $whereSQL");
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();
$totalPages = (int)ceil($total / $perPage);

// fetch page
$sql = "SELECT o.*, u.meno AS customer_name, u.email AS customer_email FROM orders o LEFT JOIN users u ON o.user_id = u.id $whereSQL ORDER BY o.created_at DESC LIMIT :limit OFFSET :offset";
$stmt = $pdo->prepare($sql);
foreach ($params as $k=>$v) $stmt->bindValue($k, $v);
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (file_exists(__DIR__ . '/partials/header.php')) include __DIR__ . '/partials/header.php';
?>
<link rel="stylesheet" href="/admin/css/admin-orders.css">

<main class="admin-main">
  <h1>Objednávky</h1>

  <form method="get" class="admin-filter" style="margin-bottom:12px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
    <select name="status">
      <option value="all" <?= $filterStatus==='all' ? 'selected' : '' ?>>Všetky stavy</option>
      <option value="pending" <?= $filterStatus==='pending' ? 'selected' : '' ?>>Čaká na platbu</option>
      <option value="paid" <?= $filterStatus==='paid' ? 'selected' : '' ?>>Zaplatené</option>
      <option value="fulfilled" <?= $filterStatus==='fulfilled' ? 'selected' : '' ?>>Vybavené</option>
      <option value="cancelled" <?= $filterStatus==='cancelled' ? 'selected' : '' ?>>Zrušené</option>
      <option value="refunded" <?= $filterStatus==='refunded' ? 'selected' : '' ?>>Vrátené</option>
    </select>
    <input name="s" placeholder="ID, meno alebo email" value="<?= htmlspecialchars($search) ?>">
    <button type="submit" class="btn">Filtrovať</button>
  </form>

  <table class="adm-table">
    <thead><tr><th>ID</th><th>Zákazník</th><th>Suma</th><th>Stav</th><th>Platba</th><th>Dátum</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($orders as $o): ?>
        <tr>
          <td>#<?= (int)$o['id'] ?></td>
          <td><?= htmlspecialchars($o['customer_name'] ?: $o['customer_email'] ?: 'Hosť') ?></td>
          <td><?= htmlspecialchars(number_format($o['total_price'],2,',','')) ?> <?= htmlspecialchars($o['currency']) ?></td>
          <td><?= htmlspecialchars($o['status']) ?></td>
          <td><?= htmlspecialchars($o['payment_method'] ?: '-') ?></td>
          <td><?= htmlspecialchars($o['created_at']) ?></td>
          <td><a href="order-detail.php?id=<?= (int)$o['id'] ?>">Detail</a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <?php if ($totalPages > 1): ?>
    <nav class="admin-pager" style="margin-top:12px">
      <?php for ($p=1;$p<=$totalPages;$p++): 
        $qs = $_GET; $qs['page'] = $p; ?>
        <a class="page <?= $p===$page ? 'active' : '' ?>" href="?<?= http_build_query($qs) ?>"><?= $p ?></a>
      <?php endfor; ?>
    </nav>
  <?php endif; ?>
</main>

<?php if (file_exists(__DIR__ . '/partials/footer.php')) include __DIR__ . '/partials/footer.php'; ?>

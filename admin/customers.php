<?php
// admin/customers.php
session_start();
require_once __DIR__ . '/../db/config/config.php';
if (!isset($_SESSION['admin_id'])) {
    header('Location: /auth/login.php?next=' . urlencode('/admin/customers.php'));
    exit;
}

$search = trim($_GET['s'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 30;
$offset = ($page-1)*$perPage;

$where = '';
$params = [];
if ($search !== '') {
    $where = "WHERE meno LIKE :s OR email LIKE :s";
    $params[':s'] = '%'.$search.'%';
}

$total = (int)$pdo->prepare("SELECT COUNT(*) FROM users $where")->execute($params) ? (int)$pdo->prepare("SELECT COUNT(*) FROM users $where")->fetchColumn() : $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
// simpler: run prepared count properly
$stmt = $pdo->prepare("SELECT COUNT(*) FROM users $where");
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();
$totalPages = (int)ceil($total / $perPage);

$stmt = $pdo->prepare("SELECT * FROM users $where ORDER BY datum_registracie DESC LIMIT :limit OFFSET :offset");
foreach ($params as $k=>$v) $stmt->bindValue($k, $v);
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (file_exists(__DIR__ . '/partials/header.php')) include __DIR__ . '/partials/header.php';
?>
<link rel="stylesheet" href="/admin/css/admin-orders.css">

<main class="admin-main">
  <h1>Zákazníci</h1>

  <form method="get" style="margin-bottom:12px;display:flex;gap:8px;align-items:center">
    <input name="s" placeholder="Hľadať meno alebo email" value="<?= htmlspecialchars($search) ?>">
    <button class="btn" type="submit">Hľadať</button>
  </form>

  <table class="adm-table">
    <thead><tr><th>ID</th><th>Meno</th><th>Email</th><th>Registrovaný</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($users as $u): ?>
        <tr>
          <td><?= (int)$u['id'] ?></td>
          <td><?= htmlspecialchars($u['meno']) ?></td>
          <td><?= htmlspecialchars($u['email']) ?></td>
          <td><?= htmlspecialchars($u['datum_registracie']) ?></td>
          <td><a href="customer-detail.php?id=<?= (int)$u['id'] ?>">Detail</a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <?php if ($totalPages > 1): ?>
    <nav class="admin-pager" style="margin-top:12px">
      <?php for ($p=1;$p<=$totalPages;$p++): $qs = $_GET; $qs['page']=$p; ?>
        <a class="page <?= $p===$page ? 'active' : '' ?>" href="?<?= http_build_query($qs) ?>"><?= $p ?></a>
      <?php endfor; ?>
    </nav>
  <?php endif; ?>

</main>

<?php if (file_exists(__DIR__ . '/partials/footer.php')) include __DIR__ . '/partials/footer.php'; ?>

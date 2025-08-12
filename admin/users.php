<?php
// /admin/users.php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
if (!admin_is_logged()) { header('Location: login.php'); exit; }
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));

$q = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['p'] ?? 1));
$per = 30;
$offset = ($page-1)*$per;

$where = "1=1";
$params = [];
if ($q !== '') {
  $where = "(LOWER(meno) LIKE :q OR LOWER(email) LIKE :q)";
  $params[':q'] = '%'.mb_strtolower($q).'%';
}

$totalStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE $where");
$totalStmt->execute($params);
$total = (int)$totalStmt->fetchColumn();

$stmt = $pdo->prepare("SELECT id, meno, email, telefon, datum_registracie, newsletter FROM users WHERE $where ORDER BY datum_registracie DESC LIMIT :lim OFFSET :off");
foreach ($params as $k=>$v) $stmt->bindValue($k,$v);
$stmt->bindValue(':lim', $per, PDO::PARAM_INT);
$stmt->bindValue(':off', $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalPages = (int)ceil($total/$per);

?>
<!doctype html>
<html lang="sk">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Admin — Užívateľia</title>
  <link rel="stylesheet" href="/admin/css/admin.css">
  <script src="/admin/js/admin.js" defer></script>
</head>
<body>
  <main class="admin-shell">
    <header class="admin-top">
      <h1>Užívateľia</h1>
      <div class="actions">
        <a class="btn" href="user-export.php">Export CSV</a>
      </div>
    </header>

    <section class="panel">
      <form method="get" action="users.php" class="form-row">
        <div class="col"><input type="text" name="q" placeholder="Hľadať podľa mena alebo e-mailu" value="<?php echo htmlspecialchars($q); ?>"></div>
        <div><button class="btn" type="submit">Hľadať</button></div>
      </form>
    </section>

    <section>
      <?php if (!$rows): ?>
        <div class="notice">Žiadni užívatelia.</div>
      <?php else: ?>
        <table class="table">
          <thead><tr><th>#</th><th>Meno</th><th>E-mail</th><th>Telefón</th><th>Registrovaný</th><th>Newsletter</th><th>Akcie</th></tr></thead>
          <tbody>
            <?php foreach($rows as $r): ?>
              <tr>
                <td><?php echo (int)$r['id']; ?></td>
                <td><?php echo htmlspecialchars($r['meno']); ?></td>
                <td><?php echo htmlspecialchars($r['email']); ?></td>
                <td><?php echo htmlspecialchars($r['telefon']); ?></td>
                <td><?php echo htmlspecialchars($r['datum_registracie']); ?></td>
                <td><?php echo $r['newsletter'] ? 'Áno' : 'Nie'; ?></td>
                <td>
                  <a class="btn small" href="user-edit.php?id=<?php echo (int)$r['id']; ?>">Upraviť</a>
                  <form method="post" action="user-action.php" style="display:inline" data-confirm="Naozaj chcete vymazať používateľa?">
                    <input type="hidden" name="csrf" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                    <input type="hidden" name="action" value="delete">
                    <button class="btn small danger" type="submit">Vymazať</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <?php if ($totalPages>1): ?>
          <nav class="pager"><?php for($i=1;$i<=$totalPages;$i++): ?><a class="<?php echo $i===$page ? 'active' : ''; ?>" href="?p=<?php echo $i; ?>&q=<?php echo urlencode($q); ?>"><?php echo $i; ?></a><?php endfor; ?></nav>
        <?php endif; ?>

      <?php endif; ?>
    </section>
  </main>
</body>
</html>
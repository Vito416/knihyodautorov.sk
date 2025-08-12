<?php
// admin/users.php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();

// načítame bootstrap (nesmie sa nahradiť)
require __DIR__ . '/bootstrap.php';
require_admin(); // ak nie je prihlásený, presmeruje na login

$me = admin_user($pdo); // info o adminovi

// Pomocné
function esc_out($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

// Export CSV
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="users_export_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF"); // BOM pre Excel UTF-8

    // hlavička
    fputcsv($out, ['ID','Meno','E-mail','Telefón','Registrovaný','Posledné prihlásenie','Newsletter','Email overený']);
    $stmt = $pdo->query("SELECT id, meno, email, telefon, COALESCE(adresa,'') AS adresa, datum_registracie, last_login, COALESCE(newsletter,0) AS newsletter, COALESCE(email_verified,0) AS email_verified FROM users ORDER BY id DESC");
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($out, [
            $r['id'],
            $r['meno'],
            $r['email'],
            $r['telefon'],
            $r['datum_registracie'],
            $r['last_login'],
            $r['newsletter'] ? 'Áno' : 'Nie',
            $r['email_verified'] ? 'Áno' : 'Nie'
        ]);
    }
    fclose($out);
    exit;
}

// základné stránkovanie / filter
$perPage = 20;
$page = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;
$offset = ($page - 1) * $perPage;

$search = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$where = "WHERE 1=1";
$params = [];

if ($search !== '') {
    $where .= " AND (LOWER(meno) LIKE :t OR LOWER(email) LIKE :t)";
    $params[':t'] = '%' . mb_strtolower($search, 'UTF-8') . '%';
}

// načítame počet
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM users $where");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$pages = (int)ceil($total / $perPage);

// načítame užívateľov
$sql = "SELECT id, meno, email, telefon, COALESCE(adresa,'') AS adresa, datum_registracie, last_login, COALESCE(newsletter,0) AS newsletter, COALESCE(email_verified,0) AS email_verified FROM users $where ORDER BY id DESC LIMIT :lim OFFSET :off";
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) $stmt->bindValue($k, $v, PDO::PARAM_STR);
$stmt->bindValue(':lim', (int)$perPage, PDO::PARAM_INT);
$stmt->bindValue(':off', (int)$offset, PDO::PARAM_INT);
$stmt->execute();
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// header + css (uistite sa, že /admin/css/admin.css existuje)
?><!doctype html>
<html lang="sk">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Administrácia — Užívateľia</title>
  <link rel="stylesheet" href="/admin/css/admin.css">
  <script defer src="/admin/js/admin.js"></script>
</head>
<body class="admin-ui">
<?php include __DIR__ . '/partials/header.php'; ?>

<main class="admin-main container">
  <header class="admin-page-header">
    <h1>Užívatelia</h1>
    <div class="admin-page-actions">
      <form method="get" class="search-form" action="/admin/users.php" aria-label="Hľadať užívateľov">
        <input id="q" name="q" value="<?php echo esc_out($search); ?>" placeholder="Hľadať podľa mena alebo emailu..." />
        <button type="submit" class="btn">Hľadať</button>
        <a class="btn btn-ghost" href="/admin/users.php">Zrušiť</a>
      </form>
      <a class="btn btn-primary" href="/admin/users.php?export=csv">Export CSV</a>
    </div>
  </header>

  <section class="admin-card">
    <div class="meta">
      <p>Celkový počet užívateľov: <strong><?php echo $total; ?></strong></p>
      <p>Strana <?php echo $page; ?> / <?php echo max(1,$pages); ?></p>
    </div>

    <table class="table">
      <thead>
        <tr>
          <th>ID</th>
          <th>Meno</th>
          <th>E-mail</th>
          <th>Telefón</th>
          <th>Registrovaný</th>
          <th>Posledné prihlásenie</th>
          <th>Newsletter</th>
          <th>Email overený</th>
          <th>Akcie</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($users)): ?>
          <tr><td colspan="9">Žiadni užívatelia.</td></tr>
        <?php else: foreach ($users as $u): ?>
          <tr>
            <td><?php echo (int)$u['id']; ?></td>
            <td><?php echo esc_out($u['meno']); ?></td>
            <td><?php echo esc_out($u['email']); ?></td>
            <td><?php echo esc_out($u['telefon']); ?></td>
            <td><?php echo esc_out($u['datum_registracie']); ?></td>
            <td><?php echo esc_out($u['last_login']); ?></td>
            <td><?php echo $u['newsletter'] ? 'Áno' : 'Nie'; ?></td>
            <td><?php echo $u['email_verified'] ? 'Áno' : 'Nie'; ?></td>
            <td>
              <a class="btn-small" href="/admin/user-edit.php?id=<?php echo (int)$u['id']; ?>">Upraviť</a>
              <a class="btn-small btn-danger" href="/admin/user-delete.php?id=<?php echo (int)$u['id']; ?>" onclick="return confirm('Naozaj zmazať užívateľa?');">Zmazať</a>
              <a class="btn-small" href="/admin/user-downloads.php?id=<?php echo (int)$u['id']; ?>">Stiahnutia</a>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>

    <nav class="pagination">
      <?php if ($page > 1): ?>
        <a class="page" href="?p=<?php echo $page-1; ?>&q=<?php echo urlencode($search); ?>">&laquo; Predchádzajúca</a>
      <?php endif; ?>
      <?php for ($i = 1; $i <= max(1,$pages); $i++): ?>
        <a class="page<?php echo $i== $page ? ' active' : ''; ?>" href="?p=<?php echo $i; ?>&q=<?php echo urlencode($search); ?>"><?php echo $i; ?></a>
      <?php endfor; ?>
      <?php if ($page < $pages): ?>
        <a class="page" href="?p=<?php echo $page+1; ?>&q=<?php echo urlencode($search); ?>">Ďalšia &raquo;</a>
      <?php endif; ?>
    </nav>

  </section>
</main>

<?php include __DIR__ . '/partials/footer.php'; ?>
</body>
</html>
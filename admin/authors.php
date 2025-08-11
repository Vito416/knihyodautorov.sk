<?php
// /admin/authors.php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/inc/mail.php'; // mail helper len pre istotu (nie je nutný tu)

if (!admin_is_logged()) { header('Location: login.php'); exit; }

// CSRF token
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));

$perPage = 20;
$page = max(1, (int)($_GET['p'] ?? 1));
$offset = ($page - 1) * $perPage;

// search
$q = trim((string)($_GET['q'] ?? ''));

$params = [];
$where = "1=1";
if ($q !== '') {
    $where = "(LOWER(meno) LIKE :q OR LOWER(bio) LIKE :q)";
    $params[':q'] = '%' . mb_strtolower($q) . '%';
}

$totalStmt = $pdo->prepare("SELECT COUNT(*) FROM authors WHERE $where");
$totalStmt->execute($params);
$total = (int)$totalStmt->fetchColumn();

$rowsStmt = $pdo->prepare("SELECT id, meno, slug, foto, created_at FROM authors WHERE $where ORDER BY created_at DESC LIMIT :lim OFFSET :off");
foreach ($params as $k=>$v) $rowsStmt->bindValue($k,$v);
$rowsStmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
$rowsStmt->bindValue(':off', $offset, PDO::PARAM_INT);
$rowsStmt->execute();
$authors = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);

// simple pager
$totalPages = (int)ceil($total / $perPage);

?><!doctype html>
<html lang="sk">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Admin — Autori</title>
  <link rel="stylesheet" href="/admin/css/admin.css">
  <script src="/admin/js/admin.js" defer></script>
</head>
<body class="admin-authors">
  <main class="admin-shell">
    <header class="admin-top">
      <h1>Autori</h1>
      <div class="actions">
        <a class="btn" href="author-edit.php">Pridať autora</a>
      </div>
    </header>

    <section class="admin-search">
      <form method="get" action="authors.php">
        <input name="q" placeholder="Hľadaj autora alebo popis..." value="<?php echo htmlspecialchars($q, ENT_QUOTES); ?>">
        <button type="submit">Hľadať</button>
      </form>
    </section>

    <section class="admin-list">
      <?php if (!$authors): ?>
        <div class="notice">Žiadni autori.</div>
      <?php else: ?>
        <div class="grid">
          <?php foreach ($authors as $a): ?>
            <article class="author-card">
              <div class="author-photo">
                <img src="<?php echo $a['foto'] ? '/assets/authors/' . htmlspecialchars($a['foto']) : '/assets/author-placeholder.png'; ?>" alt="<?php echo htmlspecialchars($a['meno']); ?>">
              </div>
              <div class="author-meta">
                <h3><?php echo htmlspecialchars($a['meno']); ?></h3>
                <div class="muted">Pridané: <?php echo htmlspecialchars($a['created_at'] ?? '—'); ?></div>
                <div class="author-actions">
                  <a class="btn small" href="author-edit.php?id=<?php echo (int)$a['id']; ?>">Upraviť</a>
                  <form method="post" action="author-delete.php" onsubmit="return confirm('Naozaj odstrániť autora?');" style="display:inline">
                    <input type="hidden" name="id" value="<?php echo (int)$a['id']; ?>">
                    <input type="hidden" name="csrf" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <button class="btn small danger" type="submit">Vymazať</button>
                  </form>
                </div>
              </div>
            </article>
          <?php endforeach; ?>
        </div>

        <?php if ($totalPages > 1): ?>
          <nav class="pager">
            <?php for ($i=1;$i<=$totalPages;$i++): ?>
              <a class="<?php echo $i===$page ? 'active' : ''; ?>" href="?p=<?php echo $i; ?><?php if($q) echo '&q='.urlencode($q); ?>"><?php echo $i; ?></a>
            <?php endfor; ?>
          </nav>
        <?php endif; ?>

      <?php endif; ?>
    </section>
  </main>
</body>
</html>
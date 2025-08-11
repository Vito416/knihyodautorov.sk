<?php
// /admin/reviews.php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
if (!admin_is_logged()) { header('Location: login.php'); exit; }
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));

$perPage = 30;
$page = max(1,(int)($_GET['p'] ?? 1));
$offset = ($page-1)*$perPage;

$totalStmt = $pdo->query("SELECT COUNT(*) FROM reviews");
$total = (int)$totalStmt->fetchColumn();

$stmt = $pdo->prepare("SELECT r.*, u.meno as user_name, b.nazov as book_name FROM reviews r LEFT JOIN users u ON r.user_id=u.id LEFT JOIN books b ON r.book_id=b.id ORDER BY r.created_at DESC LIMIT :lim OFFSET :off");
$stmt->bindValue(':lim',$perPage,PDO::PARAM_INT);
$stmt->bindValue(':off',$offset,PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalPages = (int)ceil($total/$perPage);
?>
<!doctype html>
<html lang="sk">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Admin — Recenzie</title>
  <link rel="stylesheet" href="/admin/css/admin.css">
</head>
<body class="admin-reviews">
  <main class="admin-shell">
    <header class="admin-top">
      <h1>Recenzie</h1>
      <div class="actions">
        <a class="btn ghost" href="reviews-export.php">Export CSV</a>
      </div>
    </header>

    <section>
      <?php if (!$rows): ?><div class="notice">Žiadne recenzie.</div><?php else: ?>
        <table class="table">
          <thead><tr><th>#</th><th>Kniha</th><th>Užívateľ</th><th>Hodnotenie</th><th>Text</th><th>Dátum</th><th>Akcie</th></tr></thead>
          <tbody>
            <?php foreach($rows as $r): ?>
              <tr>
                <td><?php echo (int)$r['id']; ?></td>
                <td><?php echo htmlspecialchars($r['book_name']); ?></td>
                <td><?php echo htmlspecialchars($r['user_name'] ?? 'Anonym'); ?></td>
                <td><?php echo (int)$r['rating']; ?>/5</td>
                <td><?php echo nl2br(htmlspecialchars($r['comment'])); ?></td>
                <td><?php echo htmlspecialchars($r['created_at']); ?></td>
                <td>
                  <form method="post" action="review-action.php" style="display:inline">
                    <input type="hidden" name="csrf" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                    <input type="hidden" name="action" value="approve">
                    <button class="btn small" type="submit">Schváliť</button>
                  </form>
                  <form method="post" action="review-action.php" style="display:inline" onsubmit="return confirm('Naozaj odstrániť recenziu?');">
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
          <nav class="pager"><?php for($i=1;$i<=$totalPages;$i++): ?><a class="<?php echo $i===$page ? 'active' : ''; ?>" href="?p=<?php echo $i; ?>"><?php echo $i; ?></a><?php endfor; ?></nav>
        <?php endif; ?>

      <?php endif; ?>
    </section>

  </main>
</body>
</html>
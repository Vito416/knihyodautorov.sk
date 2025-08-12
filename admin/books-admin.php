<?php
// /admin/books-admin.php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
if (!admin_is_logged()) { header('Location: login.php'); exit; }

$page = max(1,(int)($_GET['p'] ?? 1));
$per = 30; $off = ($page-1)*$per;

$total = (int)$pdo->query("SELECT COUNT(*) FROM books")->fetchColumn();
$stmt = $pdo->prepare("SELECT b.*, a.meno as author_name, c.nazov as category_name FROM books b LEFT JOIN authors a ON b.author_id=a.id LEFT JOIN categories c ON b.category_id=c.id ORDER BY b.created_at DESC LIMIT :lim OFFSET :off");
$stmt->bindValue(':lim',$per,PDO::PARAM_INT); $stmt->bindValue(':off',$off,PDO::PARAM_INT);
$stmt->execute(); $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$totalPages = (int)ceil($total/$per);
?>
<!doctype html>
<html lang="sk">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin — Knihy</title>
<link rel="stylesheet" href="/admin/css/admin.css">
<script src="/admin/js/admin.js" defer></script>
</head>
<body>
<main class="admin-shell">
  <header class="admin-top">
    <h1>Knihy</h1>
    <div class="actions">
      <a class="btn" href="book-edit.php">Pridať knihu</a>
      <a class="btn ghost" href="export-books.php">Export CSV</a>
    </div>
  </header>

  <section>
    <table class="table">
      <thead><tr><th>#</th><th>Obrázok</th><th>Názov</th><th>Autor</th><th>Kategória</th><th>Cena</th><th>Aktívna</th><th>Akcie</th></tr></thead>
      <tbody>
        <?php foreach($rows as $r): ?>
          <tr>
            <td><?php echo (int)$r['id']; ?></td>
            <td style="width:110px;">
              <img src="<?php echo $r['obrazok'] ? '/books-img/'.htmlspecialchars($r['obrazok']) : '/assets/books-placeholder.png'; ?>" alt="" style="width:88px;height:60px;object-fit:cover;border-radius:6px;">
            </td>
            <td><?php echo htmlspecialchars($r['nazov']); ?></td>
            <td><?php echo htmlspecialchars($r['author_name']); ?></td>
            <td><?php echo htmlspecialchars($r['category_name']); ?></td>
            <td><?php echo number_format((float)$r['cena'],2,',','.').' '.$r['mena']; ?></td>
            <td><?php echo $r['is_active'] ? 'Áno' : 'Nie'; ?></td>
            <td>
              <a class="btn small" href="book-edit.php?id=<?php echo (int)$r['id']; ?>">Upraviť</a>
              <form method="post" action="book-action.php" style="display:inline" data-confirm="Vymazať knihu?">
                <input type="hidden" name="csrf" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
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
  </section>
</main>
</body>
</html>
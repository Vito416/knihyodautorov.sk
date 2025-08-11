<?php
// /admin/categories.php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
if (!admin_is_logged()) { header('Location: login.php'); exit; }

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));

$rows = $pdo->query("SELECT id, nazov, slug, created_at FROM categories ORDER BY nazov ASC")->fetchAll(PDO::FETCH_ASSOC);
?><!doctype html>
<html lang="sk">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin — Kategórie</title>
<link rel="stylesheet" href="/admin/css/admin.css">
</head>
<body class="admin-categories">
  <main class="admin-shell">
    <header class="admin-top">
      <h1>Kategórie</h1>
      <a class="btn" href="category-edit.php">Pridať kategóriu</a>
    </header>

    <section class="list">
      <?php if (!$rows): ?>
        <div class="notice">Žiadne kategórie.</div>
      <?php else: ?>
        <table class="table">
          <thead><tr><th>Názov</th><th>Slug</th><th>Vytvorené</th><th>Akcie</th></tr></thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td><?php echo htmlspecialchars($r['nazov']); ?></td>
                <td><?php echo htmlspecialchars($r['slug']); ?></td>
                <td><?php echo htmlspecialchars($r['created_at']); ?></td>
                <td>
                  <a class="btn small" href="category-edit.php?id=<?php echo (int)$r['id']; ?>">Upraviť</a>
                  <form method="post" action="category-delete.php" style="display:inline" onsubmit="return confirm('Naozaj odstrániť kategóriu?');">
                    <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                    <input type="hidden" name="csrf" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <button class="btn small danger" type="submit">Vymazať</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </section>
  </main>
</body>
</html>
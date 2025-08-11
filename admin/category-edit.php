<?php
// /admin/category-edit.php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
if (!admin_is_logged()) { header('Location: login.php'); exit; }

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$cat = null;
if ($id > 0) {
    $cat = $pdo->prepare("SELECT id, nazov, slug FROM categories WHERE id = ? LIMIT 1");
    $cat->execute([$id]);
    $cat = $cat->fetch(PDO::FETCH_ASSOC);
    if (!$cat) { echo "Kategória nenájdená."; exit; }
}
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
?>
<!doctype html>
<html lang="sk"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php echo $cat ? 'Upraviť kategóriu' : 'Pridať kategóriu'; ?></title><link rel="stylesheet" href="/admin/css/admin.css"></head>
<body>
  <main class="admin-shell">
    <h1><?php echo $cat ? 'Upraviť kategóriu' : 'Pridať kategóriu'; ?></h1>
    <form action="category-save.php" method="post">
      <input type="hidden" name="id" value="<?php echo (int)($cat['id'] ?? 0); ?>">
      <input type="hidden" name="csrf" value="<?php echo $_SESSION['csrf_token']; ?>">
      <label>Názov
        <input name="nazov" required value="<?php echo htmlspecialchars($cat['nazov'] ?? '', ENT_QUOTES); ?>">
      </label>
      <label>Slug (voliteľné)
        <input name="slug" value="<?php echo htmlspecialchars($cat['slug'] ?? '', ENT_QUOTES); ?>">
      </label>
      <div class="form-actions">
        <button class="btn" type="submit">Uložiť</button>
        <a class="btn ghost" href="categories.php">Zrušiť</a>
      </div>
    </form>
  </main>
</body>
</html>
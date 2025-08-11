<?php
// /admin/author-edit.php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
if (!admin_is_logged()) { header('Location: login.php'); exit; }

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$author = null;
if ($id > 0) {
    $stmt = $pdo->prepare("SELECT id, meno, slug, bio, foto FROM authors WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $author = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$author) { http_response_code(404); echo "Autor nenájdený."; exit; }
}

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
?>
<!doctype html>
<html lang="sk">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo $author ? 'Upraviť autora' : 'Pridať autora'; ?></title>
<link rel="stylesheet" href="/admin/css/admin.css">
</head>
<body class="admin-author-edit">
  <main class="admin-shell">
    <h1><?php echo $author ? 'Upraviť autora' : 'Pridať autora'; ?></h1>
    <form action="author-save.php" method="post" enctype="multipart/form-data">
      <input type="hidden" name="id" value="<?php echo (int)($author['id'] ?? 0); ?>">
      <input type="hidden" name="csrf" value="<?php echo $_SESSION['csrf_token']; ?>">

      <label>Meno autora
        <input name="meno" required value="<?php echo htmlspecialchars($author['meno'] ?? '', ENT_QUOTES); ?>">
      </label>

      <label>Slug (voliteľné)
        <input name="slug" value="<?php echo htmlspecialchars($author['slug'] ?? '', ENT_QUOTES); ?>">
        <small>Ak necháte prázdne, slug sa vygeneruje z mena.</small>
      </label>

      <label>Krátka bio
        <textarea name="bio" rows="6"><?php echo htmlspecialchars($author['bio'] ?? '', ENT_QUOTES); ?></textarea>
      </label>

      <label>Fotografia autora (jpg/png) — max 3MB
        <input type="file" name="foto" accept="image/jpeg,image/png">
      </label>

      <?php if (!empty($author['foto'])): ?>
        <div class="current-photo">
          <img src="/assets/authors/<?php echo htmlspecialchars($author['foto']); ?>" alt="">
          <label><input type="checkbox" name="remove_photo" value="1"> Odstrániť starú fotografiu</label>
        </div>
      <?php endif; ?>

      <div class="form-actions">
        <button class="btn" type="submit">Uložiť</button>
        <a class="btn ghost" href="authors.php">Zrušiť</a>
      </div>
    </form>
  </main>
</body>
</html>
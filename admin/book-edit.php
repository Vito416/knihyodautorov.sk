<?php
// /admin/book-edit.php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
if (!admin_is_logged()) { header('Location: login.php'); exit; }
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));

$id = (int)($_GET['id'] ?? 0);
$book = null;
if ($id) {
  $stmt = $pdo->prepare("SELECT * FROM books WHERE id = ? LIMIT 1");
  $stmt->execute([$id]);
  $book = $stmt->fetch(PDO::FETCH_ASSOC);
}

$authors = $pdo->query("SELECT id, meno FROM authors ORDER BY meno")->fetchAll(PDO::FETCH_ASSOC);
$cats = $pdo->query("SELECT id, nazov FROM categories ORDER BY nazov")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="sk">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin — Upraviť knihu</title>
<link rel="stylesheet" href="/admin/css/admin.css">
</head>
<body>
<main class="admin-shell">
  <header class="admin-top"><h1><?php echo $book ? 'Upraviť knihu' : 'Pridať knihu'; ?></h1></header>

  <form method="post" action="book-action.php" class="panel" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?php echo $_SESSION['csrf_token']; ?>">
    <input type="hidden" name="id" value="<?php echo (int)$id; ?>">

    <div class="form-row">
      <div class="col"><label>Názov</label><input type="text" name="nazov" required value="<?php echo $book ? htmlspecialchars($book['nazov']):''; ?>"></div>
    </div>

    <div class="form-row" style="margin-top:12px;">
      <div class="col">
        <label>Autor</label>
        <select name="author_id">
          <option value="">— vybrať —</option>
          <?php foreach($authors as $a): ?>
            <option value="<?php echo (int)$a['id']; ?>" <?php if($book && $book['author_id']==$a['id']) echo 'selected'; ?>><?php echo htmlspecialchars($a['meno']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col">
        <label>Kategória</label>
        <select name="category_id">
          <option value="">— vybrať —</option>
          <?php foreach($cats as $c): ?>
            <option value="<?php echo (int)$c['id']; ?>" <?php if($book && $book['category_id']==$c['id']) echo 'selected'; ?>><?php echo htmlspecialchars($c['nazov']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div style="margin-top:12px;">
      <label>Krátky popis</label>
      <textarea name="popis" rows="6"><?php echo $book ? htmlspecialchars($book['popis']):''; ?></textarea>
    </div>

    <div class="form-row" style="margin-top:12px;">
      <div class="col"><label>Cena (bez meny)</label><input type="text" name="cena" value="<?php echo $book ? htmlspecialchars($book['cena']):'0.00'; ?>"></div>
      <div class="col"><label>Mena</label><input type="text" name="mena" value="<?php echo $book ? htmlspecialchars($book['mena']):'EUR'; ?>"></div>
    </div>

    <div style="margin-top:12px;">
      <label>Obrázok (názov súboru v /books-img)</label>
      <input type="text" name="obrazok" value="<?php echo $book ? htmlspecialchars($book['obrazok']):''; ?>">
    </div>

    <div style="margin-top:12px;">
      <label>PDF (názov súboru v /books-pdf)</label>
      <input type="text" name="pdf_file" value="<?php echo $book ? htmlspecialchars($book['pdf_file']):''; ?>">
    </div>

    <div style="margin-top:12px;">
      <label><input type="checkbox" name="is_active" value="1" <?php if(!$book || $book['is_active']) echo 'checked'; ?>> Aktívna</label>
    </div>

    <div style="margin-top:14px;">
      <button class="btn" type="submit">Uložiť zmeny</button>
      <a class="btn ghost" href="books-admin.php">Zrušiť</a>
    </div>
  </form>
</main>
</body>
</html>
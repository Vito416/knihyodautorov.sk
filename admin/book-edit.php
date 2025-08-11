<?php
// /admin/book-edit.php
require_once __DIR__ . '/bootstrap.php';
require_admin();
require_once __DIR__ . '/inc/helpers.php';
require_once __DIR__ . '/inc/csrf.php';
require_once __DIR__ . '/inc/upload.php';

$id = (int)($_GET['id'] ?? 0);
$book = null;
if ($id) {
    $stmt = $pdo->prepare("SELECT * FROM books WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $book = $stmt->fetch(PDO::FETCH_ASSOC);
}
$authors = $pdo->query("SELECT id, meno FROM authors ORDER BY meno")->fetchAll(PDO::FETCH_ASSOC);
$categories = $pdo->query("SELECT id, nazov FROM categories ORDER BY nazov")->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/header.php';
?>
<section class="adm-section">
  <h1><?= $id ? 'Upraviť knihu' : 'Pridať knihu' ?></h1>

  <form method="post" action="/admin/book-save.php" enctype="multipart/form-data" class="adm-form">
    <input type="hidden" name="csrf" value="<?= adm_esc(csrf_get_token()) ?>">
    <input type="hidden" name="id" value="<?= adm_esc($book['id'] ?? '') ?>">
    <label>Názov</label>
    <input name="nazov" type="text" value="<?= adm_esc($book['nazov'] ?? '') ?>" required>
    <label>Autor</label>
    <select name="author_id">
      <option value="">—</option>
      <?php foreach ($authors as $a): ?>
        <option value="<?= adm_esc($a['id']) ?>" <?= (isset($book['author_id']) && $book['author_id']==$a['id']) ? 'selected' : '' ?>><?= adm_esc($a['meno']) ?></option>
      <?php endforeach; ?>
    </select>
    <label>Kategória</label>
    <select name="category_id">
      <option value="">—</option>
      <?php foreach ($categories as $c): ?>
        <option value="<?= adm_esc($c['id']) ?>" <?= (isset($book['category_id']) && $book['category_id']==$c['id']) ? 'selected' : '' ?>><?= adm_esc($c['nazov']) ?></option>
      <?php endforeach; ?>
    </select>
    <label>Cena</label>
    <input name="cena" type="text" value="<?= adm_esc($book['cena'] ?? '0.00') ?>">
    <label>PDF súbor (názov) — ak nahrávate, nahrajte do /books-pdf/</label>
    <input name="pdf_file" type="text" value="<?= adm_esc($book['pdf_file'] ?? '') ?>">
    <label>Obrázok (png/jpg/webp)</label>
    <input name="cover" type="file" accept="image/*">
    <?php if (!empty($book['obrazok'])): ?>
      <div class="muted">Aktuálny: <?= adm_esc($book['obrazok']) ?></div>
    <?php endif; ?>

    <label>Popis</label>
    <textarea name="popis"><?= adm_esc($book['popis'] ?? '') ?></textarea>

    <div class="adm-form-actions">
      <button class="adm-btn adm-btn-primary" type="submit">Uložiť</button>
      <a href="/admin/books.php" class="adm-btn">Späť</a>
    </div>
  </form>
</section>

<?php include __DIR__ . '/footer.php'; ?>

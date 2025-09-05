<?php
require __DIR__ . '/../../inc/bootstrap.php';
$id = (int)($_GET['id'] ?? 0);
$editing = $id>0;
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::validate($_POST['csrf_token'] ?? '')) { $err = 'CSRF token neplatný'; }
    else {
        $title = trim($_POST['title'] ?? '');
        $slug = trim($_POST['slug'] ?? '');
        $price = (float)($_POST['price'] ?? 0);
        $author_id = (int)($_POST['author_id'] ?? 0);
        $desc = $_POST['description'] ?? '';
        $is_active = isset($_POST['is_active']) ? 1:0;
        if ($title==='') $err='Titul je povinný';
        else {
            if ($editing) {
                $stmt = $db->prepare('UPDATE books SET title=?, slug=?, description=?, price=?, author_id=?, is_active=?, updated_at=NOW() WHERE id=?');
                $stmt->execute([$title,$slug,$desc,$price,$author_id,$is_active,$id]);
                header('Location: books.php'); exit;
            } else {
                $stmt = $db->prepare('INSERT INTO books (title,slug,description,price,author_id,is_active,created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())');
                $stmt->execute([$title,$slug,$desc,$price,$author_id,$is_active]);
                $newid = $db->lastInsertId();
                header('Location: book_form.php?id='.$newid); exit;
            }
        }
    }
}
$authors = $db->query('SELECT id, meno FROM authors ORDER BY meno')->fetchAll();
$book = ['title'=>'','slug'=>'','description'=>'','price'=>0,'author_id'=>0,'is_active'=>1];
if ($editing) {
    $stmt = $db->prepare('SELECT * FROM books WHERE id=? LIMIT 1'); $stmt->execute([$id]); $book = $stmt->fetch();
    if (!$book) { http_response_code(404); echo 'Kniha nenájdená'; exit; }
}
?>
<!doctype html><html lang="sk"><head><meta charset="utf-8"><title><?= $editing ? 'Upraviť knihu':'Pridať knihu'?></title><link rel="stylesheet" href="/eshop/assets/css/base.css"></head><body>
<?php include __DIR__ . '/../templates/admin-header.php'; ?>
<main>
  <h1><?= $editing ? 'Upraviť knihu':'Pridať knihu'?></h1>
  <?php if ($err) echo '<p class="error">'.e($err).'</p>'; ?>
  <form method="post" enctype="multipart/form-data" action="">
    <label>Titul<br><input name="title" required value="<?=e($book['title'])?>"></label><br>
    <label>Slug<br><input name="slug" value="<?=e($book['slug'])?>"></label><br>
    <label>Autor<br>
      <select name="author_id">
        <option value="0">-- vyberte --</option>
        <?php foreach($authors as $a): ?>
          <option value="<?=e($a['id'])?>" <?=($a['id']==$book['author_id']?'selected':'')?>><?=e($a['meno'])?></option>
        <?php endforeach;?>
      </select>
    </label><br>
    <label>Cena (EUR)<br><input name="price" type="number" step="0.01" value="<?=e($book['price'])?>"></label><br>
    <label>Popis<br><textarea name="description"><?=e($book['description'])?></textarea></label><br>
    <label>Aktívna <input type="checkbox" name="is_active" <?=($book['is_active']?'checked':'')?>></label><br>
    <p>Nahranie súborov (obálka, PDF):</p>
    <label>Obálka (jpg/png) <input type="file" name="cover"></label><br>
    <label>PDF súbor <input type="file" name="pdf"></label><br>
    <input type="hidden" name="csrf_token" value="<?=e(CSRF::token())?>">
    <button type="submit">Uložiť</button>
  </form>

  <?php if ($editing): ?>
    <h2>Správa súborov</h2>
    <form method="post" action="upload_asset.php" enctype="multipart/form-data">
      <input type="hidden" name="book_id" value="<?=e($id)?>">
      <label>Typ súboru
        <select name="asset_type">
          <option value="cover">cover</option>
          <option value="pdf">pdf</option>
          <option value="sample">sample</option>
        </select>
      </label><br>
      <label>Vybrať súbor <input type="file" name="file" required></label><br>
      <input type="hidden" name="csrf_token" value="<?=e(CSRF::token())?>">
      <button type="submit">Nahrať</button>
    </form>
  <?php endif; ?>
</main>
</body></html>
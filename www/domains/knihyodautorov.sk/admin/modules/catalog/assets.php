<?php
require __DIR__ . '/../../inc/bootstrap.php';
$book_id = (int)($_GET['book_id'] ?? 0);
if (!$book_id) { echo 'Chýba book_id'; exit; }
$stmt = $db->prepare('SELECT * FROM book_assets WHERE book_id = ? ORDER BY created_at DESC');
$stmt->execute([$book_id]); $assets = $stmt->fetchAll();
?><!doctype html><html lang="sk"><head><meta charset="utf-8"><title>Súbory knihy</title></head><body>
<?php include __DIR__ . '/../templates/admin-header.php'; ?>
<main>
  <h1>Súbory pre knihu #<?=e($book_id)?></h1>
  <table border="1"><tr><th>ID</th><th>Typ</th><th>Originálny názov</th><th>Veľkosť</th><th>Encrypted</th><th>Akcie</th></tr>
    <?php foreach($assets as $a): ?>
      <tr>
        <td><?=e($a['id'])?></td>
        <td><?=e($a['asset_type'])?></td>
        <td><?=e($a['filename'])?></td>
        <td><?=number_format($a['size_bytes']/1024,2,',',' ')?> KB</td>
        <td><?=($a['is_encrypted'] ? 'Yes':'No')?></td>
        <td>
          <a href="asset_delete.php?id=<?=urlencode($a['id'])?>&book_id=<?=urlencode($book_id)?>" onclick="return confirm('Vymazať asset?')">Vymazať</a>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
</main></body></html>
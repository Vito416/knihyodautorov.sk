<?php
require __DIR__ . '/../../inc/bootstrap.php';
$stmt = $db->query('SELECT b.id, b.title, b.slug, b.price, b.is_active, a.meno AS author FROM books b LEFT JOIN authors a ON a.id=b.author_id ORDER BY b.created_at DESC');
$rows = $stmt->fetchAll();
?><!doctype html><html lang="sk"><head><meta charset="utf-8"><title>Správa kníh</title><link rel="stylesheet" href="/eshop/assets/css/base.css"></head><body>
<?php include __DIR__ . '/../templates/admin-header.php'; ?>
<main>
  <h1>Správa kníh</h1>
  <p><a href="book_form.php">Pridať novú knihu</a></p>
  <table border="1" cellpadding="6">
    <tr><th>ID</th><th>Titul</th><th>Autor</th><th>Cena</th><th>Aktívna</th><th>Akcie</th></tr>
    <?php foreach($rows as $r): ?>
      <tr>
        <td><?=e($r['id'])?></td>
        <td><?=e($r['title'])?></td>
        <td><?=e($r['author'])?></td>
        <td><?=number_format($r['price'],2,',',' ')?> €</td>
        <td><?=($r['is_active'] ? 'Áno':'Nie')?></td>
        <td>
          <a href="book_form.php?id=<?=urlencode($r['id'])?>">Upraviť</a> |
          <a href="book_delete.php?id=<?=urlencode($r['id'])?>" onclick="return confirm('Naozaj zmazať?')">Vymazať</a>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
</main>
</body></html>
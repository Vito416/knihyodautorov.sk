<?php
// admin/modules/rbac/roles.php
require __DIR__ . '/../../inc/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::validate($_POST['csrf_token'] ?? '')) { die('CSRF'); }
    $name = trim($_POST['name'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    if ($name !== '') {
        $stmt = $db->prepare('INSERT INTO roles (nazov, popis, created_at) VALUES (?, ?, NOW())');
        $stmt->execute([$name, $desc]);
        header('Location: roles.php'); exit;
    }
}
$roles = $db->query('SELECT id, nazov, popis, created_at FROM roles ORDER BY id DESC')->fetchAll();
?>
<!doctype html><html lang="sk"><head><meta charset="utf-8"><title>Role</title></head><body>
<?php include __DIR__ . '/../templates/admin-header.php'; ?>
<main>
  <h1>Role</h1>
  <section>
    <h2>Vytvoriť rolu</h2>
    <form method="post">
      <label>Názov role<input name="name" required></label><br>
      <label>Popis<textarea name="description"></textarea></label><br>
      <input type="hidden" name="csrf_token" value="<?=e(CSRF::token())?>">
      <button type="submit">Vytvoriť</button>
    </form>
  </section>

  <section>
    <h2>Zoznam rolí</h2>
    <table border="1" cellpadding="6"><tr><th>ID</th><th>Názov</th><th>Popis</th><th>Vytvorená</th><th>Akcie</th></tr>
      <?php foreach($roles as $r): ?>
        <tr>
          <td><?=e($r['id'])?></td>
          <td><?=e($r['nazov'])?></td>
          <td><?=e($r['popis'])?></td>
          <td><?=e($r['created_at'])?></td>
          <td>
            <a href="role_edit.php?id=<?=e($r['id'])?>">Upraviť</a> |
            <a href="role_delete.php?id=<?=e($r['id'])?>" onclick="return confirm('Vymazať rolu?')">Vymazať</a>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  </section>
</main>
</body></html>
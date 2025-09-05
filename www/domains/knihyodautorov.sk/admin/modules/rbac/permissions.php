<?php
// admin/modules/rbac/permissions.php
require __DIR__ . '/../../inc/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::validate($_POST['csrf_token'] ?? '')) die('CSRF');
    $code = trim($_POST['code'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    if ($code !== '') {
        $db->prepare('INSERT INTO permissions (code, description, created_at) VALUES (?, ?, NOW())')->execute([$code, $desc]);
        header('Location: permissions.php'); exit;
    }
}
$perms = $db->query('SELECT id, code, description, created_at FROM permissions ORDER BY id DESC')->fetchAll();
$roles = $db->query('SELECT id, nazov FROM roles ORDER BY nazov')->fetchAll();
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['assign'])) {
    // simple assign permission to role
    $perm_id = (int)($_GET['perm_id'] ?? 0);
    $role_id = (int)($_GET['role_id'] ?? 0);
    if ($perm_id && $role_id) {
        $db->prepare('INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)')->execute([$role_id,$perm_id]);
        header('Location: permissions.php'); exit;
    }
}
?>
<!doctype html><html lang="sk"><head><meta charset="utf-8"><title>Oprávnenia</title></head><body>
<?php include __DIR__ . '/../templates/admin-header.php'; ?>
<main>
  <h1>Oprávnenia</h1>
  <section>
    <h2>Nové oprávnenie</h2>
    <form method="post">
      <label>Kód (napr. manage_users)<input name="code" required></label><br>
      <label>Popis<textarea name="description"></textarea></label><br>
      <input type="hidden" name="csrf_token" value="<?=e(CSRF::token())?>">
      <button type="submit">Vytvoriť</button>
    </form>
  </section>

  <section>
    <h2>Priradiť oprávnenie role</h2>
    <form method="get">
      <label>Oprávnenie<select name="perm_id"><?php foreach($perms as $p): ?><option value="<?=e($p['id'])?>"><?=e($p['code'])?></option><?php endforeach;?></select></label>
      <label>Rola<select name="role_id"><?php foreach($roles as $r): ?><option value="<?=e($r['id'])?>"><?=e($r['nazov'])?></option><?php endforeach;?></select></label>
      <button name="assign" value="1" type="submit">Priradiť</button>
    </form>
  </section>

  <section>
    <h2>Zoznam oprávnení</h2>
    <table border="1"><tr><th>ID</th><th>Kód</th><th>Popis</th><th>Vytvorené</th></tr>
    <?php foreach($perms as $p): ?>
      <tr><td><?=e($p['id'])?></td><td><?=e($p['code'])?></td><td><?=e($p['description'])?></td><td><?=e($p['created_at'])?></td></tr>
    <?php endforeach; ?>
    </table>
  </section>
</main>
</body></html>
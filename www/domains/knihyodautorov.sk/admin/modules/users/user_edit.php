<?php
require __DIR__ . '/../../inc/bootstrap.php';
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: list.php'); exit; }
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::validate($_POST['csrf_token'] ?? '')) { $err = 'CSRF'; }
    else {
        $is_active = isset($_POST['is_active']) ? 1:0;
        $must = isset($_POST['must_change_password']) ? 1:0;
        $db->prepare('UPDATE pouzivatelia SET is_active=?, must_change_password=?, updated_at=NOW() WHERE id=?')->execute([$is_active,$must,$id]);
        // roles handling: simple replace
        $role = $_POST['role'] ?? null;
        if ($role) {
            // find role id
            $rid = $db->prepare('SELECT id FROM roles WHERE nazov = ? LIMIT 1'); $rid->execute([$role]); $rid = $rid->fetchColumn();
            if ($rid) {
                $db->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$id]);
                $db->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)')->execute([$id,$rid]);
            }
        }
        header('Location: list.php'); exit;
    }
}
$user = $db->prepare('SELECT id,email,is_active,must_change_password FROM pouzivatelia WHERE id = ? LIMIT 1'); $user->execute([$id]); $user = $user->fetch();
$roles = $db->query('SELECT nazov FROM roles ORDER BY nazov')->fetchAll(PDO::FETCH_COLUMN);
?><!doctype html><html lang="sk"><head><meta charset="utf-8"><title>Upraviť používateľa</title></head><body>
<?php include __DIR__ . '/../templates/admin-header.php'; ?>
<main>
  <h1>Upraviť používateľa <?=e($user['email'])?></h1>
  <?php if ($err) echo '<p class="error">'.e($err).'</p>'; ?>
  <form method="post">
    <label>Aktívny<input type="checkbox" name="is_active" <?=($user['is_active']?'checked':'')?>></label><br>
    <label>Vynútiť zmenu hesla<input type="checkbox" name="must_change_password" <?=($user['must_change_password']?'checked':'')?>></label><br>
    <label>Rola
      <select name="role">
        <option value="">-- žiadna --</option>
        <?php foreach($roles as $r): ?><option value="<?=e($r)?>"><?=e($r)?></option><?php endforeach; ?>
      </select>
    </label><br>
    <input type="hidden" name="csrf_token" value="<?=e(CSRF::token())?>">
    <button type="submit">Uložiť</button>
  </form>
</main>
</body></html>
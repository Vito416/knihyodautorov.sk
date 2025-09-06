// File: www/admin/modules/users/edit.php
<?php
declare(strict_types=1);
// Admin: edit user
// Path: www/admin/modules/users/edit.php


require_once __DIR__ . '/../../inc/bootstrap.php';


if (!class_exists('Auth') || !Auth::isLoggedIn() || !Auth::user()) {
header('Location: /admin/login.php');
exit;
}
$me = Auth::user();
if (!isset($me['is_admin']) || (int)$me['is_admin'] !== 1) {
http_response_code(403);
echo '<h1>Prístup zamietnutý</h1>';
exit;
}


function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }


$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
http_response_code(400);
echo 'Neplatné ID.';
exit;
}


// load user
$stmt = $db->prepare('SELECT id, name, email, is_admin, is_active FROM users WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
http_response_code(404);
echo 'Používateľ nenájdený.';
exit;
}


$errors = [];


// CSRF helpers (reuse keys)
function genCsrfUserEdit(string $key = 'user_edit_csrf'): string {
if (class_exists('CSRF') && method_exists('CSRF', 'generate')) return CSRF::generate();
if (empty($_SESSION[$key])) $_SESSION[$key] = bin2hex(random_bytes(32));
return $_SESSION[$key];
}
function validateCsrfUserEdit(string $token, string $key = 'user_edit_csrf'): bool {
if (class_exists('CSRF') && method_exists('CSRF', 'validate')) return CSRF::validate($token);
if (empty($_SESSION[$key])) return false;
$ok = hash_equals($_SESSION[$key], (string)$token);
if ($ok) unset($_SESSION[$key]);
return $ok;
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
$posted = $_POST;
$csrf = $posted['csrf_token'] ?? '';
if (!validateCsrfUserEdit($csrf)) {
$errors[] = 'Neplatný CSRF token.';
}


$name = trim((string)($posted['name'] ?? ''));
$email = trim((string)($posted['email'] ?? ''));
$password = $posted['password'] ?? '';
$password_confirm = $posted['password_confirm'] ?? '';
$is_admin = isset($posted['is_admin']) && ($posted['is_admin'] === '1' || $posted['is_admin'] === 'on') ? 1 : 0;
$is_active = isset($posted['is_active']) && ($posted['is_active'] === '1' || $posted['is_active'] === 'on') ? 1 : 0;


if ($name === '') $errors[] = 'Meno je povinné.';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Neplatný e‑mail.';


if ($password !== '') {
if ($password !== $password_confirm) $errors[] = 'Heslá sa nezhodujú.';
if (mb_strlen($password) < 8) $errors[] = 'Heslo musí mať aspoň 8 znakov.';
}


if (empty($errors)) {
// check email uniqueness if changed
if ($email !== $user['email']) {
$chk = $db->prepare('SELECT id FROM users WHERE email = :email AND id != :id LIMIT 1');
$chk->execute([':email' => $email, ':id' => $id]);
if ($chk->fetch(PDO::FETCH_ASSOC)) $errors[] = 'E‑mail už používa iný používateľ.';
}
}


if (empty($errors)) {
try {
$db->beginTransaction();
if ($password !== '') {
$algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
$hash = password_hash($password, $algo);
$upd = $db->prepare('UPDATE users SET name = :name, email = :email, password = :pw, password_algo = :algo, is_admin = :is_admin, is_active = :is_active, updated_at = NOW() WHERE id = :id');
$upd->execute([':name' => $name, ':email' => $email, ':pw' => $hash, ':algo' => (string)$algo, ':is_admin' => $is_admin, ':is_active' => $is_active, ':id' => $id]);
} else {
$upd = $db->prepare('UPDATE users SET name = :name, email = :email, is_admin = :is_admin, is_active = :is_active, updated_at = NOW() WHERE id = :id');
$upd->execute([':name' => $name, ':email' => $email, ':is_admin' => $is_admin, ':is_active' => $is_active, ':id' => $id]);
}


// manage roles: if RBAC exists use it
if (class_exists('RBAC')) {
try {
if ($is_admin) RBAC::assignRole($db, $id, 'admin');
else /* remove admin role */ RBAC::revokeRole($db, $id, 'admin');
} catch (Throwable $e) {}
} else {
// fallback: ensure user_roles reflects is_admin
try {
if ($is_admin) {
// find admin role id
$r = $db->prepare('SELECT id FROM roles WHERE name = :name LIMIT 1');
$r->execute([':name' => 'admin']);
$rid = $r->fetchColumn();
if ($rid) {
$ins = $db->prepare('INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (:uid, :rid)');
$ins->execute([':uid' => $id, ':rid' => $rid]);
}
} else {
$del = $db->prepare('DELETE FROM user_roles WHERE user_id = :uid AND role_id = (SELECT id FROM roles WHERE name = :name LIMIT 1)');
$del->execute([':uid' => $id, ':name' => 'admin']);
}
} catch (Throwable $e) {}
}


$db->commit();
header('Location: /admin/modules/users/list.php?updated=' . $id);
exit;
} catch (PDOException $e) {
if ($db->inTransaction()) $db->rollBack();
$errors[] = 'Chyba pri ukladaní zmien.';
}
}
}


$csrf = genCsrfUserEdit();
?><!doctype html>
<html lang="sk">
<head><meta charset="utf-8"><title>Úprava používateľa</title></head>
<body>
<h1>Úprava používateľa</h1>
<?php foreach ($errors as $err): ?><div style="background:#fff1f0;padding:0.5rem;margin-bottom:0.5rem"><?php echo e($err); ?></div><?php endforeach; ?>


<form method="post" action="?id=<?php echo e((string)$id); ?>">
<label>Meno<br><input name="name" value="<?php echo e($_POST['name'] ?? $user['name']); ?>" required></label><br><br>
<label>E‑mail<br><input name="email" type="email" value="<?php echo e($_POST['email'] ?? $user['email']); ?>" required></label><br><br>
<label>Nové heslo (nepovinné)<br><input name="password" type="password"></label><br><br>
<label>Potvrdiť heslo<br><input name="password_confirm" type="password"></label><br><br>
<label><input type="checkbox" name="is_admin" value="1" <?php echo (isset($_POST['is_admin']) ? (($_POST['is_admin']) ? 'checked' : '') : ($user['is_admin'] ? 'checked' : '')); ?>> Administrator</label><br>
<label><input type="checkbox" name="is_active" value="1" <?php echo (isset($_POST['is_active']) ? (($_POST['is_active']) ? 'checked' : '') : ($user['is_active'] ? 'checked' : '')); ?>> Aktívny</label><br><br>
<input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
<button type="submit">Uložiť zmeny</button>
</form>
</body>
</html>
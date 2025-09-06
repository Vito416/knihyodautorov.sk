// File: www/admin/modules/users/create.php
<?php
declare(strict_types=1);
// Admin: create user
// Path: www/admin/modules/users/create.php


require_once __DIR__ . '/../../inc/bootstrap.php'; // admin bootstrap provides $db, Auth, session


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


$errors = [];


// CSRF helpers
function genCsrfUser(string $key = 'user_form_csrf'): string {
if (class_exists('CSRF') && method_exists('CSRF', 'generate')) return CSRF::generate();
if (empty($_SESSION[$key])) $_SESSION[$key] = bin2hex(random_bytes(32));
return $_SESSION[$key];
}
function validateCsrfUser(string $token, string $key = 'user_form_csrf'): bool {
if (class_exists('CSRF') && method_exists('CSRF', 'validate')) return CSRF::validate($token);
if (empty($_SESSION[$key])) return false;
$ok = hash_equals($_SESSION[$key], (string)$token);
if ($ok) unset($_SESSION[$key]);
return $ok;
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
$posted = $_POST;
$csrf = $posted['csrf_token'] ?? '';
if (!validateCsrfUser($csrf)) {
$errors[] = 'Neplatný CSRF token.';
}


$name = trim((string)($posted['name'] ?? ''));
$email = trim((string)($posted['email'] ?? ''));
$password = $posted['password'] ?? '';
$password_confirm = $posted['password_confirm'] ?? '';
$is_admin = isset($posted['is_admin']) && ($posted['is_admin'] === '1' || $posted['is_admin'] === 'on') ? 1 : 0;
$is_active = isset($posted['is_active']) && ($posted['is_active'] === '1' || $posted['is_active'] === 'on') ? 1 : 1;


// validation
if ($name === '') $errors[] = 'Meno je povinné.';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Neplatný e‑mail.';
if ($password === '' || $password_confirm === '') $errors[] = 'Heslo a jeho potvrdenie sú povinné.';
if ($password !== $password_confirm) $errors[] = 'Heslá sa nezhodujú.';
if (mb_strlen($password) < 8) $errors[] = 'Heslo musí mať aspoň 8 znakov.';


if (empty($errors)) {
// unique email
$stmt = $db->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
$stmt->execute([':email' => $email]);
if ($stmt->fetch(PDO::FETCH_ASSOC)) {
$errors[] = 'Užívateľ s týmto e‑mailom už existuje.';
}
}


if (empty($errors)) {
$algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
$hash = password_hash($password, $algo);
if ($hash === false) {
$errors[] = 'Chyba pri hashovaní hesla.';
} else {
try {
$db->beginTransaction();
$ins = $db->prepare('INSERT INTO users (name, email, password, password_algo, is_admin, is_active, created_at) VALUES (:name, :email, :pw, :algo, :is_admin, :is_active, NOW())');
$ins->execute([
':name' => $name,
':email' => $email,
':pw' => $hash,
':algo' => (string)$algo,
':is_admin' => $is_admin,
':is_active' => $is_active,
]);
$newId = (int)$db->lastInsertId();


// If RBAC available, assign default role or admin role
if (class_exists('RBAC')) {
try { RBAC::assignRole($db, $newId, $is_admin ? 'admin' : 'user'); } catch (Throwable $e) {}
} else {
// fallback: insert into user_roles if table exists and is_admin
if ($is_admin) {
try {
$r = $db->prepare('SELECT id FROM roles WHERE name = :name LIMIT 1');
$r->execute([':name' => 'admin']);
$rid = $r->fetchColumn();
if ($rid) {
$ur = $db->prepare('INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (:uid, :rid)');
$ur->execute([':uid' => $newId, ':rid' => $rid]);
}
} catch (Throwable $e) {}
}
}


$db->commit();
header('Location: /admin/modules/users/list.php?created=' . $newId);
exit;
} catch (PDOException $e) {
if ($db->inTransaction()) $db->rollBack();
$errors[] = 'Chyba pri vytvorení používateľa.';
}
}
}
}


$csrf = genCsrfUser();
?><!doctype html>
<html lang="sk">
<head><meta charset="utf-8"><title>Vytvoriť používateľa</title></head>
<body>
<h1>Vytvoriť používateľa</h1>
<?php foreach ($errors as $err): ?><div style="background:#fff1f0;padding:0.5rem;margin-bottom:0.5rem"><?php echo e($err); ?></div><?php endforeach; ?>
<form method="post" action="">
<label>Meno<br><input name="name" value="<?php echo e($_POST['name'] ?? ''); ?>" required></label><br><br>
<label>E‑mail<br><input name="email" type="email" value="<?php echo e($_POST['email'] ?? ''); ?>" required></label><br><br>
<label>Heslo<br><input name="password" type="password" required></label><br><br>
<label>Potvrdiť heslo<br><input name="password_confirm" type="password" required></label><br><br>
<label><input type="checkbox" name="is_admin" value="1" <?php echo isset($_POST['is_admin'])? 'checked':''; ?>> Administrator</label><br>
<label><input type="checkbox" name="is_active" value="1" <?php echo (isset($_POST['is_active']) && $_POST['is_active']) || !isset($_POST['is_active'])? 'checked':''; ?>> Aktívny</label><br><br>
<input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
<button type="submit">Vytvoriť</button>
</form>
</body>
</html>
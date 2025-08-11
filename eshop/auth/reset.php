<?php
require __DIR__ . '/../bootstrap.php';
$pdoLocal = $pdo;
$errors = [];
$ok = false;

$token = $_GET['token'] ?? ($_POST['token'] ?? '');
if ($token === '') {
    die('Neplatný token.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? '')) $errors[] = 'Neplatný CSRF token.';
    $pass = (string)($_POST['password'] ?? '');
    $pass2 = (string)($_POST['password2'] ?? '');
    if ($pass === '' || $pass2 === '') $errors[] = 'Oba polia sú povinné.';
    if ($pass !== $pass2) $errors[] = 'Heslá sa nezhodujú.';
    if (strlen($pass) < 6) $errors[] = 'Heslo minimálne 6 znakov.';
    if (empty($errors)) {
        $stmt = $pdoLocal->prepare("SELECT id FROM users WHERE reset_token = ? LIMIT 1");
        $stmt->execute([$token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) { $errors[] = 'Token nie je platný alebo expiroval.'; }
        else {
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            $pdoLocal->prepare("UPDATE users SET heslo = ?, reset_token = NULL WHERE id = ?")->execute([$hash, (int)$row['id']]);
            set_flash('success', 'Heslo bolo obnovené. Môžete sa prihlásiť.');
            redirect_to('login.php');
        }
    }
}
?><!doctype html><html lang="sk"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Reset hesla</title><link rel="stylesheet" href="/eshop/css/eshop-auth.css"></head><body class="eshop">
<div class="eshop-wrap"><div class="card">
  <h1>Obnova hesla</h1>
  <?php if (!empty($errors)): ?><div class="msg error"><?php foreach($errors as $e) echo '<div>'.esc($e).'</div>'; ?></div><?php endif; ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?php echo esc(csrf_token()); ?>">
    <input type="hidden" name="token" value="<?php echo esc($token); ?>">
    <div class="form-row"><label for="password">Nové heslo</label><input id="password" name="password" type="password" required></div>
    <div class="form-row"><label for="password2">Nové heslo — znova</label><input id="password2" name="password2" type="password" required></div>
    <div class="form-row"><button class="btn" type="submit">Nastaviť nové heslo</button></div>
  </form>
</div></div>
</body></html>

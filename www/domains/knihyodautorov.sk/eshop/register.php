<?php
require __DIR__ . '/inc/bootstrap.php';
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST'){
    if (!CSRF::validate($_POST['csrf_token'] ?? '')) { $err = 'CSRF token invalid'; }
    else {
        $email = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL);
        $pass = $_POST['password'] ?? '';
        if (!$email) $err = 'Neplatný email';
        elseif (strlen($pass) < 8) $err = 'Heslo musí mať aspoň 8 znakov';
        else {
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            $stmt = $db->prepare('INSERT INTO pouzivatelia (email, heslo_hash, is_active, created_at) VALUES (?, ?, 1, NOW())');
            try { $stmt->execute([$email, $hash]); header('Location: login.php'); exit; }
            catch(Exception $ex){ $err = 'Účet s týmto emailom už existuje'; }
        }
    }
}
?><!doctype html>
<html lang="sk"><head><meta charset="utf-8"></head><body>
<?php include __DIR__.'/templates/layout-header.php'; ?>
<main>
<h1>Registrácia</h1>
<?php if ($err) echo '<p class="error">'.e($err).'</p>'; ?>
<form method="post">
<label>Email<input type="email" name="email" required></label>
<label>Heslo<input type="password" name="password" required></label>
<input type="hidden" name="csrf_token" value="<?=e(CSRF::token())?>">
<button type="submit">Registrovať</button>
</form>
</main>
<?php include __DIR__.'/templates/layout-footer.php'; ?>
</body></html>
<?php
require __DIR__ . '/inc/bootstrap.php';
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST'){
    if (!CSRF::validate($_POST['csrf_token'] ?? '')) { $err = 'CSRF token invalid'; }
    else {
        [$ok,$msg] = Auth::loginWithPassword($db, $_POST['email'] ?? '', $_POST['password'] ?? '');
        if ($ok) header('Location: index.php'); else $err = $msg;
    }
}
?><!doctype html>
<html lang="sk"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Prihlásenie</title>
<link rel="stylesheet" href="assets/css/base.css">
</head><body>
<?php include __DIR__.'/templates/layout-header.php'; ?>
<main>
<h1>Prihlásenie</h1>
<?php if ($err) echo '<p class="error">'.e($err).'</p>'; ?>
<form method="post">
  <label>Email<input type="email" name="email" required></label>
  <label>Heslo<input type="password" name="password" required></label>
  <input type="hidden" name="csrf_token" value="<?=e(CSRF::token())?>">
  <button type="submit">Prihlásiť</button>
</form>
<p><a href="register.php">Registrovať</a></p>
</main>
<?php include __DIR__.'/templates/layout-footer.php'; ?>
</body></html>
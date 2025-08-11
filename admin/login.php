<?php
// /admin/login.php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/inc/csrf.php';
require_once __DIR__ . '/inc/helpers.php';

if (admin_is_logged()) {
    header('Location: index.php'); exit;
}

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check_token($_POST['csrf'] ?? '')) {
        $err = 'Neplatný formulár.';
    } else {
        $user = trim((string)($_POST['username'] ?? ''));
        $pass = (string)($_POST['password'] ?? '');
        $stmt = $pdo->prepare("SELECT id, username, password, is_active FROM admin_users WHERE username = ? LIMIT 1");
        $stmt->execute([$user]);
        $a = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($a && !empty($a['is_active']) && password_verify($pass, $a['password'])) {
            $_SESSION['admin_user_id'] = (int)$a['id'];
            // redirect
            header('Location: index.php'); exit;
        } else {
            $err = 'Neplatné prihlasovacie údaje.';
        }
    }
}
?><!doctype html>
<html lang="sk">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Admin — Prihlásenie</title>
  <link rel="stylesheet" href="/admin/css/admin.css">
</head>
<body class="adm-auth-body">
  <div class="adm-auth-card">
    <h1>Administrácia</h1>
    <?php if ($err): ?><div class="adm-alert adm-alert-error"><?= adm_esc($err) ?></div><?php endif; ?>
    <form method="post" action="/admin/login.php" class="adm-form">
      <input type="hidden" name="csrf" value="<?= adm_esc(csrf_get_token()) ?>">
      <label>Užívateľ</label>
      <input name="username" type="text" required>
      <label>Heslo</label>
      <input name="password" type="password" required>
      <div class="adm-form-actions">
        <button class="adm-btn adm-btn-primary" type="submit">Prihlásiť</button>
      </div>
    </form>
  </div>
</body>
</html>

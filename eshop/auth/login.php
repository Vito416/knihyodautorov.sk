<?php
require __DIR__ . '/../bootstrap.php';
$pdoLocal = $pdo;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        $errors[] = 'Neplatný CSRF token.';
    } else {
        $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
        $password = (string)($_POST['password'] ?? '');
        if ($email === '' || $password === '') $errors[] = 'Vyplňte e-mail a heslo.';
        if (empty($errors)) {
            $stmt = $pdoLocal->prepare("SELECT id, heslo FROM users WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$r || !password_verify($password, $r['heslo'])) {
                $errors[] = 'Neplatné prihlasovacie údaje.';
            } else {
                // login OK
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int)$r['id'];
                // update last_login
                $pdoLocal->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([ (int)$r['id'] ]);
                $ret = $_GET['return'] ?? '/eshop/account.php';
                header('Location: ' . $ret);
                exit;
            }
        }
    }
}
?><!doctype html>
<html lang="sk">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Prihlásenie — e-shop</title>
  <link rel="stylesheet" href="/eshop/css/eshop-auth.css">
</head>
<body class="eshop">
  <div class="eshop-wrap"><div class="card">
    <h1>Prihlásenie</h1>
    <?php if ($err = get_flash('error')): ?><div class="msg error"><?php echo esc($err); ?></div><?php endif; ?>
    <?php if ($s = get_flash('success')): ?><div class="msg success"><?php echo esc($s); ?></div><?php endif; ?>
    <?php if (!empty($errors)): ?><div class="msg error"><?php foreach ($errors as $e) echo '<div>'.esc($e).'</div>'; ?></div><?php endif; ?>

    <form method="post">
      <input type="hidden" name="csrf" value="<?php echo esc(csrf_token()); ?>">
      <div class="form-row"><label for="email">E-mail</label><input id="email" name="email" type="email" required></div>
      <div class="form-row"><label for="password">Heslo</label><input id="password" name="password" type="password" required></div>
      <div class="form-row"><button class="btn" type="submit">Prihlásiť</button></div>
    </form>

    <p class="small"><a href="forgot.php">Zabudnuté heslo?</a></p>
    <p class="small">Nemáte účet? <a href="register.php">Zaregistrujte sa</a></p>
  </div></div>
  <script src="/eshop/js/eshop-auth.js" defer></script>
</body>
</html>

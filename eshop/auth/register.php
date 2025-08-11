<?php
require __DIR__ . '/../bootstrap.php';
$base = '/eshop';
$pdoLocal = $pdo;

$errors = [];
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        $errors[] = 'Neplatný CSRF token.';
    } else {
        $meno = trim((string)($_POST['meno'] ?? ''));
        $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
        $password = (string)($_POST['password'] ?? '');
        $newsletter = isset($_POST['newsletter']) ? 1 : 0;

        if ($meno === '' || $email === '' || $password === '') $errors[] = 'Vyplňte všetky povinné údaje.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Neplatný e-mail.';
        if (strlen($password) < 6) $errors[] = 'Heslo musí mať aspoň 6 znakov.';

        if (empty($errors)) {
            // kontrola existencie emailu
            $stmt = $pdoLocal->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            if ($stmt->fetchColumn()) {
                $errors[] = 'E-mail už je zaregistrovaný.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdoLocal->prepare("INSERT INTO users (meno, email, heslo, newsletter) VALUES (?, ?, ?, ?)");
                $stmt->execute([$meno, $email, $hash, $newsletter]);
                set_flash('success', 'Registrácia prebehla, môžete sa prihlásiť.');
                header('Location: login.php');
                exit;
            }
        }
    }
}
?><!doctype html>
<html lang="sk">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Registrácia — e-shop</title>
  <link rel="stylesheet" href="/eshop/css/eshop-auth.css">
</head>
<body class="eshop">
  <div class="eshop-wrap">
    <div class="card">
      <h1>Registrácia</h1>
      <?php if ($err = get_flash('error')): ?><div class="msg error"><?php echo esc($err); ?></div><?php endif; ?>
      <?php if ($s = get_flash('success')): ?><div class="msg success"><?php echo esc($s); ?></div><?php endif; ?>
      <?php if (!empty($errors)): ?><div class="msg error"><?php foreach ($errors as $e) echo '<div>'.esc($e).'</div>'; ?></div><?php endif; ?>

      <form method="post" data-eshop-form>
        <input type="hidden" name="csrf" value="<?php echo esc(csrf_token()); ?>">
        <div class="form-row"><label for="meno">Meno a priezvisko</label><input id="meno" name="meno" required></div>
        <div class="form-row"><label for="email">E-mail</label><input id="email" name="email" type="email" required></div>
        <div class="form-row"><label for="password">Heslo</label><input id="password" name="password" type="password" required></div>
        <div class="form-row"><label><input type="checkbox" name="newsletter"> Chcem dostávať novinky</label></div>
        <div class="form-row"><button class="btn" type="submit">Registrovať sa</button></div>
      </form>
      <p class="small center">Máte už účet? <a href="login.php">Prihlásiť sa</a></p>
    </div>
  </div>
  <script src="/eshop/js/eshop-auth.js" defer></script>
</body>
</html>

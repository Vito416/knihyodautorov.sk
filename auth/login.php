<?php
// auth/login.php
session_start();
require_once __DIR__ . '/../db/config/config.php';

$next = $_GET['next'] ?? '/';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $pass = $_POST['password'] ?? '';
    if ($email && $pass) {
        $stmt = $pdo->prepare("SELECT id, heslo FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $u = $stmt->fetch();
        if ($u && password_verify($pass, $u['heslo'])) {
            $_SESSION['user_id'] = (int)$u['id'];
            header('Location: ' . $next);
            exit;
        } else {
            $err = 'Nesprávny email alebo heslo.';
        }
    } else $err = 'Vyplňte email a heslo.';
}

if (file_exists(__DIR__ . '/partials/header.php')) include __DIR__ . '/partials/header.php';
?>
<link rel="stylesheet" href="/eshop/css/cart.css">
<section class="auth-login">
  <div class="auth-inner" style="max-width:440px;margin:40px auto">
    <h1>Prihlásenie</h1>
    <?php if ($err) echo '<div class="auth-err">'.htmlspecialchars($err).'</div>'; ?>
    <form method="post">
      <label>Email</label>
      <input type="email" name="email" required>
      <label>Heslo</label>
      <input type="password" name="password" required>
      <input type="hidden" name="next" value="<?= htmlspecialchars($next) ?>">
      <button type="submit" class="cart-checkout">Prihlásiť</button>
    </form>
  </div>
</section>
<?php if (file_exists(__DIR__ . '/partials/footer.php')) include __DIR__ . '/partials/footer.php'; ?>

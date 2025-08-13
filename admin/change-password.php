<?php
// /admin/change-password.php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
require __DIR__ . '/bootstrap.php';
if (!admin_is_logged()) { header('Location: login.php'); exit; }

$admin = admin_user($pdo);
if (!$admin) { header('Location: login.php'); exit; }

$messages = [];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pw = trim((string)($_POST['password'] ?? ''));
    $pw2 = trim((string)($_POST['password2'] ?? ''));
    if ($pw === '' || $pw2 === '') $errors[] = 'Zadajte nové heslo obidva krát.';
    if ($pw !== $pw2) $errors[] = 'Heslá sa nezhodujú.';
    if (strlen($pw) < 8) $errors[] = 'Heslo musí mať aspoň 8 znakov.';
    if (empty($errors)) {
        $hash = password_hash($pw, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE admin_users SET password = ?, must_change_password = 0 WHERE id = ?");
        $stmt->execute([$hash, $admin['id']]);
        $messages[] = 'Heslo zmenené. Prihláste sa znova.';
        // logout and force re-login
        session_unset();
        session_destroy();
        header('Location: login.php');
        exit;
    }
}

include __DIR__ . '/partials/header.php';
?>
<main class="admin-main container">
  <h1>Zmena hesla</h1>
  <?php foreach ($errors as $e): ?><div class="notice error"><?php echo htmlspecialchars($e); ?></div><?php endforeach; ?>
  <?php foreach ($messages as $m): ?><div class="notice success"><?php echo htmlspecialchars($m); ?></div><?php endforeach; ?>

  <form method="post" class="card">
    <label>Nové heslo<br><input type="password" name="password" required></label>
    <label>Nové heslo znovu<br><input type="password" name="password2" required></label>
    <div style="margin-top:10px;"><button class="btn-primary" type="submit">Zmeniť heslo</button></div>
  </form>
</main>
<?php include __DIR__ . '/partials/footer.php'; ?>
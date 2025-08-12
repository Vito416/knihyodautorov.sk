<?php
// /admin/user-edit.php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
if (!admin_is_logged()) { header('Location: login.php'); exit; }
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { header('Location: users.php'); exit; }

$stmt = $pdo->prepare("SELECT id, meno, email, telefon, adresa, newsletter, email_verified, verify_token, last_login, datum_registracie FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) { header('Location: users.php'); exit; }
?>
<!doctype html>
<html lang="sk">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Admin — Upraviť užívateľa</title>
  <link rel="stylesheet" href="/admin/css/admin.css">
  <script src="/admin/js/admin.js" defer></script>
</head>
<body>
  <main class="admin-shell">
    <header class="admin-top">
      <h1>Upraviť užívateľa — <?php echo htmlspecialchars($user['meno']); ?></h1>
      <div class="actions"><a class="btn ghost" href="users.php">Späť</a></div>
    </header>

    <form method="post" action="user-action.php" class="panel">
      <input type="hidden" name="csrf" value="<?php echo $_SESSION['csrf_token']; ?>">
      <input type="hidden" name="id" value="<?php echo (int)$user['id']; ?>">
      <input type="hidden" name="action" value="update">

      <div class="form-row">
        <div class="col">
          <label>Meno</label>
          <input type="text" name="meno" required value="<?php echo htmlspecialchars($user['meno']); ?>">
        </div>
        <div class="col">
          <label>E-mail</label>
          <input type="email" name="email" required value="<?php echo htmlspecialchars($user['email']); ?>">
        </div>
      </div>

      <div style="margin-top:12px;">
        <label>Telefón</label>
        <input type="text" name="telefon" value="<?php echo htmlspecialchars($user['telefon']); ?>">
      </div>

      <div style="margin-top:12px;">
        <label>Adresa</label>
        <textarea name="adresa" rows="3"><?php echo htmlspecialchars($user['adresa']); ?></textarea>
      </div>

      <div style="margin-top:12px;">
        <label><input type="checkbox" name="newsletter" value="1" <?php if($user['newsletter']) echo 'checked'; ?>> Odoberá newsletter</label>
      </div>

      <div style="margin-top:12px;">
        <strong>Overenie e-mailu:</strong> <?php echo $user['email_verified'] ? 'Overený' : 'Neoverený'; ?>
        <?php if (!$user['email_verified']): ?>
          <button class="btn small" type="submit" formaction="user-action.php" formmethod="post" name="action" value="send_verify" style="margin-left:12px;">Poslať verifikačný e-mail</button>
        <?php endif; ?>
      </div>

      <hr style="margin:16px 0;border:none;border-top:1px solid rgba(255,255,255,0.03)">

      <h4>Zmena hesla</h4>
      <div class="form-row">
        <div class="col"><label>Nové heslo</label><input type="password" name="new_password" autocomplete="new-password"></div>
        <div class="col"><label>Potvrdenie hesla</label><input type="password" name="new_password_confirm"></div>
      </div>
      <div style="margin-top:12px;">
        <button class="btn" type="submit" formaction="user-action.php" formmethod="post" name="action" value="set_password">Uložiť heslo</button>
        <button class="btn ghost" type="submit" formaction="user-action.php" formmethod="post" name="action" value="send_reset_token">Poslať resetovací e-mail</button>
      </div>

      <div style="margin-top:18px;">
        <button class="btn" type="submit">Uložiť zmeny</button>
        <a class="btn ghost" href="users.php">Zrušiť</a>
      </div>
    </form>

    <section class="panel" style="margin-top:12px;">
      <h3>História prihlásení</h3>
      <p class="muted">Posledné prihlásenie: <?php echo htmlspecialchars($user['last_login'] ?? '—'); ?></p>
      <p class="muted">Registrovaný: <?php echo htmlspecialchars($user['datum_registracie']); ?></p>
    </section>

    <section class="panel">
      <h3>Objednávky & stiahnutia</h3>
      <p><a class="btn" href="user-downloads.php?user_id=<?php echo (int)$user['id']; ?>">Zobraziť objednávky používateľa</a></p>
    </section>
  </main>
</body>
</html>
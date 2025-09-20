<?php
// pages/change_password.php
$title = $title ?? 'Změna hesla';
include __DIR__ . '/../partials/header.php';
?>
<section class="auth-card change-password-card">
  <h1>Změna hesla</h1>

  <?php if (!empty($message)): ?>
    <div class="notice"><?= $message ?></div>
  <?php endif; ?>

  <form method="post" action="/eshop/actions/change_password.php" id="changePasswordForm">
    <label for="current_password">Aktuální heslo</label>
    <input id="current_password" name="current_password" type="password" required>

    <label for="new_password">Nové heslo</label>
    <input id="new_password" name="new_password" type="password" required>

    <label for="new_password_confirm">Potvrdit nové heslo</label>
    <input id="new_password_confirm" name="new_password_confirm" type="password" required>

    <input type="hidden" name="csrf" value="<?= $csrf ?? '' ?>">

    <button type="submit">Změnit heslo</button>
  </form>
</section>
<?php include __DIR__ . '/../partials/footer.php'; ?>
<?php
// pages/verify_email.php
$title = $title ?? 'Ověření e-mailu';
include __DIR__ . '/../partials/header.php';
?>
<section class="auth-card verify-card">
  <h1>Ověření e-mailu</h1>

  <?php if (!empty($sent)): ?>
    <div class="notice">Na váš e-mail jsme odeslali ověřovací odkaz. Zkontrolujte prosím schránku.</div>
  <?php else: ?>
    <p>Pro dokončení registrace klikněte na odkaz v ověřovacím e-mailu.</p>
    <form method="post" action="/eshop/actions/resend_verify.php">
      <input type="hidden" name="csrf" value="<?= $csrf ?? '' ?>">
      <button type="submit">Znovu odeslat ověřovací e-mail</button>
    </form>
  <?php endif; ?>
</section>
<?php include __DIR__ . '/../partials/footer.php'; ?>
<?php
// pages/newsletter_confirm.php
// Voliteľné premenné (Templates ich escapuje): $title, $status ('success'|'error'), $error, $email_enc, $back_url, $manage_url
$title = $title ?? 'Potvrdenie odberu';
$status = strtolower((string)($status ?? 'error'));
$backUrl = $back_url ?? '/';
$manageUrl = $manage_url ?? '/newsletter/preferences.php';

?>
<section class="auth-card verify-card">
  <h1><?= $title ?></h1>

  <?php if ($status === 'success'): ?>
    <div class="success">
      <strong>Ďakujeme — odber potvrdený</strong>
      <p>Vaše prihlásenie na novinky bolo potvrdené.</p>

      <p style="margin-top:.6rem;">
        <a class="btn" href="<?= $backUrl ?>">Späť</a>
        <a class="btn alt" href="<?= $manageUrl ?>" style="margin-left:.5rem;">Spravovať</a>
      </p>

      <?php if (!empty($email_enc)): ?>
        <p class="small muted" style="margin-top:.6rem;">(E-mail je uložený šifrovane v DB.)</p>
      <?php endif; ?>
    </div>

  <?php else: ?>
    <div class="error">
      <strong>Potvrdenie zlyhalo</strong>
      <p><?= htmlspecialchars($error ?? 'Token je neplatný, expirovaný alebo už použitý.', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>

      <p style="margin-top:.6rem;">
        <a class="btn" href="/newsletter/subscribe.php">Požiadať znova</a>
        <a class="btn alt" href="<?= $backUrl ?>" style="margin-left:.5rem;">Späť</a>
      </p>
    </div>
  <?php endif; ?>

  <p class="small muted" style="margin-top:1rem;">Máte problém? <a href="/contact.php">Kontaktujte nás</a>.</p>
</section>

<style>
.auth-card { max-width:720px; margin:2rem auto; padding:1.2rem; background:#fff; border-radius:8px; box-shadow:0 6px 18px rgba(0,0,0,0.04); }
.verify-card h1 { margin:0 0 .6rem 0; font-size:1.4rem; }
.success { border-left:4px solid #28a745; padding:.6rem 1rem; background:#f6fffa; margin-bottom:1rem; }
.error { border-left:4px solid #dc3545; padding:.6rem 1rem; background:#fff6f6; margin-bottom:1rem; }
.btn { display:inline-block; padding:.45rem .8rem; background:#007bff; color:#fff; text-decoration:none; border-radius:4px; font-size:.95rem; }
.btn.alt { background:#6c757d; }
.small { font-size:.88rem; }
.muted { color:#6b6b6b; }
</style>
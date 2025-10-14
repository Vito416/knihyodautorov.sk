<?php
// pages/newsletter_unsubscribe.php
// Voliteľné premenné: $title, $status ('Unsubscribed'|'error'), $error, $back_url
$title = $title ?? 'Odhlásenie z odberu';
$status = strtolower((string)($status ?? 'error'));
$backUrl = $back_url ?? '/';

?>
<section class="auth-card verify-card">
  <h1><?= $title ?></h1>

  <?php if ($status === 'unsubscribed'): ?>
    <div class="success">
      <strong>Odhlásenie úspešné</strong>
      <p>Vaša e-mailová adresa bola odhlásená z odberu noviniek.</p>

      <p style="margin-top:.6rem;">
        <a class="btn" href="<?= $backUrl ?>">Späť na stránku</a>
      </p>
    </div>

  <?php else: ?>
    <div class="error">
      <strong>Odhlásenie zlyhalo</strong>
      <p><?= htmlspecialchars($error ?? 'Token je neplatný, expirovaný alebo už použitý.', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>

      <p style="margin-top:.6rem;">
        <a class="btn" href="<?= $backUrl ?>">Späť</a>
      </p>
    </div>
  <?php endif; ?>

  <p class="small muted" style="margin-top:1rem;">
    Máte problém? <a href="/contact.php">Kontaktujte nás</a>.
  </p>
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
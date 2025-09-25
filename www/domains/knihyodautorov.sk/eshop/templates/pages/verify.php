<?php
// pages/verify.php
// Vstupné premenné (voliteľné, Templates ich bezpečne escapuje):
//   $title (string)           - titulok stránky
//   $status (string)          - one of: 'invalid','not_found','expired','error','success','already_active'
//   $csrf (string)            - CSRF token pre resend form (ak používate)
//   $sent (bool|int)          - ak resend prebehol, môžete nastaviť true/1
//   $newsletter (int|string)  - voliteľný flag (0/1) ak chcete zobraziť extra hlášku pre newsletter
$title = $title ?? 'Overenie e-mailu';

?>
<section class="auth-card verify-card">
  <h1><?= $title ?></h1>

  <?php
    $status = strtolower((string)($status ?? ''));
    // používateľsky prívetivé texty
    switch ($status) {
      case 'invalid':
  ?>
        <div class="error">
          <strong>Neplatný overovací odkaz</strong>
          <p>Odkaz, ktorý ste použili, je nesprávny alebo bol poškodený. Skontrolujte prosím odkaz v e-maile alebo si nechajte poslať nový overovací e-mail.</p>
        </div>
  <?php
        break;
      case 'not_found':
  ?>
        <div class="error">
          <strong>Overovací záznam nebol nájdený</strong>
          <p>Nepodarilo sa nájsť overovací token. Môže byť už použitý alebo nikdy nebol vytvorený. Požiadajte prosím o opätovné odoslanie overovacieho e-mailu.</p>
        </div>
  <?php
        break;
      case 'expired':
  ?>
        <div class="warning">
          <strong>Overovací odkaz vypršal</strong>
          <p>Overovací odkaz už nie je platný. Požiadajte prosím o zaslanie nového overovacieho e-mailu.</p>
        </div>
  <?php
        break;
      case 'already_active':
  ?>
        <div class="notice">
          <strong>Účet je už aktívny</strong>
          <p>Váš účet je už aktivovaný. Prosím prihláste sa.</p>
          <p><a class="btn" href="/login">Prejsť na prihlásenie</a></p>
        </div>
  <?php
        break;
      case 'success':
  ?>
        <div class="success">
          <strong>Ďakujeme — e-mail overený</strong>
          <p>Váš e-mail bol úspešne overený a účet aktivovaný.</p>
          <p><a class="btn" href="/login">Prejsť na prihlásenie</a></p>

          <?php if ((int)($newsletter ?? 0) === 1): ?>
              <p class="small">Ďakujeme — vaša žiadosť o zasielanie noviniek bola zaznamenaná.</p>
          <?php else: ?>
              <p class="small">Chcete dostávať novinky? <a href="/newsletter/subscribe">Prihlásiť sa na odber</a>.</p>
          <?php endif; ?>
        </div>
  <?php
        break;
      case 'error':
      default:
  ?>
        <div class="error">
          <strong>Nastala chyba</strong>
          <p>Pri overovaní došlo k neočakávanej chybe. Skúste to, prosím, neskôr alebo kontaktujte podporu.</p>
        </div>
  <?php
        break;
    }
  ?>

  <!-- Resend form: zobrazíme, ak nie je success/already_active -->
  <?php if (!in_array($status, ['success', 'already_active'], true)): ?>
    <div class="resend-section">
      <?php if (!empty($sent)): ?>
        <div class="notice">Na váš e-mail sme odoslali nový overovací odkaz. Skontrolujte si prosím schránku (vrátane priečinka spam).</div>
      <?php else: ?>
        <p>Ak ste overovací e-mail neobdržali, môžete si nechať odkaz poslať znova.</p>
        <form method="post" action="/eshop/actions/resend_verify" class="resend-form">
          <?= CSRF::hiddenInput('csrf') ?>
          <button type="submit" class="btn">Znova odoslať overovací e-mail</button>
        </form>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <p class="small muted">Máte problém? <a href="/contact">Kontaktujte nás</a>.</p>
</section>

<style>
/* Jednoduché lokálne štýly pre prehľadnosť; môžete ich presunúť do CSS súboru */
.auth-card { max-width: 720px; margin: 2rem auto; padding: 1.5rem; background:#fff; border-radius:8px; box-shadow:0 6px 20px rgba(0,0,0,0.05); }
.verify-card h1 { margin-top:0; font-size:1.6rem; }
.success { border-left:4px solid #28a745; padding:0.6rem 1rem; background:#f6fffa; margin-bottom:1rem; }
.notice { border-left:4px solid #17a2b8; padding:0.6rem 1rem; background:#f3fbfd; margin-bottom:1rem; }
.warning { border-left:4px solid #ffc107; padding:0.6rem 1rem; background:#fff9e6; margin-bottom:1rem; }
.error { border-left:4px solid #dc3545; padding:0.6rem 1rem; background:#fff6f6; margin-bottom:1rem; }
.btn { display:inline-block; padding:0.5rem 0.9rem; background:#007bff; color:#fff; text-decoration:none; border-radius:4px; }
.resend-section { margin-top:1rem; }
.small { font-size:0.9rem; }
.muted { color:#666; }
</style>
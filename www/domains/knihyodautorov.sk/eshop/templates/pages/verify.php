<?php
// pages/verify.php
// Vstupné premenné (od frontcontrolleru alebo volajúceho):
//   $title (string)           - nadpis stránky (voliteľné)
//   $status (string)          - one of: 'invalid','not_found','expired','error','success','already_active','ready'
//   $csrfToken (string)       - CSRF token pre POST form (required when status === 'ready')
//   $selector (string)        - selector token (z bezpečnostných dôvodov pošlite len selector)
//   $sent (bool|int)          - ak resend prebehol, môžete nastaviť true/1
//   $newsletter (int|string)  - voliteľný flag (0/1) ak chcete zobraziť extra hlášku pre newsletter

$title = $title ?? 'Overenie e-mailu';
$status = strtolower((string)($status ?? ''));
$csrfToken = $csrfToken ?? ($csrf ?? null);
$selector = $selector ?? null; // bezpečné: nechceme tlačiť validator do šablóny
$sent = !empty($sent);
$newsletter = (int)($newsletter ?? 0);

// small helper
function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
?>
<section class="auth-card verify-card" aria-labelledby="verify-title">
  <h1 id="verify-title"><?= e($title) ?></h1>

  <?php switch ($status):
    case 'invalid': ?>
      <div class="error">
        <strong>Neplatný overovací odkaz</strong>
        <p>Odkaz, ktorý ste použili, je nesprávny alebo bol poškodený. Skontrolujte prosím odkaz v e‑maile alebo požiadajte o nový overovací e‑mail.</p>
      </div>
    <?php break;

    case 'not_found_or_used': ?>
      <div class="error">
        <strong>Overovací záznam nebol nájdený</strong>
        <p>Nepodarilo sa nájsť overovací token. Môže byť už použitý alebo neexistuje.</p>
      </div>
    <?php break;

    case 'expired': ?>
      <div class="warning">
        <strong>Overovací odkaz vypršal</strong>
        <p>Overovací odkaz už nie je platný. Požiadajte, prosím, o zaslanie nového overovacieho e‑mailu.</p>
      </div>
    <?php break;

    case 'already_active': ?>
      <div class="notice">
        <strong>Účet je už aktívny</strong>
        <p>Váš účet je už aktivovaný. Prosím <a href="/eshop/login">prihláste sa</a>.</p>
      </div>
    <?php break;

    case 'success': ?>
      <div class="success">
        <strong>Ďakujeme — e‑mail overený</strong>
        <p>Váš e‑mail bol úspešne overený a účet aktivovaný.</p>
        <p><a class="btn" href="/login">Prejsť na prihlásenie</a></p>
        <?php if ($newsletter === 1): ?>
          <p class="small">Ďakujeme — vaša žiadosť o zasielanie noviniek bola zaznamenaná.</p>
        <?php else: ?>
          <p class="small">Chcete dostávať novinky? <a href="/newsletter/subscribe">Prihlásiť sa na odber</a>.</p>
        <?php endif; ?>
      </div>
    <?php break;

    case 'ready': ?>
      <div class="ready">
        <strong>Overenie e‑mailu pripravené</strong>
        <p>Ak potvrdíte, váš účet bude aktivovaný. Ak ste otvorili tento odkaz z e‑mailu, kliknite na tlačidlo „Potvrdiť".</p>
      </div>
    <?php break;

    case 'error':
    default: ?>
      <div class="error">
        <strong>Nastala chyba</strong>
        <p>Pri overovaní došlo k neočakávanej chybe. Skúste to prosím neskôr alebo nás <a href="/contact">kontaktujte</a>.</p>
      </div>
  <?php endswitch; ?>

  <!-- Ak je ready, zobrazíme potvrzovací POST formulár (vyžaduje $csrfToken a selector) -->
  <?php if ($status === 'ready'): ?>
    <?php if (!is_string($csrfToken) || $csrfToken === ''): ?>
      <div class="error"><strong>Interná chyba (chýba CSRF token)</strong></div>
    <?php else: ?>
      <form method="post" action="/eshop/verify" class="confirm-form" novalidate>
        <input type="hidden" name="csrf" value="<?= e($csrfToken) ?>">
        <?php if (is_string($selector) && $selector !== ''): ?>
          <input type="hidden" name="selector" value="<?= e($selector) ?>">
        <?php endif; ?>
        <!-- Poznámka: z bezpečnostných dôvodov sa neodporúča vkladať validator do šablóny. Ak ho chcete poslať, vložte ho tu ako hidden len ak prihliadate na riziká. -->
        <button type="submit" class="btn">Potvrdiť e‑mail</button>
      </form>
    <?php endif; ?>
  <?php endif; ?>

  <!-- Resend sekcia: zobrazíme ak nie success/already_active -->
  <?php if (!in_array($status, ['success','already_active','not_found_or_used'], true)): ?>
    <div class="resend-section">
      <?php if ($sent): ?>
        <div class="notice small">Na váš e‑mail sme odoslali nový overovací odkaz. Skontrolujte si schránku (aj spam).</div>
      <?php else: ?>
        <p>Ak ste overovací e‑mail neobdržali, môžete si ho nechať odoslať znova.</p>
        <form method="post" action="/eshop/actions/resend_verify" class="resend-form" novalidate>
          <?php if (is_string($csrfToken) && $csrfToken !== ''): ?>
            <input type="hidden" name="csrf" value="<?= e($csrfToken) ?>">
          <?php endif; ?>
          <?php if (is_string($selector) && $selector !== ''): ?>
            <input type="hidden" name="selector" value="<?= e($selector) ?>">
          <?php endif; ?>
          <button type="submit" class="btn">Znova odoslať overovací e‑mail</button>
        </form>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <p class="small muted">Máte problém? <a href="/contact">Kontaktujte nás</a>.</p>
</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const form = document.querySelector('.confirm-form');
  if (!form) return; // nic dělat, když formulář není na stránce

  // Vytvoříme element pro hlášky (pokud tam už není)
  let msgContainer = document.querySelector('.verify-message');
  if (!msgContainer) {
    msgContainer = document.createElement('div');
    msgContainer.className = 'verify-message';
    // umístíme těsně nad formulář
    form.parentNode.insertBefore(msgContainer, form);
  }

  function showMessage(text, type = 'error') {
    // type může být: 'success'|'notice'|'warning'|'error' — použití záleží na CSS
    msgContainer.textContent = text || (type === 'success' ? 'Hotovo.' : 'Došlo k chybě. Prosím zkuste to znovu.');
    msgContainer.setAttribute('role', 'alert');
    msgContainer.dataset.type = type;
    // jednoduchý vizuál pokud chcete; CSS třídy lze přidat podle potřeby
    msgContainer.classList.remove('success','notice','warning','error');
    msgContainer.classList.add(type);
  }

  form.addEventListener('submit', async function (ev) {
    ev.preventDefault();

    const submitBtn = form.querySelector('button[type="submit"]');
    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.setAttribute('aria-busy', 'true');
    }

    try {
      const resp = await fetch(form.action, {
        method: 'POST',
        body: new FormData(form),
        credentials: 'same-origin',
        headers: {
          // necháme browser nastavit správně Content-Type pro FormData
          'X-Requested-With': 'XMLHttpRequest'
        }
      });

      let data;
      try {
        data = await resp.json();
      } catch (_) {
        // nedefinovaný JSON — zobrazíme generickou chybu
        showMessage('Neplatná odpověď serveru. Prosím zkuste to znovu.', 'error');
        return;
      }

      // očekáváme pole { success: true|false, message?: string, ... }
      if (data && data.success === true) {
        // pro produkci necháme jen přesměrování bez "super-detailních" hlášek
        window.location.href = '/eshop/login';
        return;
      } else {
        // když success false nebo chybí, zobrazíme zprávu (pokud není, použijeme neurčitou)
        const msg = (data && data.message) ? String(data.message) : 'Overenie nebolo úspešné. Skúste to prosím znova.';
        showMessage(msg, 'error');
      }
    } catch (err) {
      // síťová chyba / fetch selhal
      showMessage('Chyba spojení so serverom. Skúste to prosím znova.', 'error');
    } finally {
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.removeAttribute('aria-busy');
      }
    }
  });
});
</script>

<style>
.auth-card { max-width:720px; margin:2rem auto; padding:1.5rem; background:#fff; border-radius:8px; box-shadow:0 6px 20px rgba(0,0,0,0.05); }
.verify-card h1 { margin-top:0; font-size:1.6rem; }
.success { border-left:4px solid #28a745; padding:0.6rem 1rem; background:#f6fffa; margin-bottom:1rem; }
.notice { border-left:4px solid #17a2b8; padding:0.6rem 1rem; background:#f3fbfd; margin-bottom:1rem; }
.warning { border-left:4px solid #ffc107; padding:0.6rem 1rem; background:#fff9e6; margin-bottom:1rem; }
.error { border-left:4px solid #dc3545; padding:0.6rem 1rem; background:#fff6f6; margin-bottom:1rem; }
.ready { border-left:4px solid #6f42c1; padding:0.6rem 1rem; background:#fbf8ff; margin-bottom:1rem; }
.btn { display:inline-block; padding:0.5rem 0.9rem; background:#007bff; color:#fff; text-decoration:none; border-radius:4px; border:0; cursor:pointer; }
.resend-section { margin-top:1rem; }
.small { font-size:0.9rem; }
.muted { color:#666; }
.confirm-form, .resend-form { margin-top:0.8rem; }
.verify-message { margin-bottom: 0.8rem; padding: 0.6rem 0.9rem; border-radius: 6px; font-size: 0.95rem; }
.verify-message.success { border-left: 4px solid #28a745; background: #f6fffa; color: #064e2b; }
.verify-message.notice  { border-left: 4px solid #17a2b8; background: #f3fbfd; color: #073642; }
.verify-message.warning { border-left: 4px solid #ffc107; background: #fff9e6; color: #6b4f00; }
.verify-message.error   { border-left: 4px solid #dc3545; background: #fff6f6; color: #61121a; }
</style>
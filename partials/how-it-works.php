<?php
// partials/how-it-works.php
if (!function_exists('esc_hiw')) {
    function esc_hiw($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
?>
<link rel="stylesheet" href="/css/how-it-works.css">

<section class="hiw-section" aria-label="Ako to funguje - Objednávka PDF">
  <div class="hiw-paper-wrap">
    <span class="hiw-grain-overlay" aria-hidden="true"></span>
    <span class="hiw-paper-edge" aria-hidden="true"></span>

    <div class="hiw-container">
      <header class="hiw-head">
        <h2 class="hiw-title">Ako to funguje</h2>
        <p class="hiw-sub">Objednáš PDF → dostaneš faktúru s QR → e-mail s odkazom na stiahnutie. Podporujeme babyboxy.</p>

        <div class="hiw-cta-wrap" aria-hidden="false">
          <a class="hiw-cta" href="/eshop" role="button" aria-label="Prejsť do e-shopu">Prejsť do e-shopu</a>
        </div>
      </header>

      <div class="hiw-steps" role="list" aria-describedby="hiw-sub">
        <article class="hiw-step" role="listitem" aria-labelledby="hiw-step-1">
          <div class="hiw-step-icon" aria-hidden="true">📚</div>
          <h3 id="hiw-step-1" class="hiw-step-title">Vyber knihu</h3>
          <p class="hiw-step-text">Rýchle vyhľadanie, jasné informácie o PDF a cene.</p>
        </article>

        <article class="hiw-step" role="listitem" aria-labelledby="hiw-step-2">
          <div class="hiw-step-icon" aria-hidden="true">🧾</div>
          <h3 id="hiw-step-2" class="hiw-step-title">Faktúra s QR</h3>
          <p class="hiw-step-text">Faktúra (PDF) so zabudovaným QR kódom pre evidenciu.</p>
        </article>

        <article class="hiw-step" role="listitem" aria-labelledby="hiw-step-3">
          <div class="hiw-step-icon" aria-hidden="true">💳</div>
          <h3 id="hiw-step-3" class="hiw-step-title">Platba a odkaz</h3>
          <p class="hiw-step-text">Po zaplatení ti príde e-mail s bezpečným odkazom na stiahnutie (alebo prílohou).</p>
        </article>

        <article class="hiw-step" role="listitem" aria-labelledby="hiw-step-4">
          <div class="hiw-step-icon" aria-hidden="true">🤝</div>
          <h3 id="hiw-step-4" class="hiw-step-title">Podpora</h3>
          <p class="hiw-step-text">Časť výťažku smeruje babyboxom — transparentne a pravidelne.</p>
        </article>
      </div>
    </div>
  </div>
</section>

<script src="/js/how-it-works.js" defer></script>
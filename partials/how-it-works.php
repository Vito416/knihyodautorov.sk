<?php
// partials/how-it-works.php
if (!function_exists('esc_hiw')) {
    function esc_hiw($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
?>
<link rel="stylesheet" href="/css/how-it-works.css">

<section class="style-section" aria-label="Ako to funguje - Objednávka PDF">
  <div class="paper-wrap">
    <span class="paper-grain-overlay" aria-hidden="true"></span>
    <span class="paper-edge" aria-hidden="true"></span>

    <div class="blank-container">

<div class="central-steps" role="list" aria-describedby="hiw-sub">
  <article class="hiw-step arrow1-down" role="listitem" aria-labelledby="hiw-step-1">
    <div class="hiw-step-icon" aria-hidden="true">
    <!-- ikona knihy -->
    <img src="/assets/kniha.png" alt="ikona knihy">
    </div>
    <h3 id="hiw-step-1" class="hiw-step-title hiw-text-1">Nájdi svoju knihu</h3>
    <p class="hiw-step-text hiw-text-1">Objav príbeh, ktorý ťa osloví — jasné informácie o diele a cene.</p>
  </article>

  <article class="hiw-step arrow-up" role="listitem" aria-labelledby="hiw-step-2">
    <h3 id="hiw-step-2" class="hiw-step-title hiw-text-2">Pergamen s pečaťou</h3>
    <p class="hiw-step-text hiw-text-2">Získaš faktúru (PDF) so zabudovaným QR kódom — oficiálny doklad k tvojmu výberu.</p>
    <div class="hiw-step-icon" aria-hidden="true">
    <!-- ikona pečate -->
    <img src="/assets/pecat.png" alt="ikona pečate">
    </div>
  </article>

  <article class="hiw-step arrow2-down" role="listitem" aria-labelledby="hiw-step-3">
    <div class="hiw-step-icon" aria-hidden="true">
    <!-- ikona platby -->
    <img src="/assets/mince.png" alt="ikona mince">
    </div>
    <h3 id="hiw-step-3" class="hiw-step-title hiw-text-3">Platba a odkaz</h3>
    <p class="hiw-step-text hiw-text-3">Po zaplatení ti príde magický odkaz na stiahnutie — priamo do e-mailu.</p>
  </article>

  <article class="hiw-step arrow-up" role="listitem" aria-labelledby="hiw-step-4">
    <h3 id="hiw-step-4" class="hiw-step-title hiw-text-4">Podpora dobra</h3>
    <p class="hiw-step-text hiw-text-4">Časť výťažku putuje na podporu babyboxov — pravidelne a transparentne.</p>
    <div class="hiw-step-icon" aria-hidden="true">
    <!-- ikona kalich -->
    <img src="/assets/kalich.png" alt="ikona kalich">
    </div>
  </article>
</div>
    </div>
  </div>
</section>

<script src="/js/how-it-works.js" defer></script>
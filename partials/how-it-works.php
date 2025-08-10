<?php
// partials/how-it-works.php
// Epická "How it works" sekcia - statická, ale plnohodnotná.
// Zahŕňa link na svoj CSS a script na svoj JS (defer).

// bezpečné escaper funkcie (unikátne meno, aby sa neprekrývalo)
if (!function_exists('esc_hiw')) {
    function esc_hiw($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

// Niekoľko poznámok: CSS a JS sú načítané lokálne; uprav cesty ak máš iné.
?>
<link rel="stylesheet" href="/css/how-it-works.css">

<section class="hiw-section" aria-label="Ako to funguje - Knihy od autorov">
  <div class="hiw-paper-wrap">
    <span class="hiw-grain-overlay" aria-hidden="true"></span>
    <span class="hiw-paper-edge" aria-hidden="true"></span>

    <div class="hiw-container">
      <header class="hiw-head">
        <h2 class="hiw-title">Ako to funguje</h2>
        <p class="hiw-sub">Jednoducho — vyber, zaplať, stiahni. Časť výťažku putuje na podporu babyboxov.</p>
      </header>

      <div class="hiw-steps" role="list">
        <article class="hiw-step" role="listitem" aria-labelledby="hiw-step-1">
          <div class="hiw-step-icon">📚</div>
          <h3 id="hiw-step-1" class="hiw-step-title">Vyber si knihu</h3>
          <p class="hiw-step-text">Prehľadné kategórie, odporúčania a náhodné promo tituly — rýchlo nájdeš, čo chceš.</p>
        </article>

        <article class="hiw-step" role="listitem" aria-labelledby="hiw-step-2">
          <div class="hiw-step-icon">💳</div>
          <h3 id="hiw-step-2" class="hiw-step-title">Bezpečná platba</h3>
          <p class="hiw-step-text">Platba kartou alebo cez PayPal. Bezpečné, šifrované platobné brány.</p>
        </article>

        <article class="hiw-step" role="listitem" aria-labelledby="hiw-step-3">
          <div class="hiw-step-icon">⬇️</div>
          <h3 id="hiw-step-3" class="hiw-step-title">Okamžité stiahnutie</h3>
          <p class="hiw-step-text">Po zaplatení získaš okamžité odkazy na stiahnutie PDF v zložke <code>books-pdf/</code>.</p>
        </article>

        <article class="hiw-step" role="listitem" aria-labelledby="hiw-step-4">
          <div class="hiw-step-icon">🤝</div>
          <h3 id="hiw-step-4" class="hiw-step-title">Podpora dobrých vecí</h3>
          <p class="hiw-step-text">Časť príjmov venujeme babyboxom a charitatívnym projektom — transparentne.</p>
        </article>
      </div>
    </div>
  </div>
</section>

<script src="/js/how-it-works.js" defer></script>

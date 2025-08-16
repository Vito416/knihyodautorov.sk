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

<div class="hiw-steps" role="list" aria-describedby="hiw-sub">
  <article class="hiw-step" role="listitem" aria-labelledby="hiw-step-1">
    <div class="hiw-step-icon" aria-hidden="true">
<svg viewBox="0 0 24 24" width="48" height="48" role="img" aria-hidden="true">
  <g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
    <!-- obrys knihy -->
    <path d="M4 5h7a3 3 0 0 1 3 3v11H7a3 3 0 0 0-3 3z"/>
    <path d="M20 5h-7a3 3 0 0 0-3 3v11h7a3 3 0 0 1 3 3z"/>
    <!-- středová dělicí linka (hřbet) -->
    <path d="M12 5v16"/>
    <!-- pár linek – náznak textu -->
    <path d="M8 9h2M8 12h2M8 15h2"/>
    <path d="M14 9h2M14 12h2M14 15h2"/>
  </g>
</svg>


    </div>
    <h3 id="hiw-step-1" class="hiw-step-title">Vyber knihu</h3>
    <p class="hiw-step-text">Rýchle vyhľadanie, jasné informácie o PDF a cene.</p>
  </article>

  <article class="hiw-step" role="listitem" aria-labelledby="hiw-step-2">
    <div class="hiw-step-icon" aria-hidden="true">
<svg viewBox="0 0 24 24" width="48" height="48" role="img" aria-hidden="true">
  <g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
    <!-- list papíru s ohnutým rohem -->
    <path d="M6 3h9l5 5v13a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/>
    <path d="M15 3v5h5"/>
    
    <!-- textové linky faktury -->
    <path d="M8 9h6"/>
    <path d="M8 12h4"/>
    
    <!-- QR symbol (stylizovaný – tři čtverečky) -->
    <rect x="13.5" y="14" width="2.5" height="2.5" rx="0.2"/>
    <rect x="17" y="14" width="2.5" height="2.5" rx="0.2"/>
    <rect x="13.5" y="17.5" width="2.5" height="2.5" rx="0.2"/>
  </g>
</svg>

    </div>
    <h3 id="hiw-step-2" class="hiw-step-title">Faktúra s QR</h3>
    <p class="hiw-step-text">Faktúra (PDF) so zabudovaným QR kódom pre evidenciu.</p>
  </article>

  <article class="hiw-step" role="listitem" aria-labelledby="hiw-step-3">
    <div class="hiw-step-icon" aria-hidden="true">

<svg viewBox="0 0 24 24" width="48" height="48" role="img" aria-hidden="true">
  <g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
    <!-- ruka -->
    <path d="M3 16c1.5-1 3-1 4.5 0l2.5 2h5a2 2 0 0 0 2-2c0-1-1-2-2-2h-3"/>
    <!-- mince -->
    <circle cx="9" cy="9" r="2"/>
    <!-- list / odkaz -->
    <rect x="16" y="7" width="5" height="6" rx="1"/>
    <path d="M16 10h5"/>
  </g>
</svg>



    </div>
    <h3 id="hiw-step-3" class="hiw-step-title">Platba a odkaz</h3>
    <p class="hiw-step-text">Po zaplatení ti príde e-mail s bezpečným odkazom na stiahnutie (alebo prílohou).</p>
  </article>

  <article class="hiw-step" role="listitem" aria-labelledby="hiw-step-4">
    <div class="hiw-step-icon" aria-hidden="true">

<!-- PODPORA -->
<svg viewBox="0 0 24 24" width="1em" height="1em" role="img" aria-hidden="true">
  <g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
    <!-- srdce -->
    <path d="M12 7c0-1.5 1-2.5 2.5-2.5S17 5.5 17 7c0 1-0.5 1.7-1.2 2.3L12 12l-3.8-2.7C7.5 8.7 7 8 7 7c0-1.5 1-2.5 2.5-2.5S12 5.5 12 7z"/>
  </g>
</svg>
    </div>
    <h3 id="hiw-step-4" class="hiw-step-title">Podpora</h3>
    <p class="hiw-step-text">Časť výťažku smeruje babyboxom — transparentne a pravidelne.</p>
  </article>
</div>
    </div>
  </div>
</section>

<script src="/js/how-it-works.js" defer></script>
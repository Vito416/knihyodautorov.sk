<?php
declare(strict_types=1);

/**
 * reklamacie.php
 * Static complaints / returns policy handler.
 */

$article = <<<'HTML'
<article id="reklamacie-article">
  <h1>Reklamačný poriadok</h1>

  <p><strong>Prevádzkovateľ:</strong> Black Cat Academy s. r. o., Dolná ulica 1C, Kunerad 013 13. Kontakt: info@knihyodautorov.sk.</p>

  <h2>1. Úvod</h2>
  <p>Tento reklamačný poriadok upravuje postup uplatnenia práv zo zodpovednosti za vady tovaru zakúpeného v e-shope <strong>knihyodautorov.sk</strong>. Vzťahuje sa predovšetkým na spotrebiteľov (fyzické osoby nakupujúce mimo svojej podnikateľskej činnosti).</p>

  <h2>2. Lehota na uplatnenie vady</h2>
  <p>Spotrebiteľ môže uplatniť práva zo zodpovednosti za vady za podmienok a v lehotách stanovených príslušnými právnymi predpismi (spravidla 24 mesiacov pri novom tovare). Vadu treba predávajúcemu vytknúť bez zbytočného odkladu po jej zistení, najneskôr však v lehote vyplývajúcej zo zákona.</p>

  <h2>3. Postup pre uplatnenie reklamácie</h2>
  <ol>
    <li>Reklamáciu oznámte e-mailom na <strong>info@knihyodautorov.sk</strong> alebo poštou na adresu sídla spoločnosti.</li>
    <li>Uveďte Vaše kontaktné údaje, číslo objednávky (alebo dátum nákupu), popis vady a prípadne priložte fotografiu vady.</li>
    <li>Ak je potrebné zaslať reklamovaný tovar, po dohode zašlite balík na adresu prevádzkovateľa — neposielajte zásielku na dobierku, pokiaľ nie je dohodnuté inak.</li>
  </ol>

  <h2>4. Lehota na vybavenie reklamácie</h2>
  <p>Po obdržaní reklamácie predávajúci bezodkladne potvrdí jej prijatie e-mailom a oznámi predpokladanú lehotu vybavenia. Predpokladaná štandardná lehota na vybavenie reklamácie je 30 dní; ak je potrebné lehotu predĺžiť z objektívnych dôvodov, predávajúci o tom kupujúceho informuje a oznámi nový termín.</p>

  <h2>5. Spôsoby vybavenia reklamácie</h2>
  <p>Možné riešenia reklamácie sú:</p>
  <ul>
    <li>oprava tovaru;</li>
    <li>výmena tovaru za nový kus;</li>
    <li>primeraná zľava z ceny;</li>
    <li>odstúpenie od zmluvy a vrátenie kúpnej ceny, ak nie je možné odstrániť vadu v primeranej lehote alebo opakovane dochádza k vade.</li>
  </ul>

  <h2>6. Reklamácie digitálneho obsahu (PDF)</h2>
  <p>Pri uplatnení reklamácie digitálneho obsahu sa posudzuje, či obsah zodpovedá popisu, či je súbor čitateľný, kompletný a bez chýb. Ak spotrebiteľ súhlasil so začatím dodávky digitálneho obsahu pred uplynutím zákonnej lehoty na odstúpenie, stráca právo odstúpiť od zmluvy pre tento obsah, avšak reklamácia z dôvodu vady obsahu (napr. poškodený súbor) zostáva možná.</p>

  <h2>7. Náklady spojené s reklamáciou</h2>
  <p>Ak je reklamácia uznaná ako oprávnená (vada na tovare), predávajúci uhradí primerané náklady spojené s prepravou reklamovaného tovaru k predávajúcemu a späť. V prípade neopodstatnenej reklamácie si predávajúci vyhradzuje právo požadovať náhradu nákladov spojených s posúdením reklamácie.</p>

  <h2>8. Doklady potrebné pri reklamácii</h2>
  <ul>
    <li>doklad o kúpe (faktúra, potvrdenie o platbe alebo iný doklad);</li>
    <li>opis vady a dátum zistenia;</li>
    <li>kontakt na kupujúceho;</li>
    <li>fotografie vady (ak sú k dispozícii).</li>
  </ul>

  <h2>9. Kontakt pri riešení sporov</h2>
  <p>Ak sa nepodarí spor vyriešiť mimosúdnou dohodou, spotrebiteľ má právo obrátiť sa na orgány ochrany spotrebiteľa alebo na príslušný súd podľa platných právnych predpisov.</p>

  <p><em>Posledná aktualizácia: [DÁTUM]</em></p>
</article>
HTML;

$fullHtml = '<article class="page-article legal-article container"><header class="page-header"><h1>Reklamačný poriadok</h1></header><div class="page-content">' . $article . '</div></article>';

return [
    'template' => null,
    'vars' => [],
    'content' => $fullHtml,
];
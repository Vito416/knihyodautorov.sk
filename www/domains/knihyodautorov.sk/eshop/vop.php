<?php
declare(strict_types=1);

/**
 * vop.php
 * Static Terms & Conditions page handler.
 */

$article = <<<'HTML'
<article id="vop-article">
  <h1>Všeobecné obchodné podmienky (VOP)</h1>

  <p><strong>Predávajúci:</strong> Black Cat Academy s. r. o.<br>
     <strong>Sídlo:</strong> Dolná ulica 1C, Kunerad 013 13<br>
     <strong>IČO:</strong> 55 396 461<br>
     <strong>Kontakt:</strong> info@knihyodautorov.sk, +421 901 770 666</p>

  <h2>1. Úvodné ustanovenia</h2>
  <p>Tieto Všeobecné obchodné podmienky (ďalej len „VOP“) upravujú práva a povinnosti medzi predávajúcim (Black Cat Academy s. r. o.) a kupujúcim pri nákupe tovaru a digitálneho obsahu v internetovom obchode <strong>knihyodautorov.sk</strong>.</p>

  <h2>2. Objednávka a uzavretie zmluvy</h2>
  <p>Objednávku je možné podať bez registrácie alebo po registrácii. Zmluva o kúpe tovaru je uzavretá okamihom, keď predávajúci potvrdí prijatie objednávky e-mailom kupujúcemu, ak nie je v objednávke uvedené inak.</p>

  <h2>3. Ceny a platobné podmienky</h2>
  <p>Ceny tovaru sú uvádzané v EUR a zahŕňajú zákonom stanovenú DPH, ak je uplatniteľná. Dostupné spôsoby platby (aktuálne):</p>
  <ul>
    <li>online platba kartou cez platobnú bránu (platobný partner);</li>
    <li>bankový prevod na účet predávajúceho;</li>
    <li>platba pri prevzatí (dobierka) — ak je systémom podporovaná.</li>
  </ul>
  <p>Digitálny obsah (PDF) je spravidla sprístupnený po úspešnej platbe alebo okamžite po potvrdení objednávky, podľa nastavenia obchodu.</p>

  <h2>4. Dodanie tovaru</h2>
  <p>Fyzické knihy dodávame prostredníctvom kuriéra alebo poštou na doručovaciu adresu uvedenú v objednávke. Termíny doručenia a náklady sú uvedené pri konkrétnej objednávke.</p>
  <p>Digitálne produkty (PDF) odosielame ako odkaz na stiahnutie e-mailom alebo poskytujeme priamy prístup v užívateľskom účte kupujúceho.</p>

  <h2>5. Odstúpenie od zmluvy</h2>
  <p>Spotrebiteľ (fyzická osoba, ktorá nejedná v rámci svojej podnikateľskej činnosti) má právo odstúpiť od zmluvy uzavretej na diaľku bez udania dôvodu v lehote ustanovenej právnou úpravou. Výnimky a pravidlá sú upravené zákonom o ochrane spotrebiteľa.</p>
  <p><strong>Dôležité pre digitálny obsah:</strong> Ak spotrebiteľ pred začiatkom dodania digitálneho obsahu výslovne súhlasí so začatím dodávky a potvrdí, že tým stráca právo na odstúpenie, predávajúci môže okamžite začať s poskytnutím digitálneho obsahu a spotrebiteľ tak stráca právo na odstúpenie pre tento obsah (v rozsahu transpozície príslušnej legislatívy).</p>

  <h2>6. Reklamačné práva a vady</h2>
  <p>Práva zodpovednosti za vady tovaru sú upravené príslušnými zákonmi. Spotrebiteľ môže uplatniť vady tovaru v zákonných lehotách. Postup uplatnenia reklamácie je popísaný v Reklamačnom poriadku.</p>

  <h2>7. Ochrana osobných údajov</h2>
  <p>Podrobnosti o spracovaní osobných údajov sú uvedené v Politike ochrany osobných údajov (GDPR).</p>

  <h2>8. Rozhodné právo a riešenie sporov</h2>
  <p>Všetky právne vzťahy medzi predávajúcim a kupujúcim sa riadia právnym poriadkom Slovenskej republiky. Spotrebiteľ má právo obrátiť sa na orgány ochrany spotrebiteľa, prípadne na súd podľa platných právnych predpisov.</p>

  <p><em>Posledná aktualizácia: [DÁTUM]</em></p>
</article>
HTML;

$fullHtml = '<article class="page-article legal-article container"><header class="page-header"><h1>Všeobecné obchodné podmienky</h1></header><div class="page-content">' . $article . '</div></article>';

return [
    'template' => null,
    'vars' => [
        'navActive' => 'vop',
    ],
    'content' => $fullHtml,
];
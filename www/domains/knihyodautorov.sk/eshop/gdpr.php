<?php
declare(strict_types=1);

/**
 * gdpr.php
 * Static GDPR page handler for front controller.
 * Returns: ['template' => 'pages/static.php', 'vars' => [...]]
 */

$article = <<<'HTML'
<article id="gdpr-article">
  <h1>Ochrana osobných údajov (GDPR)</h1>

  <p><strong>Prevádzkovateľ:</strong> Black Cat Academy s. r. o.<br>
     <strong>Sídlo:</strong> Dolná ulica 1C, Kunerad 013 13<br>
     <strong>IČO:</strong> 55 396 461<br>
     <strong>Kontakt:</strong> info@knihyodautorov.sk, +421 901 770 666</p>

  <h2>1. Úvod</h2>
  <p>Tento dokument popisuje, aké osobné údaje spracúvame v súvislosti s prevádzkovaním e-shopu <strong>knihyodautorov.sk</strong>, na aké účely ich spracúvame, aké sú právne základy spracovania a aké máte práva v súvislosti s vašimi osobnými údajmi. Spracúvanie prebieha v súlade s Nariadením Európskeho parlamentu a Rady (EÚ) 2016/679 (GDPR) a príslušnou slovenskou legislatívou.</p>

  <h2>2. Prevádzkovateľ a kontakty</h2>
  <p>Prevádzkovateľ: Black Cat Academy s. r. o., Dolná ulica 1C, Kunerad 013 13. Pre záležitosti týkajúce sa ochrany osobných údajov kontaktujte prevádzkovateľa na e-maile <strong>info@knihyodautorov.sk</strong>.</p>

  <h2>3. Zhromažďované údaje</h2>
  <ul>
    <li><strong>Identifikačné a kontaktné údaje</strong>: meno, priezvisko, e-mail, telefón, doručovacia a fakturačná adresa.</li>
    <li><strong>Údaje súvisiace s objednávkou</strong>: objednané položky, dátumy objednávok, história nákupov, stav objednávky, platobné informácie nevyhnutné na realizáciu transakcie (spravidla spracované poskytovateľom platobnej brány).</li>
    <li><strong>Technické a prevádzkové údaje</strong>: IP adresa, údaje o používaní stránky, cookies, údaje z analytiky.</li>
    <li><strong>Údaje pre účtovníctvo a daňové účely</strong>: fakturačné údaje, IČO / DIČ ak sú poskytnuté.</li>
    <li><strong>Voliteľné údaje</strong>: súhlasy so zasielaním marketingu, preferencie a hodnotenia produktov.</li>
  </ul>

  <h2>4. Účely spracovania a právne základy</h2>
  <p>Spracúvame osobné údaje za účelmi:</p>
  <ul>
    <li>plnenia zmluvy (spracovanie objednávky a doručenie tovaru) — právny základ: plnenie zmluvy (čl. 6 ods. 1 písm. b GDPR);</li>
    <li>plnenia zákonných povinností (uchovávanie účtovných dokladov, daňové povinnosti) — právny základ: zákonná povinnosť;</li>
    <li>zasielania marketingových oznámení — právny základ: výslovný súhlas dotknutej osoby (čl. 6 ods. 1 písm. a GDPR), pokiaľ nie je inak povolené zákonom;</li>
    <li>prevádzky webu, bezpečnosti a analýzy používania — právny základ: oprávnený záujem prevádzkovateľa (čl. 6 ods. 1 písm. f GDPR), ak nie sú dotknuté základné práva a slobody dotknutých osôb.</li>
  </ul>

  <h2>5. Príjemcovia údajov</h2>
  <p>Osobné údaje môžeme zdieľať s:</p>
  <ul>
    <li>poskytovateľmi platobných služieb a platobnými bránami (pre spracovanie platieb);</li>
    <li>dopravcami a kuriérskymi službami (pre doručenie fyzických zásielok);</li>
    <li>poskytovateľmi hostingových a IT služieb (na základe zmluvy o spracovaní osobných údajov);</li>
    <li>štátnymi orgánmi, ak to vyžaduje zákon (napr. daňové orgány).</li>
  </ul>

  <h2>6. Prenos do tretích krajín</h2>
  <p>Ak dôjde k prenosu osobných údajov mimo EÚ/EHP, vykonávame to iba za podmienok zabezpečenia primeranej úrovne ochrany (rozhodnutie Komisii o primeranosti, štandardné zmluvné doložky alebo iné zákonné mechanizmy). O prenose do tretích krajín vás informujeme, ak to bude relevantné.</p>

  <h2>7. Doba uchovávania</h2>
  <p>Údaje uchovávame len po dobu potrebnú na dosiahnutie účelu spracovania alebo po dobu vyžadovanú právnymi predpismi (napr. účtovné doklady podľa daňových predpisov). Po uplynutí tejto doby sú údaje bezpečne vymazané alebo anonymizované.</p>

  <h2>8. Práva dotknutej osoby</h2>
  <p>Máte právo:</p>
  <ul>
    <li>požiadať o prístup k osobným údajom;</li>
    <li>žiadať o opravu (rectification) nepresných údajov;</li>
    <li>žiadať o vymazanie údajov („právo byť zabudnutý“) v prípadoch stanovených GDPR;</li>
    <li>požiadať o obmedzenie spracovania;</li>
    <li>vzniesť námietku proti spracovaniu na základe oprávneného záujmu;</li>
    <li>uplatniť právo na prenositeľnosť údajov (data portability);</li>
    <li>podať sťažnosť dozornému orgánu — Úrad na ochranu osobných údajov SR.</li>
  </ul>

  <p>Kontakt na dozorný orgán: Úrad na ochranu osobných údajov SR (informácie dostupné na ich oficiálnej webovej stránke).</p>

  <h2>9. Cookies</h2>
  <p>Na tejto webovej stránke používame iba technicky nevyhnutné cookies, ktoré sú potrebné na správne fungovanie e-shopu (napríklad uchovanie obsahu košíka alebo zabezpečenie prihlásenia). Tieto cookies nezhromažďujú osobné údaje na marketingové ani analytické účely.</p>

  <h2>10. Bezpečnosť</h2>
  <p>Implementujeme technické a organizačné opatrenia na zabezpečenie osobných údajov (TLS/HTTPS, obmedzenie prístupov, šifrovanie hesiel, zálohovanie, aktualizácie softvéru). Napriek tomu neexistuje úplné riziko — preto odporúčame mať silné heslá a nezdieľať prihlasovacie údaje.</p>

  <h2>11. Zmeny politiky</h2>
  <p>Politiku ochrany údajov môžeme priebežne aktualizovať. Pri podstatných zmenách vás upozorníme a uverejníme dátum poslednej aktualizácie.</p>

  <p><em>Posledná aktualizácia: [DÁTUM]</em></p>
</article>
HTML;

$fullHtml = '<article class="page-article legal-article container"><header class="page-header"><h1>Ochrana osobných údajov</h1></header><div class="page-content">' . $article . '</div></article>';

return [
    'template' => null,
    'vars' => [],
    'content' => $fullHtml,
];
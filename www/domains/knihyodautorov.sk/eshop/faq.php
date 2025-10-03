<?php
declare(strict_types=1);

/**
 * faq.php
 * Static FAQ page handler for front controller.
 * Returns: ['template' => null, 'vars' => [...], 'content' => '...']
 *
 * Jazyk: slovenčina (zhodné s ostatními stránkami v projekte)
 */

$article = <<<'HTML'
<article id="faq-article" class="page-article container">
  <header class="page-header">
    <h1>Často kladené otázky (FAQ)</h1>
    <p class="lead">Najčastejšie otázky a rýchle odpovede k nákupu, dodaniu, digitálnemu obsahu a podpori.</p>
  </header>

  <section class="page-content">
    <div class="faq-list" style="display:flex;flex-direction:column;gap:18px;max-width:900px;">

      <div class="faq-item">
        <h2>1. Ako objednať knihu?</h2>
        <p>Vyberte požadovaný titul, zvoľte počet kusov a kliknite na "Pridať do košíka". Pre zrealizovanie objednávky prejdite do košíka a dokončite objednávku cez pokladňu. Objednať je možné aj bez registrácie.</p>
      </div>

      <div class="faq-item">
        <h2>2. Aké sú možnosti platby?</h2>
        <p>Akceptujeme platby kartou cez platobnú bránu, bankovým prevodom a (ak je povolené) dobierkou. Pri digitálnom obsahu je prístup často sprístupnený okamžite po pripísaní platby.</p>
      </div>

      <div class="faq-item">
        <h2>3. Koľko trvá doručenie?</h2>
        <p>Doručenie závisí od spôsobu dopravy a miestnych podmienok. Bežná doba doručenia na Slovensku je 2–5 pracovných dní (uvedené pri produkte môže byť konkrétnejšie). Ak ide o predobjednávku, termín je označený pri produkte.</p>
      </div>

      <div class="faq-item">
        <h2>4. Ako dostanem digitálny produkt (PDF)?</h2>
        <p>Po úspešnej platbe obdržíte e-mail s odkazom na stiahnutie a/alebo prístup k súboru vo vašom užívateľskom účte. Skontrolujte aj spam priečinok. Ak odkaz nefunguje, kontaktujte podporu a uveďte číslo objednávky.</p>
      </div>

      <div class="faq-item">
        <h2>5. Môžem zrušiť alebo upraviť objednávku?</h2>
        <p>Ak objednávka ešte nebola spracovaná, je možné ju upraviť alebo zrušiť — kontaktujte nás čo najskôr a uveďte číslo objednávky. Ak už bola objednávka odoslaná alebo bol digitálny obsah sprístupnený, procedúra sa riadi VOP a zákonnými lehotami.</p>
      </div>

      <div class="faq-item">
        <h2>6. Ako reklamovať chybný alebo poškodený tovar?</h2>
        <p>Postup reklamácie nájdete v sekcii <a href="/eshop/reklamacie">Reklamácie a vrátenie tovaru</a>. Zvyčajne potrebujeme číslo objednávky, popis chyby a fotografiu/scan dokladu. Pre digitálne produkty uveďte detail chyby a prípadné screenshoty.</p>
      </div>

      <div class="faq-item">
        <h2>7. Koľko stojí doprava?</h2>
        <p>Cena dopravy sa počíta pri vytváraní objednávky — závisí od hmotnosti, rozmerov a zvoleného dopravcu. Presné náklady sú zobrazené pred potvrdením platby.</p>
      </div>

      <div class="faq-item">
        <h2>8. Môžem získať faktúru alebo opravu faktúry?</h2>
        <p>Áno — faktúru zasielame spolu s potvrdením objednávky. Ak potrebujete opravu faktúry, kontaktujte fakturačné oddelenie (uveďte číslo objednávky a požadované zmeny).</p>
      </div>

      <div class="faq-item">
        <h2>9. Som z inej krajiny — dodávate medzinárodne?</h2>
        <p>Doručujeme aj do zahraničia (ak je táto možnosť uvedená pri produkte). Pri medzinárodnom doručení môžu vzniknúť ďalšie poplatky (clo, DPH podľa miestnych predpisov) — kupujúci je zodpovedný za colné a daňové povinnosti vo svojej krajine.</p>
      </div>

      <div class="faq-item">
        <h2>10. Prečo som nedostal e-mail s potvrdením?</h2>
        <p>Skontrolujte spam a propagačný priečinok. Uistite sa, že e-mail je zadaný správne. Ak stále nič neprichádza, kontaktujte nás cez <a href="/eshop/contact">kontaktný formulár</a> s číslom objednávky a my to preveríme.</p>
      </div>

      <div class="faq-item">
        <h2>11. Ako sa odhlásiť z newslettera?</h2>
        <p>V každom newslettri je link na odhlásenie (unsubscribe). Alternatívne nás môžete požiadať o zrušenie zasielania cez <a href="/eshop/contact">kontaktný formulár</a> a uveďte e-mail, ktorý chcete odstrániť.</p>
      </div>

      <div class="faq-item">
        <h2>12. Aké sú podmienky vrátenia peňazí za digitálny obsah?</h2>
        <p>Pre digitálny obsah platia osobitné pravidlá: ak ste pred začatím dodania výslovne súhlasili so začatím dodávky a stratou práva na odstúpenie, reklamácie sa riešia podľa VOP a zákonných náležitostí. Vždy nás kontaktujte s podrobným popisom problému.</p>
      </div>

      <div class="faq-item">
        <h2>13. Ako nahlásiť technický problém so stiahnutím súboru?</h2>
        <p>Pošlite nám e-mail alebo použite kontaktný formulár — uveďte číslo objednávky, názov súboru, typ chyby (nefunkčný odkaz, poškodený PDF) a screenshoty. Pre rýchle riešenie priložte error logy, ak sú dostupné.</p>
      </div>

      <div class="faq-item">
        <h2>14. Aké formáty súborov posielate?</h2>
        <p>Digitálny obsah dodávame prevažne vo formáte PDF. Ak je k dispozícii iný formát (ePub, MOBI), bude to uvedené pri produkte.</p>
      </div>

      <div class="faq-item">
        <h2>15. Ako dlho uchovávate osobné údaje z objednávok?</h2>
        <p>Údaje uchovávame po dobu potrebnú na účely účtovníctva a plnenia zákonných povinností (napríklad daňové doklady). Podrobnosti nájdete v <a href="/eshop/gdpr">Politike ochrany osobných údajov</a>.</p>
      </div>

      <div class="faq-item">
        <h2>16. Nevidím v ponuke konkrétny titul — môžete ho dodať?</h2>
        <p>Ak titul v e-shope nie je, môžete nás kontaktovať s požiadavkou. Pre väčšinu titulov vieme doobjednať alebo odporučiť ekvivalentné vydanie.</p>
      </div>

      <div class="faq-item">
        <h2>17. Mám darčekový poukaz alebo zľavový kód — ako ho uplatním?</h2>
        <p>Pri pokladni je pole pre zadanie zľavového kódu. Darčekové poukazy a zľavy môžu mať špecifické podmienky (platnosť, kombinácia s inými zľavami) — informácie sú zvyčajne uvedené pri poukaze alebo v e-maile.</p>
      </div>

      <div class="faq-item">
        <h2>18. Kde nájdem obchodné podmienky a reklamačný poriadok?</h2>
        <p>Všetky právne dokumenty nájdete v patičke stránky: <a href="/eshop/vop">Všeobecné obchodné podmienky (VOP)</a>, <a href="/eshop/gdpr">GDPR</a>, <a href="/eshop/reklamacie">Reklamácie</a>.</p>
      </div>

      <div class="faq-item">
        <h2>19. Ako kontaktovať zákaznícku podporu?</h2>
        <p>Najrýchlejšie cez <a href="/eshop/contact">kontaktný formulár</a> s uvedením čísla objednávky. V e-maily uveďte čo najviac detailov (číslo objednávky, názov titulu, screenshoty). Telefón: <a href="tel:+421901770666">+421 901 770 666</a>.</p>
      </div>

    </div>

    <footer style="margin-top:24px;">
      <p style="font-size:0.9em;color:#555;">Posledná aktualizácia: <em>[DÁTUM]</em></p>
    </footer>
  </section>

  <!-- Structured data: FAQPage -->
  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@type": "FAQPage",
    "mainEntity": [
      {
        "@type": "Question",
        "name": "Ako objednať knihu?",
        "acceptedAnswer": {
          "@type": "Answer",
          "text": "Vyberte požadovaný titul, zvoľte počet kusov a kliknite na 'Pridať do košíka'. Pre zrealizovanie objednávky prejdite do košíka a dokončite objednávku cez pokladňu. Objednať je možné aj bez registrácie."
        }
      },
      {
        "@type": "Question",
        "name": "Aké sú možnosti platby?",
        "acceptedAnswer": {
          "@type": "Answer",
          "text": "Akceptujeme platby kartou cez platobnú bránu, bankovým prevodom a (ak je povolené) dobierkou. Pri digitálnom obsahu je prístup často sprístupnený okamžite po pripísaní platby."
        }
      },
      {
        "@type": "Question",
        "name": "Koľko trvá doručenie?",
        "acceptedAnswer": {
          "@type": "Answer",
          "text": "Doručenie závisí od spôsobu dopravy a miestnych podmienok. Bežná doba doručenia na Slovensku je 2–5 pracovných dní (uvedené pri produkte môže byť konkrétnejšie)."
        }
      }
      /* Pozn.: ak chceš, môžem doplniť všetky otázky do JSON-LD (zväčší velikost) */
    ]
  }
  </script>

</article>
HTML;

$fullHtml = '<article class="page-article legal-article container"><header class="page-header"><h1>FAQ — Často kladené otázky</h1></header><div class="page-content">' . $article . '</div></article>';

return [
    'template' => null,
    'vars' => [],
    'content' => $fullHtml,
];
<?php
// templates/pages/privacy.php
// Expects:
//  - $pageTitle (string|null)
//  - $navActive (string|null)
//
// Uses partials: header.php, footer.php

$pageTitle = isset($pageTitle) ? (string)$pageTitle : 'Ochrana osobních údajů';
$navActive = $navActive ?? null;

$partialsDir = __DIR__ . '/../partials';
include $partialsDir . '/header.php';
?>
<article class="page-privacy">
    <h1><?= htmlspecialchars($pageTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>

    <section>
        <h2>1. Správce osobních údajů</h2>
        <p>Správcem osobních údajů je provozovatel tohoto e-shopu. Kontaktní údaje naleznete v sekci kontakt.</p>
    </section>

    <section>
        <h2>2. Shromažďované údaje</h2>
        <p>Shromažďujeme pouze údaje nezbytné pro vyřízení objednávky a poskytování zákaznické podpory, zejména jméno, adresu, e-mail a telefonní číslo.</p>
    </section>

    <section>
        <h2>3. Účel zpracování</h2>
        <p>Osobní údaje zpracováváme za účelem uzavření a plnění kupní smlouvy, plnění zákonných povinností a zajištění oprávněných zájmů správce.</p>
    </section>

    <section>
        <h2>4. Doba uchovávání</h2>
        <p>Údaje uchováváme pouze po dobu nezbytně nutnou k plnění povinností a účelů, pro které byly shromážděny.</p>
    </section>

    <section>
        <h2>5. Předávání údajů třetím stranám</h2>
        <p>Osobní údaje mohou být předány poskytovatelům přepravních a platebních služeb v rozsahu nutném pro doručení zboží a zpracování plateb.</p>
    </section>

    <section>
        <h2>6. Práva subjektu údajů</h2>
        <p>Máte právo na přístup k údajům, jejich opravu, výmaz, omezení zpracování a vznést námitku proti zpracování. Máte také právo podat stížnost u dozorového úřadu.</p>
    </section>

    <section>
        <h2>7. Závěrečná ustanovení</h2>
        <p>Tato pravidla ochrany osobních údajů nabývají účinnosti dnem jejich zveřejnění na stránkách e-shopu.</p>
    </section>
</article>

<?php
include $partialsDir . '/footer.php';
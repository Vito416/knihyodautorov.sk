<?php
// templates/pages/terms.php
// Expects:
//  - $pageTitle (string|null)
//  - $navActive (string|null)
//
// Uses partials: header.php, footer.php

$pageTitle = isset($pageTitle) ? (string)$pageTitle : 'Obchodní podmínky';
$navActive = $navActive ?? null;

$partialsDir = __DIR__ . '/../partials';
include $partialsDir . '/header.php';
?>
<article class="page-terms">
    <h1><?= htmlspecialchars($pageTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>

    <section>
        <h2>1. Úvodní ustanovení</h2>
        <p>Tyto obchodní podmínky upravují práva a povinnosti prodávajícího a kupujícího vznikající v souvislosti s kupní smlouvou uzavřenou prostřednictvím internetového obchodu.</p>
    </section>

    <section>
        <h2>2. Objednávka a uzavření smlouvy</h2>
        <p>Objednávka učiněná prostřednictvím e-shopu je závazná. Kupní smlouva vzniká potvrzením objednávky ze strany prodávajícího.</p>
    </section>

    <section>
        <h2>3. Dodací podmínky</h2>
        <p>Zboží je odesláno kupujícímu na adresu uvedenou v objednávce. Termín dodání se obvykle pohybuje mezi 2–5 pracovními dny.</p>
    </section>

    <section>
        <h2>4. Cena a platební podmínky</h2>
        <p>Ceny uvedené v e-shopu jsou konečné, včetně DPH. Platbu lze provést převodem na účet, dobírkou nebo platební kartou online.</p>
    </section>

    <section>
        <h2>5. Odstoupení od smlouvy</h2>
        <p>Spotřebitel má právo odstoupit od smlouvy bez udání důvodu do 14 dnů od převzetí zboží.</p>
    </section>

    <section>
        <h2>6. Reklamace a záruka</h2>
        <p>Na zboží se vztahuje záruka 24 měsíců. Reklamace je nutné uplatnit bez zbytečného odkladu.</p>
    </section>

    <section>
        <h2>7. Závěrečná ustanovení</h2>
        <p>Tyto podmínky jsou platné a účinné od data zveřejnění na stránkách e-shopu.</p>
    </section>
</article>

<?php
include $partialsDir . '/footer.php';
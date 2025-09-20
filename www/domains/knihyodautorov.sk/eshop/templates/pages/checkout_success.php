<?php
// templates/pages/checkout_success.php
// Expects:
//  - $pageTitle (string|null)
//  - $order (array|null) : ['id'=>int,'total'=>float,'created_at'=>string]
//  - $navActive (string|null)
//
// Uses partials: header.php, flash.php, footer.php

$pageTitle = isset($pageTitle) ? (string)$pageTitle : 'Objednávka dokončena';
$navActive = $navActive ?? 'cart';
$order = isset($order) && is_array($order) ? $order : null;

$partialsDir = __DIR__ . '/../partials';
include $partialsDir . '/header.php';
include $partialsDir . '/flash.php';
?>
<article class="checkout-success-page">
    <h1><?= htmlspecialchars($pageTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>

    <?php if ($order === null): ?>
        <p>Vaše objednávka byla přijata.</p>
    <?php else: ?>
        <p>Děkujeme za váš nákup! Vaše objednávka č. <strong>#<?= (int)$order['id'] ?></strong> byla úspěšně vytvořena dne
           <?= htmlspecialchars($order['created_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>.</p>

        <p>Celková částka: <strong><?= number_format((float)$order['total'], 2, ',', ' ') ?> Kč</strong></p>
    <?php endif; ?>

    <p>Potvrzení objednávky a další informace byly zaslány na váš e-mail.</p>

    <p><a class="btn btn-primary" href="/eshop/catalog.php">Pokračovat v nákupu</a></p>
</article>

<?php
include $partialsDir . '/footer.php';
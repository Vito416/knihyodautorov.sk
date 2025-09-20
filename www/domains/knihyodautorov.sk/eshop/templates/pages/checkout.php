<?php
// templates/pages/checkout.php
// Expects:
//  - $pageTitle (string|null)
//  - $navActive (string|null)
//  - $cart (array) : položky ['product_name'=>string,'quantity'=>int,'price'=>float]
//  - $csrf_token (string|null)
//  - $error (string|null)
//
// Uses partials: header.php, flash.php, footer.php

$pageTitle = isset($pageTitle) ? (string)$pageTitle : 'Pokladna';
$navActive = $navActive ?? 'cart';
$cart = isset($cart) && is_array($cart) ? $cart : [];
$csrf = isset($csrf_token) && is_string($csrf_token) ? $csrf_token : null;
$error = isset($error) ? (string)$error : null;

$partialsDir = __DIR__ . '/../partials';
include $partialsDir . '/header.php';
include $partialsDir . '/flash.php';

$total = 0.0;
foreach ($cart as $item) {
    $total += (float)$item['price'] * (int)$item['quantity'];
}
?>
<article class="checkout-page">
    <h1><?= htmlspecialchars($pageTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>

    <?php if (empty($cart)): ?>
        <p>Váš košík je prázdný.</p>
        <p><a class="btn" href="/eshop/catalog.php">Pokračovat v nákupu</a></p>
    <?php else: ?>
        <?php if ($error !== null && $error !== ''): ?>
            <div class="form-error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
        <?php endif; ?>

        <h2>Souhrn objednávky</h2>
        <ul class="checkout-items">
            <?php foreach ($cart as $item): ?>
                <li>
                    <?= htmlspecialchars($item['product_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    × <?= (int)$item['quantity'] ?>
                    — <?= number_format($item['price'] * $item['quantity'], 2, ',', ' ') ?> Kč
                </li>
            <?php endforeach; ?>
        </ul>
        <p><strong>Celkem: <?= number_format($total, 2, ',', ' ') ?> Kč</strong></p>

        <h2>Fakturační údaje</h2>
        <form method="post" action="/eshop/checkout.php" class="form-checkout" novalidate>
            <div class="form-row">
                <label for="full_name">Jméno a příjmení</label>
                <input type="text" id="full_name" name="full_name" required value="<?= htmlspecialchars($_POST['full_name'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            </div>

            <div class="form-row">
                <label for="address">Adresa</label>
                <input type="text" id="address" name="address" required value="<?= htmlspecialchars($_POST['address'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            </div>

            <div class="form-row">
                <label for="city">Město</label>
                <input type="text" id="city" name="city" required value="<?= htmlspecialchars($_POST['city'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            </div>

            <div class="form-row">
                <label for="zip">PSČ</label>
                <input type="text" id="zip" name="zip" required value="<?= htmlspecialchars($_POST['zip'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            </div>

            <div class="form-row">
                <label for="email">E-mail</label>
                <input type="email" id="email" name="email" required value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            </div>

            <div class="form-row">
                <label for="phone">Telefon</label>
                <input type="tel" id="phone" name="phone" value="<?= htmlspecialchars($_POST['phone'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            </div>

            <?php if ($csrf !== null): ?>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <?php endif; ?>

            <div class="form-row">
                <button type="submit" class="btn btn-primary">Dokončit objednávku</button>
            </div>
        </form>
    <?php endif; ?>
</article>

<?php
include $partialsDir . '/footer.php';
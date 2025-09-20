<?php
// templates/pages/cart.php
// Expects:
//  - $pageTitle (string|null)
//  - $navActive (string|null)
//  - $cart (array) : seznam položek ['product_id'=>int,'product_name'=>string,'quantity'=>int,'price'=>float]
//  - $csrf_token (string|null)
//
// Uses partials: header.php, flash.php, footer.php

$pageTitle = isset($pageTitle) ? (string)$pageTitle : 'Nákupní košík';
$navActive = $navActive ?? 'cart';
$cart = isset($cart) && is_array($cart) ? $cart : [];
$csrf = isset($csrf_token) && is_string($csrf_token) ? $csrf_token : null;

$partialsDir = __DIR__ . '/../partials';
include $partialsDir . '/header.php';
include $partialsDir . '/flash.php';

$total = 0.0;
foreach ($cart as $item) {
    $total += (float)$item['price'] * (int)$item['quantity'];
}
?>
<article class="cart-page">
    <h1><?= htmlspecialchars($pageTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>

    <?php if (empty($cart)): ?>
        <p>Váš košík je prázdný.</p>
        <p><a class="btn" href="/eshop/catalog.php">Pokračovat v nákupu</a></p>
    <?php else: ?>
        <form method="post" action="/eshop/cart_update.php" class="form-cart">
            <table class="cart-table">
                <thead>
                    <tr>
                        <th>Produkt</th>
                        <th>Množství</th>
                        <th>Cena za kus</th>
                        <th>Celkem</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($cart as $item): ?>
                        <tr>
                            <td><?= htmlspecialchars($item['product_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                            <td>
                                <input type="number" name="qty[<?= (int)$item['product_id'] ?>]" value="<?= (int)$item['quantity'] ?>" min="1">
                            </td>
                            <td><?= number_format((float)$item['price'], 2, ',', ' ') ?> Kč</td>
                            <td><?= number_format((float)$item['price'] * (int)$item['quantity'], 2, ',', ' ') ?> Kč</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($csrf !== null): ?>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <?php endif; ?>

            <div class="cart-summary">
                <p><strong>Celkem: <?= number_format($total, 2, ',', ' ') ?> Kč</strong></p>
                <div class="cart-actions">
                    <button type="submit" class="btn">Aktualizovat košík</button>
                    <a href="/eshop/checkout.php" class="btn btn-primary">Pokračovat k objednávce</a>
                </div>
            </div>
        </form>
    <?php endif; ?>
</article>

<?php
include $partialsDir . '/footer.php';
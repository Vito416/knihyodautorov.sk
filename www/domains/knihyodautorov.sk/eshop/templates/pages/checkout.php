<?php
// templates/pages/checkout.php
// Expects:
//  - $pageTitle (string|null)
//  - $navActive (string|null)
//  - $cart (array) : položky ['title'=>string,'qty'=>int,'price_snapshot'=>float]
//  - $csrf_token (string|null)
//  - $error (string|null)
//  - $prefill (array)

$pageTitle = isset($pageTitle) ? (string)$pageTitle : 'Pokladňa';
$navActive = $navActive ?? 'cart';
$cart = isset($cart) && is_array($cart) ? $cart : [];
$csrf_token = $csrf_token ?? ($trustedShared['csrfToken'] ?? null);
$error = isset($error) ? (string)$error : null;
$prefill = $prefill ?? [];

// server-side totals for accessibility / noscript
$total = 0.0;
$itemsCount = 0;
foreach ($cart as $item) {
    $qty = (int)($item['qty'] ?? 0);
    $price = (float)($item['price_snapshot'] ?? 0.0);
    $total += $price * $qty;
    $itemsCount += $qty;
}
?>
<article class="checkout-page card">
    <header class="checkout-header">
        <h1 class="page-title"><?= htmlspecialchars($pageTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
        <div class="checkout-meta">
            <span class="meta-pill">Položky: <strong id="cart-items-count"><?= (int)$itemsCount ?></strong></span>
            <span class="meta-pill">Spolu: <strong id="cart-total"><?= number_format($total, 2, ',', ' ') ?> €</strong></span>
        </div>
    </header>

    <div class="checkout-grid">
        <!-- LEFT: Billing form -->
        <section class="form-card">
            <?php if ($error): ?>
                <div class="form-error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
            <?php endif; ?>

            <h2 class="section-title">Fakturačné údaje</h2>

            <form id="checkout-form" method="post" action="?route=checkout" class="form-checkout" novalidate>
                <div class="row two">
                    <div class="form-row">
                        <label for="bill_full_name">Meno a priezvisko</label>
                        <input type="text" id="bill_full_name" name="bill_full_name" required
                               value="<?= htmlspecialchars($_POST['bill_full_name'] ?? $prefill['full_name'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    </div>

                    <div class="form-row">
                        <label for="email">E-mail</label>
                        <input type="email" id="email" name="email" required
                               value="<?= htmlspecialchars($_POST['email'] ?? $prefill['email'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    </div>
                </div>

                <div class="form-row">
                    <label for="bill_street">Ulica a číslo</label>
                    <input type="text" id="bill_street" name="bill_street" required
                           value="<?= htmlspecialchars($_POST['bill_street'] ?? $prefill['street'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                </div>

                <div class="row three">
                    <div class="form-row">
                        <label for="bill_city">Mesto</label>
                        <input type="text" id="bill_city" name="bill_city" required
                               value="<?= htmlspecialchars($_POST['bill_city'] ?? $prefill['city'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    </div>

                    <div class="form-row">
                        <label for="bill_zip">PSČ</label>
                        <input type="text" id="bill_zip" name="bill_zip" required
                               value="<?= htmlspecialchars($_POST['bill_zip'] ?? $prefill['zip'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    </div>

                    <div class="form-row">
                        <label for="bill_country">Krajina</label>
                        <input type="text" id="bill_country" name="bill_country" required
                               value="<?= htmlspecialchars($_POST['bill_country'] ?? $prefill['country'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    </div>
                </div>
                <div class="form-actions">
                    <?= \CSRF::hiddenInput('csrf') ?>
                    <!-- NOTE: button type="button" -> submit cez JS (posiela JSON) -->
                    <button type="button" id="checkout-submit" class="btn btn-primary" data-action="submit-order">
                        Dokončiť objednávku
                    </button>
                    <a href="/eshop" class="btn btn-ghost">Pokračovať v nákupe</a>
                </div>
            </form>
            <script type="text/javascript">
            // server-side cart snapshot (bez session reliance)
            window.__checkoutCart = <?= json_encode(array_values($cart), JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
            // optional CSRF token (ak máš)
            window.__csrfToken = <?= $csrf_token !== null ? json_encode($csrf_token, JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) : 'null' ?>;
            // endpoint
            window.__orderSubmitUrl = '/eshop/order_submit';
            </script>
        </section>

        <!-- RIGHT: Order summary -->
        <aside class="summary-card aside">
            <h2 class="section-title">Súhrn objednávky</h2>

            <?php if (empty($cart)): ?>
                <div class="empty">
                    <p>Váš košík je prázdny.</p>
                    <p><a class="btn btn-ghost" href="/eshop">Pridať položky</a></p>
                </div>
            <?php else: ?>
                <ul class="checkout-items" id="checkout-items">
                    <?php foreach ($cart as $idx => $item):
                        $title = $item['title'] ?? '';
                        $qty = (int)($item['qty'] ?? 0);
                        $price = (float)($item['price_snapshot'] ?? 0.0);
                        $line = $price * $qty;
                    ?>
                    <li class="item-row" data-idx="<?= $idx ?>">
                        <div>
                            <div class="item-title"><?= htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                            <div class="item-meta"><?= $qty ?> × <?= number_format($price, 2, ',', ' ') ?> €</div>
                        </div>
                        <div class="price"><?= number_format($line, 2, ',', ' ') ?> €</div>
                    </li>
                    <?php endforeach; ?>
                </ul>

                <div class="total-row">
                    <div class="muted">Medzisúčet</div>
                    <div class="price"><?= number_format($total, 2, ',', ' ') ?> €</div>
                </div>

                <div class="total-row" style="margin-top:8px;font-size:1.05rem">
                    <strong>Celkom</strong>
                    <strong id="total-strong"><?= number_format($total, 2, ',', ' ') ?> €</strong>
                </div>

                <div class="smallprint muted" style="margin-top:12px;font-size:0.86rem">
                    Bezpečná platba — prijímame GoPay, PayBySquare a ďalšie. Po odoslaní budete presmerovaný na stránku platobnej brány.
                </div>
            <?php endif; ?>
        </aside>
    </div>
</article>
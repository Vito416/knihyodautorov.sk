<?php
// templates/pages/order_detail.php
// Expects:
//  - $pageTitle (string|null)
//  - $user (array|null)
//  - $navActive (string|null)
//  - $order (array|null) : ['id'=>int,'created_at'=>string,'status'=>string,'total'=>float,'items'=>array]
//        každý prvek items: ['product_name'=>string,'quantity'=>int,'price'=>float]
//  - $csrf_token (string|null)
//
// Uses partials: header.php, flash.php, footer.php

$pageTitle = isset($pageTitle) ? (string)$pageTitle : 'Detail objednávky';
$navActive = $navActive ?? 'orders';
$order = isset($order) && is_array($order) ? $order : null;
$csrf = isset($csrf_token) && is_string($csrf_token) ? $csrf_token : null;

$partialsDir = __DIR__ . '/../partials';
include $partialsDir . '/header.php';
include $partialsDir . '/flash.php';
?>
<article class="order-detail-page">
    <h1><?= htmlspecialchars($pageTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>

    <?php if ($order === null): ?>
        <p>Objednávka nebyla nalezena.</p>
    <?php else: ?>
        <section class="order-summary">
            <h2>Souhrn</h2>
            <dl>
                <dt>Číslo objednávky:</dt>
                <dd>#<?= (int)$order['id'] ?></dd>
                <dt>Datum vytvoření:</dt>
                <dd><?= htmlspecialchars($order['created_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
                <dt>Stav:</dt>
                <dd><?= htmlspecialchars($order['status'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
                <dt>Celková cena:</dt>
                <dd><strong><?= number_format((float)$order['total'], 2, ',', ' ') ?> Kč</strong></dd>
            </dl>
        </section>

        <section class="order-items">
            <h2>Položky objednávky</h2>
            <?php if (empty($order['items'])): ?>
                <p>Žádné položky.</p>
            <?php else: ?>
                <table class="order-items-table">
                    <thead>
                        <tr>
                            <th>Produkt</th>
                            <th>Množství</th>
                            <th>Cena za kus</th>
                            <th>Celkem</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($order['items'] as $item): ?>
                            <tr>
                                <td><?= htmlspecialchars($item['product_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                <td><?= (int)$item['quantity'] ?></td>
                                <td><?= number_format((float)$item['price'], 2, ',', ' ') ?> Kč</td>
                                <td><?= number_format($item['quantity'] * $item['price'], 2, ',', ' ') ?> Kč</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</article>

<?php
include $partialsDir . '/footer.php';
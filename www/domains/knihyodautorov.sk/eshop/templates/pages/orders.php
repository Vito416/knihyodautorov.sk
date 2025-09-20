<?php
// templates/pages/orders.php
// Expects:
//  - $pageTitle (string|null)
//  - $user (array|null)
//  - $navActive (string|null)
//  - $orders (array) : seznam objednávek, každá ve tvaru
//        ['id'=>int, 'created_at'=>string, 'total'=>float, 'status'=>string]
//  - $csrf_token (string|null)
//
// Uses partials: header.php, flash.php, footer.php

$pageTitle = isset($pageTitle) ? (string)$pageTitle : 'Moje objednávky';
$navActive = $navActive ?? 'orders';
$orders = isset($orders) && is_array($orders) ? $orders : [];
$csrf = isset($csrf_token) && is_string($csrf_token) ? $csrf_token : null;

$partialsDir = __DIR__ . '/../partials';
include $partialsDir . '/header.php';
include $partialsDir . '/flash.php';
?>
<article class="orders-page">
    <h1><?= htmlspecialchars($pageTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>

    <?php if (empty($orders)): ?>
        <p>Zatím nemáte žádné objednávky.</p>
    <?php else: ?>
        <table class="orders-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Datum</th>
                    <th>Celková cena</th>
                    <th>Stav</th>
                    <th>Akce</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orders as $order): ?>
                    <tr>
                        <td>#<?= (int)$order['id'] ?></td>
                        <td><?= htmlspecialchars($order['created_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                        <td><?= number_format((float)$order['total'], 2, ',', ' ') ?> Kč</td>
                        <td><?= htmlspecialchars($order['status'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                        <td><a href="/eshop/order_detail.php?id=<?= urlencode((string)$order['id']) ?>">Detail</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</article>

<?php
include $partialsDir . '/footer.php';
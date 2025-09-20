<?php
// templates/pages/account.php
// Expects:
//  - $pageTitle (string|null)
//  - $user (array|null) : přihlášený uživatel (musí mít alespoň ['id','email','full_name'] nebo podobně)
//  - $navActive (string|null)
//  - $csrf_token (string|null)
//  - $flash (array|null)
//
// Uses partials: header.php, flash.php, footer.php

$pageTitle = isset($pageTitle) ? (string)$pageTitle : 'Můj účet';
$navActive = $navActive ?? 'account';
$csrf = isset($csrf_token) && is_string($csrf_token) ? $csrf_token : null;

$partialsDir = __DIR__ . '/../partials';
include $partialsDir . '/header.php';
include $partialsDir . '/flash.php';

$fullName = isset($user['full_name']) ? htmlspecialchars((string)$user['full_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '';
$email    = isset($user['email']) ? htmlspecialchars((string)$user['email'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '';
?>
<article class="account-page">
    <h1><?= htmlspecialchars($pageTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>

    <section class="account-details">
        <h2>Osobní údaje</h2>
        <dl>
            <dt>Jméno:</dt>
            <dd><?= $fullName ?></dd>
            <dt>E-mail:</dt>
            <dd><?= $email ?></dd>
        </dl>
    </section>

    <section class="account-actions">
        <h2>Akce</h2>
        <ul>
            <li><a href="/eshop/orders.php">Moje objednávky</a></li>
            <li><a href="/eshop/password_change.php">Změna hesla</a></li>
            <li>
                <form method="post" action="/eshop/logout.php" class="inline-form">
                    <?php if ($csrf !== null): ?>
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    <?php endif; ?>
                    <button type="submit" class="btn btn-link">Odhlásit se</button>
                </form>
            </li>
        </ul>
    </section>
</article>

<?php
include $partialsDir . '/footer.php';
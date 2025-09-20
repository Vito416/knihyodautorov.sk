<?php
// templates/pages/password_reset.php
// Expects (optional):
//  - $pageTitle, $navActive, $csrf_token
//  - $message (string|null) : informational message to display after POST
//
// Uses partials: header.php, flash.php, footer.php

$pageTitle = isset($pageTitle) ? (string)$pageTitle : 'Obnovení hesla';
$navActive = $navActive ?? 'account';
$csrf = isset($csrf_token) && is_string($csrf_token) ? $csrf_token : null;
$message = isset($message) ? (string)$message : null;

$partialsDir = __DIR__ . '/../partials';
include $partialsDir . '/header.php';
include $partialsDir . '/flash.php';
?>
<article class="auth-page password-reset-page">
    <h1><?= htmlspecialchars($pageTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>

    <?php if ($message !== null): ?>
        <div class="info" role="status">
            <?= htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
        </div>
    <?php else: ?>
        <p>Zadejte prosím e-mailovou adresu přiřazenou k vašemu účtu. Pokud účet existuje, pošleme vám odkaz pro obnovení hesla.</p>

        <form method="post" action="/eshop/password_reset.php" class="form form-password-reset" novalidate>
            <div class="form-row">
                <label for="email">E-mail</label>
                <input id="email" name="email" type="email" required autocomplete="email" value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            </div>

            <?php if ($csrf !== null): ?>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <?php endif; ?>

            <div class="form-row">
                <button type="submit" class="btn btn-primary">Požádat o obnovení hesla</button>
            </div>

            <div class="form-row form-links">
                <a href="/eshop/login.php">Zpět na přihlášení</a>
            </div>
        </form>
    <?php endif; ?>
</article>

<?php
include $partialsDir . '/footer.php';
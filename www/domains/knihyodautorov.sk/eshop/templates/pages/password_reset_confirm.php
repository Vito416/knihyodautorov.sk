<?php
// templates/pages/password_reset_confirm.php
// Expects (optional):
//  - $pageTitle (string|null)
//  - $navActive (string|null)
//  - $csrf_token (string|null)
//  - $error (string|null)  // chybová hláška, pokud token neplatí nebo hesla nesedí
//
// Uses partials: header.php, flash.php, footer.php

$pageTitle = isset($pageTitle) ? (string)$pageTitle : 'Nastavení nového hesla';
$navActive = $navActive ?? 'account';
$csrf = isset($csrf_token) && is_string($csrf_token) ? $csrf_token : null;
$error = isset($error) ? (string)$error : null;

$partialsDir = __DIR__ . '/../partials';
include $partialsDir . '/header.php';
include $partialsDir . '/flash.php';
?>
<article class="auth-page password-reset-confirm">
    <h1><?= htmlspecialchars($pageTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>

    <?php if ($error !== null && $error !== ''): ?>
        <div class="form-error" role="alert">
            <?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <form method="post" action="/eshop/password_reset_confirm.php" class="form form-password-reset-confirm" novalidate>
        <div class="form-row">
            <label for="password">Nové heslo</label>
            <input id="password" name="password" type="password" required autocomplete="new-password">
        </div>

        <div class="form-row">
            <label for="password2">Nové heslo znovu</label>
            <input id="password2" name="password2" type="password" required autocomplete="new-password">
        </div>

        <?php if ($csrf !== null): ?>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <?php endif; ?>

        <div class="form-row">
            <button type="submit" class="btn btn-primary">Nastavit heslo</button>
        </div>
    </form>
</article>

<?php
include $partialsDir . '/footer.php';
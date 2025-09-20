<?php
// templates/pages/login.php
// Expects (optional):
//  - $pageTitle (string|null)
//  - $user (array|null)
//  - $navActive (string|null)
//  - $error (string|null)         // chybová zpráva nebo null
//  - $csrf_token (string|null)
// Uses partials: header.php, flash.php, footer.php

$pageTitle = isset($pageTitle) ? (string)$pageTitle : 'Přihlášení';
$navActive = $navActive ?? 'account';
$csrf = isset($csrf_token) && is_string($csrf_token) ? $csrf_token : null;

$partialsDir = __DIR__ . '/../partials';
include $partialsDir . '/header.php';
include $partialsDir . '/flash.php';
?>
<article class="auth-page login-page">
    <h1><?= htmlspecialchars($pageTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>

    <?php if (!empty($error)): ?>
        <div class="form-error" role="alert">
            <?= htmlspecialchars((string)$error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <form method="post" action="/eshop/login.php" class="form form-login" autocomplete="off" novalidate>
        <div class="form-row">
            <label for="email">E-mail</label>
            <input id="email" name="email" type="email" required autocomplete="email" value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        </div>

        <div class="form-row">
            <label for="password">Heslo</label>
            <input id="password" name="password" type="password" required autocomplete="current-password" value="">
        </div>

        <?php if ($csrf !== null): ?>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <?php endif; ?>

        <div class="form-row">
            <button type="submit" class="btn btn-primary">Přihlásit</button>
        </div>

        <div class="form-row form-links">
            <a href="/eshop/password_reset.php">Zapomněli jste heslo?</a>
            <span> | </span>
            <a href="/eshop/register.php">Registrovat se</a>
        </div>
    </form>
</article>

<?php
include $partialsDir . '/footer.php';
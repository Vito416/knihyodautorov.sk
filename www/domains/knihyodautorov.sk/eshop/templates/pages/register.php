<?php
// templates/pages/register.php
// Expects (optional):
//  - $pageTitle (string|null)
//  - $user (array|null)
//  - $navActive (string|null)
//  - $error (string|null)        // chybová zpráva nebo null
//  - $csrf_token (string|null)
// Uses partials: header.php, flash.php, footer.php

$pageTitle = isset($pageTitle) ? (string)$pageTitle : 'Registrace';
$navActive = $navActive ?? 'account';
$csrf = isset($csrf_token) && is_string($csrf_token) ? $csrf_token : null;

$partialsDir = __DIR__ . '/../partials';
include $partialsDir . '/header.php';
include $partialsDir . '/flash.php';

// safe prefill from previous POST (controllers may pass explicit values instead)
$pref_name  = htmlspecialchars($_POST['full_name'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$pref_email = htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<article class="auth-page register-page">
    <h1><?= htmlspecialchars($pageTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>

    <?php if (!empty($error)): ?>
        <div class="form-error" role="alert"><?= htmlspecialchars((string)$error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
    <?php endif; ?>

    <form method="post" action="/eshop/register.php" class="form form-register" autocomplete="off" novalidate>
        <div class="form-row">
            <label for="full_name">Jméno a příjmení</label>
            <input id="full_name" name="full_name" type="text" required maxlength="255" value="<?= $pref_name ?>">
        </div>

        <div class="form-row">
            <label for="email">E-mail</label>
            <input id="email" name="email" type="email" required autocomplete="email" value="<?= $pref_email ?>">
        </div>

        <div class="form-row">
            <label for="password">Heslo</label>
            <input id="password" name="password" type="password" required autocomplete="new-password">
            <small>Heslo alespoň 8 znaků.</small>
        </div>

        <div class="form-row">
            <label for="password2">Heslo znovu</label>
            <input id="password2" name="password2" type="password" required autocomplete="new-password">
        </div>

        <?php if ($csrf !== null): ?>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <?php endif; ?>

        <div class="form-row">
            <button type="submit" class="btn btn-primary">Registrovat</button>
        </div>

        <div class="form-row form-links">
            <a href="/eshop/login.php">Mám už účet — přihlásit</a>
        </div>
    </form>
</article>

<?php
include $partialsDir . '/footer.php';
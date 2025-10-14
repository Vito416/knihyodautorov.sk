<?php
// templates/pages/login.php
// Expects (optional):
//  - $pageTitle (string|null)
//  - $user (array|null)
//  - $navActive (string|null)
//  - $error (string|null)         // chybová zpráva nebo null
//  - $csrf_token (string|null)

$pageTitle = isset($pageTitle) ? (string)$pageTitle : 'Přihlášení';
$navActive = $navActive ?? 'account';
?>
<article class="auth-page login-page">
    <h1><?= htmlspecialchars($pageTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>

    <?php if (!empty($error)): ?>
        <div class="form-error" role="alert">
            <?= htmlspecialchars((string)$error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <form method="post" action="/eshop/login" class="form form-login" autocomplete="off" novalidate>
        <div class="form-row">
            <label for="email">E-mail</label>
            <input id="email" name="email" type="email" required autocomplete="email" value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        </div>

        <div class="form-row">
            <label for="password">Heslo</label>
            <input id="password" name="password" type="password" required autocomplete="current-password" value="">
        </div>

        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">

        <div class="form-row">
            <button type="submit" class="btn btn-primary">Přihlásit</button>
        </div>

        <div class="form-row form-links">
            <a href="/eshop/password_reset">Zapomněli jste heslo?</a>
            <span> | </span>
            <a href="/eshop/register">Registrovat se</a>
        </div>
        <?php if (!empty($_SESSION['register_success'])): ?>
            <div class="success">Registrácia úspešná. Skontrolujte svoj e-mail pre overenie účtu.</div>
            <?php unset($_SESSION['register_success']); ?>
        <?php endif; ?>
    </form>
</article>
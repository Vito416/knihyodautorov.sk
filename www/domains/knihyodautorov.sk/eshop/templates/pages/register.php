<?php
// templates/pages/register.php
// Expects (optional):
//  - $pageTitle (string|null)
//  - $user (array|null)
//  - $navActive (string|null)
//  - $error (string|null)        // chybová zpráva nebo null

$pageTitle = isset($pageTitle) ? (string)$pageTitle : 'Registrácia';
$navActive = $navActive ?? 'account';

$partialsDir = __DIR__ . '/../partials';
include $partialsDir . '/header.php';
include $partialsDir . '/flash.php';

// safe prefill from previous POST (controllers may pass explicit values instead)
$pref_given  = htmlspecialchars($_POST['given_name'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$pref_family = htmlspecialchars($_POST['family_name'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$pref_email  = htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<article class="auth-page register-page">
    <h1><?= htmlspecialchars($pageTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>

    <?php if (!empty($error)): ?>
        <div class="form-error" role="alert"><?= htmlspecialchars((string)$error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
    <?php endif; ?>

    <form method="post" action="/eshop/register.php" class="form form-register" autocomplete="off" novalidate>
        <div class="form-row">
            <label for="given_name">Meno</label>
            <input id="given_name" name="given_name" type="text" required maxlength="100" value="<?= $pref_given ?>">
        </div>

        <div class="form-row">
            <label for="family_name">Priezvisko</label>
            <input id="family_name" name="family_name" type="text" required maxlength="150" value="<?= $pref_family ?>">
        </div>

        <div class="form-row">
            <label for="email">E-mail</label>
            <input id="email" name="email" type="email" required autocomplete="email" value="<?= $pref_email ?>">
        </div>

        <div class="form-row">
            <label for="password">Heslo</label>
            <input id="password" name="password" type="password" required autocomplete="new-password">
            <small>Heslo aspoň 12 znakov, veľké/malé písmená, číslo, špeciálny znak.</small>
        </div>

        <div class="form-row">
            <label for="password2">Heslo znova</label>
            <input id="password2" name="password2" type="password" required autocomplete="new-password">
        </div>

        <div class="form-row">
            <?= CSRF::hiddenInput('csrf') ?>
            <input type="hidden" name="newsletter_subscribe" id="newsletter_subscribe" value="0">
        </div>

        <div class="form-row">
            <button type="submit" class="btn btn-primary">Registrovať</button>
        </div>

        <div class="form-row form-links">
            <a href="/eshop/login.php">Už mám účet — prihlásiť sa</a>
        </div>
    </form>
<?php if (empty($existingNewsletter) || !$existingNewsletter): ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.querySelector('.form-register');
        if (!form) return;

        form.addEventListener('submit', function(e) {
            // jen pokud checkbox/skrytý popup ještě nebyl potvrzen
            let subscribe = confirm("Chcete dostávať náš newsletter?");
            document.getElementById('newsletter_subscribe').value = subscribe ? "1" : "0";
            // pokračujeme v odeslání
        });
    });
    </script>
<?php endif; ?>
</article>

<?php
include $partialsDir . '/footer.php';
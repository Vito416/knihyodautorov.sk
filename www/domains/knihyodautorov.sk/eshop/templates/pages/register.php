<?php
// templates/pages/register.php
// defensive register template

$pageTitle = isset($pageTitle) ? (string)$pageTitle : 'Registrácia';
$navActive  = $navActive ?? 'account';

// ensure these are defined and safe
$existingNewsletter = $existingNewsletter ?? false;
$form_pref = $form_pref ?? (is_array($form_pref ?? null) ? $form_pref : []);
// safe prefill order: handler-provided form_pref -> POST -> empty
$pref_given  = htmlspecialchars($form_pref['given_name'] ?? ($_POST['given_name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$pref_family = htmlspecialchars($form_pref['family_name'] ?? ($_POST['family_name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$pref_email  = htmlspecialchars($form_pref['email'] ?? ($_POST['email'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<article class="auth-page register-page">
    <h1><?= htmlspecialchars($pageTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>

    <?php if (!empty($error)): ?>
        <div class="form-error" role="alert"><?= htmlspecialchars((string)$error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
    <?php endif; ?>

    <form method="post" action="/actions/register" onsubmit="event.preventDefault(); submitRegistration(this);" class="form form-register" autocomplete="off" novalidate>
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
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <input type="hidden" name="newsletter_subscribe" id="newsletter_subscribe" value="0">
        </div>

        <div class="form-row">
            <button type="submit" class="btn btn-primary">Registrovať</button>
        </div>

        <div class="form-row form-links">
            <a href="/eshop/login">Už mám účet — prihlásiť sa</a>
        </div>
        <div id="register-message" class="form-message"></div>
    </form>

<?php if (empty($existingNewsletter)): ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.querySelector('.form-register');
        if (!form) return;

        form.addEventListener('submit', function(e) {
            // jednoduché potvrdenie — môžeš nahradiť modalom / reCAPTCHA
            try {
                var subscribe = confirm("Chcete dostávať náš newsletter?");
                document.getElementById('newsletter_subscribe').value = subscribe ? "1" : "0";
            } catch (err) {
                // ak niečo zlyhá, necháme predvolenú hodnotu "0"
                document.getElementById('newsletter_subscribe').value = "0";
            }
            // pokračujeme v odoslaní
        });
    });
    </script>
<?php endif; ?>
</article>
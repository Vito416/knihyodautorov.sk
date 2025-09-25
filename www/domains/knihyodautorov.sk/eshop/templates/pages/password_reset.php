<?php
// templates/pages/password_reset.php
// Expects (optional):
//  - $pageTitle, $navActive, $csrf_token
//  - $message (string|null) : informačná správa po odoslaní formulára
//

$pageTitle = isset($pageTitle) ? (string)$pageTitle : 'Obnovenie hesla';
$navActive = $navActive ?? 'account';
$message = isset($message) ? (string)$message : null;

?>
<article class="auth-page password-reset-page">
    <h1><?= htmlspecialchars($pageTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>

    <?php if ($message !== null): ?>
        <div class="info" role="status" aria-live="polite">
            <?= htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
        </div>
    <?php else: ?>
        <p>Zadajte e-mailovú adresu priradenú k vášmu účtu. Ak účet existuje, pošleme vám odkaz na obnovenie hesla.</p>

        <form method="post" action="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '/eshop/password_reset', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
              class="form form-password-reset">
            <div class="form-row">
                <label for="email">E-mail</label>
                <input id="email"
                       name="email"
                       type="email"
                       required
                       autocomplete="email"
                       maxlength="512"
                       value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            </div>

            <!-- Honeypot: boti často vyplnia toto pole, používatelia ho nevidia -->
            <div style="position: absolute; left: -10000px; top: auto; width: 1px; height: 1px; overflow: hidden;" aria-hidden="true">
                <label for="website">Web</label>
                <input id="website" name="website" type="text" tabindex="-1" autocomplete="off" value="">
            </div>

            <?= CSRF::hiddenInput('csrf') ?>

            <div class="form-row">
                <button type="submit" class="btn btn-primary">Požiadať o obnovenie hesla</button>
            </div>

            <div class="form-row form-links">
                <a href="/eshop/login" rel="noopener noreferrer">Späť na prihlásenie</a>
            </div>
        </form>
    <?php endif; ?>
</article>
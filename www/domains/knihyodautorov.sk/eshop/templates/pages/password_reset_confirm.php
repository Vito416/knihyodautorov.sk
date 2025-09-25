<?php
// templates/pages/password_reset_confirm.php
// Expects (optional):
//  - $pageTitle (string|null)
//  - $navActive (string|null)
//  - $csrf_token (string|null)
//  - $status (string) : 'form'|'form_error'|'invalid'|'expired'|'already_used'|'success'|'error'
//  - $error (string|null)
//  - $selector (string|null)
//  - $validator (string|null)

$pageTitle = isset($pageTitle) ? (string)$pageTitle : 'Nastavenie nového hesla';
$navActive = $navActive ?? 'account';
$csrf = isset($csrf_token) && is_string($csrf_token) ? $csrf_token : null;
$status = isset($status) ? strtolower((string)$status) : 'invalid';
$error = isset($error) ? (string)$error : null;
$selector = isset($selector) ? (string)$selector : null;
$validator = isset($validator) ? (string)$validator : null;

?>
<article class="auth-page password-reset-confirm">
    <h1><?= htmlspecialchars($pageTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>

    <?php if ($status === 'form' || $status === 'form_error'): ?>

        <?php if ($status === 'form_error' && $error !== null && $error !== ''): ?>
            <div class="form-error" role="alert">
                <?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <p>Zadajte nové heslo pre váš účet. Heslo musí mať aspoň 10 znakov.</p>

        <form method="post" action="/eshop/password_reset_confirm" class="form form-password-reset-confirm" novalidate>
            <div class="form-row">
                <label for="password">Nové heslo</label>
                <input id="password" name="password" type="password" required autocomplete="new-password" minlength="10">
            </div>

            <div class="form-row">
                <label for="password2">Nové heslo znovu</label>
                <input id="password2" name="password2" type="password" required autocomplete="new-password" minlength="10">
            </div>

            <?php if (!empty($selector)): ?>
                <input type="hidden" name="selector" value="<?= htmlspecialchars($selector, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <?php endif; ?>
            <?php if (!empty($validator)): ?>
                <input type="hidden" name="validator" value="<?= htmlspecialchars($validator, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <?php endif; ?>

            <?php if ($csrf !== null): ?>
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <?php endif; ?>

            <div class="form-row">
                <button type="submit" class="btn btn-primary">Nastaviť heslo</button>
            </div>

            <div class="form-row form-links">
                <a href="/eshop/login">Späť na prihlásenie</a>
            </div>
        </form>

    <?php elseif ($status === 'success'): ?>

        <div class="success" role="status">
            <strong>Heslo bolo úspešne zmenené</strong>
            <p>Môžete sa teraz prihlásiť s novým heslom.</p>
            <p style="margin-top:.6rem;">
                <a class="btn" href="/eshop/login">Prihlásiť sa</a>
                <a class="btn alt" href="/" style="margin-left:.5rem;">Domov</a>
            </p>
        </div>

    <?php elseif ($status === 'already_used'): ?>

        <div class="error" role="alert">
            <strong>Odkaz už bol použitý</strong>
            <p>Tento odkaz na obnovenie hesla už bol použitý. Ak stále potrebujete pomoc, požiadajte o nové obnovenie hesla.</p>
            <p style="margin-top:.6rem;">
                <a class="btn" href="/eshop/password_reset">Požiadať znovu</a>
                <a class="btn alt" href="/eshop/login" style="margin-left:.5rem;">Prihlásiť sa</a>
            </p>
        </div>

    <?php elseif ($status === 'expired'): ?>

        <div class="error" role="alert">
            <strong>Platnosť odkazu vypršala</strong>
            <p>Platnosť odkazu na obnovenie hesla už uplynula. Požiadajte, prosím, o nový odkaz.</p>
            <p style="margin-top:.6rem;">
                <a class="btn" href="/eshop/password_reset">Požiadať nový odkaz</a>
                <a class="btn alt" href="/eshop/login" style="margin-left:.5rem;">Prihlásiť sa</a>
            </p>
        </div>

    <?php elseif ($status === 'invalid'): ?>

        <div class="error" role="alert">
            <strong>Neplatný odkaz</strong>
            <p>Odkaz na obnovenie hesla je neplatný. Skontrolujte, či máte správny odkaz, alebo požiadajte o nový.</p>
            <p style="margin-top:.6rem;">
                <a class="btn" href="/eshop/password_reset">Požiadať o obnovenie hesla</a>
                <a class="btn alt" href="/eshop/login" style="margin-left:.5rem;">Prihlásiť sa</a>
            </p>
        </div>

    <?php else: /* 'error' alebo iný neočakávaný stav */ ?>

        <div class="error" role="alert">
            <strong>Nastala chyba</strong>
            <p><?= htmlspecialchars($error ?? 'Vyskytla sa neočakávaná chyba. Skúste to, prosím, neskôr.', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
            <p style="margin-top:.6rem;">
                <a class="btn" href="/eshop/password_reset">Požiadať o obnovenie hesla</a>
                <a class="btn alt" href="/" style="margin-left:.5rem;">Domov</a>
            </p>
        </div>

    <?php endif; ?>

</article>
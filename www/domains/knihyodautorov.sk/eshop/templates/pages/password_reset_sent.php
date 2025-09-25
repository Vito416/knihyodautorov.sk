<?php
// templates/pages/password_reset_sent.php
// Expects:
//  - $pageTitle (string|null)
//  - $navActive (string|null)
//  - $email (string|null)   // volitelně pro informaci uživatele
//

$pageTitle = isset($pageTitle) ? (string)$pageTitle : 'Odeslání odkazu pro obnovení hesla';
$navActive = $navActive ?? 'account';

$displayEmail = isset($email) ? htmlspecialchars((string)$email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : null;
?>
<article class="password-reset-sent">
    <h1><?= htmlspecialchars($pageTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>

    <p>Pokud je v naší databázi účet s danou e-mailovou adresou, zaslali jsme na něj odkaz pro obnovení hesla.</p>

    <?php if ($displayEmail !== null && $displayEmail !== ''): ?>
        <p>Odesláno na: <strong><?= $displayEmail ?></strong></p>
    <?php endif; ?>

    <p>Z bezpečnostních důvodů nic víc nezobrazujeme — pokud e-mail nedorazí do několika minut, zkontrolujte složku spam nebo zkuste znovu požádat o odkaz.</p>

    <p><a class="btn" href="/eshop/login">Přejít na přihlášení</a></p>
</article>
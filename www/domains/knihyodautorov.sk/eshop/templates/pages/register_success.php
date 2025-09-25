<?php
// templates/pages/register_success.php
// Expects:
//  - $pageTitle (string|null)
//  - $user (array|null)
//  - $navActive (string|null)
//  - $email (string)  -- e-mail, kterému byl odeslán verifikační odkaz (pro informaci uživatele)
//

$pageTitle = isset($pageTitle) ? (string)$pageTitle : 'Registrace dokončena';
$navActive = $navActive ?? 'account';

$displayEmail = isset($email) ? htmlspecialchars((string)$email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '';
?>
<article class="register-success">
    <h1><?= htmlspecialchars($pageTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>

    <p>Děkujeme za registraci. Na e-mail <strong><?= $displayEmail ?></strong> jsme poslali ověřovací odkaz. 
       Klikněte prosím na něj, abyste dokončili aktivaci účtu.</p>

    <p>Pokud e-mail nedorazí do několika minut, zkontrolujte složku spam nebo požádejte o opětovné odeslání z vašeho profilu.</p>

    <p><a class="btn" href="/eshop/login">Přejít na přihlášení</a></p>
</article>
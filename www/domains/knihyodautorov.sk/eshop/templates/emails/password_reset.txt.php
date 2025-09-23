<?php
// password_reset.txt.php (plain text)
// Available (escaped) variables: $reset_url, $site
// Optional: $logo_cid, $logo_url (not used in plaintext)

$siteName = $site ?? ($_ENV['APP_NAME'] ?? 'Náš web');
?>
Obnovenie hesla

Dobrý deň,

Obdržali sme požiadavku na obnovenie hesla pre účet na stránke: <?= $siteName ?>.

Ak ste o obnovenie hesla požiadali vy, otvoríte nasledujúci odkaz (alebo ho vložte do prehliadača):
<?= ($reset_url ?? '#') ?>

Ak o obnovenie nežiadate, môžete tento e-mail ignorovať — váš účet zostane nezmenený.

Poznámka: z bezpečnostných dôvodov je odkaz časovo obmedzený. Ak odkaz nefunguje, skúste požiadať o obnovenie znova cez web.

S pozdravom,
<?= $siteName ?> tím
<?= date('Y') ?>
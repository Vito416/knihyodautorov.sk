<?php
// newsletter_subscribe_confirm.txt.php
// Available variables: $confirm_url, $unsubscribe_url
$confirmHref = $confirm_url ?? '#';
$unsubscribeHref = $unsubscribe_url ?? '#';
?>
Potvrďte prihlásenie na odber noviniek

Dobrý deň,

Prosím potvrďte prihlásenie na zasielanie noviniek kliknutím na nasledujúci odkaz:
<?= $confirmHref ?>


Ak ste o prihlásenie nežiadali alebo sa chcete odhlásiť, použite tento odkaz:
<?= $unsubscribeHref ?>


Ďakujeme,
<?= $_ENV['APP_NAME'] ?? 'Náš tím' ?>
<?php
/** @var string $reset_url */
?>
<!doctype html>
<html lang="sk">
<head><meta charset="utf-8"><title>Obnovenie hesla</title></head>
<body>
  <p>Dobrý deň,</p>
  <p>obdržali sme požiadavku na obnovenie hesla pre tento účet. Ak ste požiadavku nepodali, prosím ignorujte tento e-mail.</p>
  <p>Pre nastavenie nového hesla kliknite na odkaz:</p>
  <p><a href="<?= htmlspecialchars($reset_url) ?>"><?= htmlspecialchars($reset_url) ?></a></p>
  <p>Odkaz je platný krátky čas. Ak ste žiadali obnovenie a nedostanete e-mail, skontrolujte spam.</p>
  <p>S pozdravom,<br>tím e-shopu</p>
</body>
</html>

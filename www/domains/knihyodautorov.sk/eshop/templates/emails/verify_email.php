<?php
/** @var string $verify_url */
/** @var string|null $name */
$name = $name ?? '';
?>
<!doctype html>
<html lang="sk">
<head><meta charset="utf-8"><title>Overenie e-mailu</title></head>
<body>
  <p>Dobrý deň <?= $name !== '' ? htmlspecialchars($name) : '' ?>,</p>
  <p>ďakujeme za registráciu v našom e-shope. Pre dokončenie registrácie prosím potvrďte svoj e-mail kliknutím na nasledujúci odkaz:</p>
  <p><a href="<?= htmlspecialchars($verify_url) ?>"><?= htmlspecialchars($verify_url) ?></a></p>
  <p>Ak ste túto žiadosť nevyžiadali, ignorujte tento e-mail.</p>
  <p>S pozdravom,<br>tím e-shopu</p>
</body>
</html>
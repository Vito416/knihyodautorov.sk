<!DOCTYPE html>
<html lang="sk">
<head>
    <meta charset="UTF-8">
    <title>Overenie e-mailovej adresy</title>
</head>
<body>
    <h2>Overenie e-mailovej adresy</h2>
    <p>Dobrý deň <?= htmlspecialchars($user['name'] ?? $user['email']) ?>,</p>

    <p>ďakujeme za registráciu v našom e-shope.</p>

    <p>Na dokončenie aktivácie účtu prosím kliknite na nasledujúci odkaz:</p>
    <p><a href="<?= htmlspecialchars($verify_url) ?>">Overiť e-mail</a></p>

    <p>Ak ste si účet nevytvorili vy, tento e-mail ignorujte.</p>
</body>
</html>
<!DOCTYPE html>
<html lang="sk">
<head>
    <meta charset="UTF-8">
    <title>Obnovenie hesla</title>
</head>
<body>
    <h2>Obnovenie hesla</h2>
    <p>Dobrý deň <?= htmlspecialchars($user['name'] ?? $user['email']) ?>,</p>

    <p>požiadali ste o obnovenie hesla k svojmu účtu.</p>

    <p>Na nastavenie nového hesla použite nasledujúci odkaz:</p>
    <p><a href="<?= htmlspecialchars($reset_url) ?>">Obnoviť heslo</a></p>

    <p>Odkaz je platný len obmedzený čas. Ak ste o obnovenie hesla nepožiadali vy,
       tento e-mail môžete ignorovať.</p>
</body>
</html>
<!DOCTYPE html>
<html lang="sk">
<head>
    <meta charset="UTF-8">
    <title>Objednávka dokončená</title>
</head>
<body>
    <h2>Objednávka dokončená</h2>
    <p>Dobrý deň <?= htmlspecialchars($user['name'] ?? $user['email']) ?>,</p>

    <p>vaša objednávka č. <strong>#<?= (int)$order['id'] ?></strong> bola úspešne dokončená.</p>

    <p>Veríme, že budete spokojný s nákupom a tešíme sa na vašu ďalšiu návštevu.</p>
</body>
</html>
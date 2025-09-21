<!DOCTYPE html>
<html lang="sk">
<head>
    <meta charset="UTF-8">
    <title>Platba prijatá</title>
</head>
<body>
    <h2>Platba bola prijatá</h2>
    <p>Dobrý deň <?= htmlspecialchars($user['name'] ?? $user['email']) ?>,</p>

    <p>potvrdzujeme, že platba k vašej objednávke č. <strong>#<?= (int)$order['id'] ?></strong>
       vo výške <strong><?= number_format((float)$order['total'], 2, ',', ' ') ?> €</strong>
       bola úspešne prijatá.</p>

    <p>Objednávku spracovávame a o ďalších krokoch vás budeme informovať e-mailom.</p>
</body>
</html>
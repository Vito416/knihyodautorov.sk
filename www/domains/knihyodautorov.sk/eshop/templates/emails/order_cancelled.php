<!DOCTYPE html>
<html lang="sk">
<head>
    <meta charset="UTF-8">
    <title>Objednávka zrušená</title>
</head>
<body>
    <h2>Objednávka zrušená</h2>
    <p>Dobrý deň <?= htmlspecialchars($user['name'] ?? $user['email']) ?>,</p>

    <p>vaša objednávka č. <strong>#<?= (int)$order['id'] ?></strong> bola zrušená.</p>

    <?php if (!empty($reason)): ?>
        <p>Dôvod zrušenia: <?= htmlspecialchars($reason) ?></p>
    <?php endif; ?>

    <p>Ak máte otázky, kontaktujte nás prosím na <?= htmlspecialchars($_ENV['SUPPORT_EMAIL'] ?? 'podpora@example.com') ?>.</p>
</body>
</html>
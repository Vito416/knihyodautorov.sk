<!DOCTYPE html>
<html lang="sk">
<head>
    <meta charset="UTF-8">
    <title>Objednávka odoslaná</title>
</head>
<body>
    <h2>Vaša objednávka bola odoslaná</h2>
    <p>Dobrý deň <?= htmlspecialchars($user['name'] ?? $user['email']) ?>,</p>

    <p>vaša objednávka č. <strong>#<?= (int)$order['id'] ?></strong> bola odoslaná.</p>

    <?php if (!empty($tracking_url)): ?>
        <p>Sledovať zásielku môžete tu:
            <a href="<?= htmlspecialchars($tracking_url) ?>">Sledovanie zásielky</a>
        </p>
    <?php endif; ?>

    <p>Ďakujeme, že nakupujete u nás!</p>
</body>
</html>
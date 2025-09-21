<!DOCTYPE html>
<html lang="sk">
<head>
    <meta charset="UTF-8">
    <title>Faktúra k objednávke</title>
</head>
<body>
    <h2>Faktúra k objednávke</h2>
    <p>Dobrý deň <?= htmlspecialchars($user['name'] ?? $user['email']) ?>,</p>

    <p>k vašej objednávke č. <strong>#<?= (int)$order['id'] ?></strong> bola vystavená faktúra.</p>

    <p>Faktúru si môžete stiahnuť tu:
        <a href="<?= htmlspecialchars($invoice_url) ?>">Stiahnuť faktúru (PDF)</a>
    </p>

    <p>Celková suma: <strong><?= number_format((float)$order['total'], 2, ',', ' ') ?> €</strong></p>
</body>
</html>
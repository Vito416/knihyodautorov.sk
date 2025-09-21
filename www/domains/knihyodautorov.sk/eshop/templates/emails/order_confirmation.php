<!DOCTYPE html>
<html lang="sk">
<head>
    <meta charset="UTF-8">
    <title>Potvrdenie objednávky</title>
</head>
<body>
    <h2>Potvrdenie objednávky</h2>
    <p>Dobrý deň <?= htmlspecialchars($user['name'] ?? $user['email']) ?>,</p>

    <p>ďakujeme za vašu objednávku č. <strong>#<?= (int)$order['id'] ?></strong>
       z <?= htmlspecialchars($order['created_at']) ?>.</p>

    <p>Celková suma: <strong><?= number_format((float)$order['total'], 2, ',', ' ') ?> €</strong></p>

    <?php if (!empty($order['items']) && is_array($order['items'])): ?>
        <h3>Položky objednávky:</h3>
        <ul>
            <?php foreach ($order['items'] as $item): ?>
                <li>
                    <?= htmlspecialchars($item['title']) ?>
                    (<?= (int)$item['quantity'] ?> ×
                    <?= number_format((float)$item['price'], 2, ',', ' ') ?> €)
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <p>O ďalších krokoch vás budeme informovať e-mailom.</p>
</body>
</html>
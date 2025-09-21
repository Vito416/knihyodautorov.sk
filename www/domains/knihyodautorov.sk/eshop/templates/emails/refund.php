<!DOCTYPE html>
<html lang="sk">
<head>
    <meta charset="UTF-8">
    <title>Vrátenie platby</title>
</head>
<body>
    <h2>Platba bola vrátená</h2>
    <p>Dobrý deň <?= htmlspecialchars($user['name'] ?? $user['email']) ?>,</p>

    <p>k objednávke č. <strong>#<?= (int)$order['id'] ?></strong>
       bola spracovaná refundácia vo výške
       <strong><?= number_format((float)$refund['amount'], 2, ',', ' ') ?> €</strong>.</p>

    <?php if (!empty($refund['reason'])): ?>
        <p>Dôvod vrátenia: <?= htmlspecialchars($refund['reason']) ?></p>
    <?php endif; ?>

    <p>Prostriedky budú pripísané na váš účet v priebehu niekoľkých dní.</p>
</body>
</html>
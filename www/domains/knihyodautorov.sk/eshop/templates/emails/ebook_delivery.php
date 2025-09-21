<!DOCTYPE html>
<html lang="sk">
<head>
    <meta charset="UTF-8">
    <title>Doručenie elektronického obsahu</title>
</head>
<body>
    <h2>Vaše elektronické produkty sú pripravené</h2>
    <p>Dobrý deň <?= htmlspecialchars($user['name'] ?? $user['email']) ?>,</p>

    <p>ďakujeme za objednávku č. <strong>#<?= (int)$order['id'] ?></strong>.
       Zakúpené elektronické produkty si môžete stiahnuť cez nasledujúce odkazy:</p>

    <?php if (!empty($downloads) && is_array($downloads)): ?>
        <ul>
            <?php foreach ($downloads as $dl): ?>
                <li>
                    <a href="<?= htmlspecialchars($dl['url']) ?>">
                        <?= htmlspecialchars($dl['title']) ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <p>Odkazy sú aktívne po obmedzený čas, preto odporúčame stiahnuť súbory čo najskôr.</p>
</body>
</html>
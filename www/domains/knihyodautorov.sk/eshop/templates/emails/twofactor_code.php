<!DOCTYPE html>
<html lang="sk">
<head>
    <meta charset="UTF-8">
    <title>Dvojfaktorový overovací kód</title>
</head>
<body>
    <h2>Bezpečnostný kód</h2>
    <p>Dobrý deň <?= htmlspecialchars($user['name'] ?? $user['email']) ?>,</p>

    <p>váš overovací kód pre prihlásenie je:</p>

    <p><strong style="font-size: 20px;"><?= htmlspecialchars($code) ?></strong></p>

    <p>Kód je platný iba niekoľko minút.</p>
</body>
</html>
<!DOCTYPE html>
<html lang="sk">
<head>
    <meta charset="UTF-8">
    <title>Účet zablokovaný</title>
</head>
<body>
    <h2>Účet bol dočasne zablokovaný</h2>
    <p>Dobrý deň <?= htmlspecialchars($user['name'] ?? $user['email']) ?>,</p>

    <p>Z bezpečnostných dôvodov bol váš účet dočasne zablokovaný
       po viacerých neúspešných pokusoch o prihlásenie.</p>

    <p>Ak ste to neboli vy, odporúčame kontaktovať podporu:
       <?= htmlspecialchars($_ENV['SUPPORT_EMAIL'] ?? 'podpora@example.com') ?>.</p>
</body>
</html>
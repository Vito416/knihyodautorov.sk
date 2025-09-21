Účet bol dočasne zablokovaný

Dobrý deň <?= $user['name'] ?? $user['email'] ?>,

z bezpečnostných dôvodov bol váš účet dočasne zablokovaný
po viacerých neúspešných pokusoch o prihlásenie.

Ak ste to neboli vy, odporúčame kontaktovať podporu:
<?= $_ENV['SUPPORT_EMAIL'] ?? 'podpora@example.com' ?>.
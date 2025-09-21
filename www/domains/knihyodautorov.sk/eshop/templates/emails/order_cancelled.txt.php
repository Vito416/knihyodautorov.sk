Objednávka zrušená

Dobrý deň <?= $user['name'] ?? $user['email'] ?>,

vaša objednávka č. #<?= (int)$order['id'] ?> bola zrušená.
<?php if (!empty($reason)): ?>

Dôvod zrušenia: <?= $reason ?>
<?php endif; ?>

Ak máte otázky, kontaktujte nás prosím na <?= $_ENV['SUPPORT_EMAIL'] ?? 'podpora@example.com' ?>.
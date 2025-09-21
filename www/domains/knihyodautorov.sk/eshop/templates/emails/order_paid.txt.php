Platba bola prijatá

Dobrý deň <?= $user['name'] ?? $user['email'] ?>,

potvrdzujeme, že platba k vašej objednávke č. #<?= (int)$order['id'] ?>
vo výške <?= number_format((float)$order['total'], 2, ',', ' ') ?> € bola úspešne prijatá.

Objednávku spracovávame a o ďalších krokoch vás budeme informovať e-mailom.
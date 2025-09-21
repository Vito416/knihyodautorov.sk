Vaša objednávka bola odoslaná

Dobrý deň <?= $user['name'] ?? $user['email'] ?>,

vaša objednávka č. #<?= (int)$order['id'] ?> bola odoslaná.

<?php if (!empty($tracking_url)): ?>
Sledovať zásielku môžete tu: <?= $tracking_url ?>

<?php endif; ?>
Ďakujeme, že nakupujete u nás!
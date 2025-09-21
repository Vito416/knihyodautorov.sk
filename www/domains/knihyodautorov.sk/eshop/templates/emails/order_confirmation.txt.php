Potvrdenie objednávky

Dobrý deň <?= $user['name'] ?? $user['email'] ?>,

ďakujeme za vašu objednávku č. #<?= (int)$order['id'] ?> z <?= $order['created_at'] ?>.

Celková suma: <?= number_format((float)$order['total'], 2, ',', ' ') ?> €

<?php if (!empty($order['items']) && is_array($order['items'])): ?>
Položky objednávky:
<?php foreach ($order['items'] as $item): ?>
- <?= $item['title'] ?> (<?= (int)$item['quantity'] ?> × <?= number_format((float)$item['price'], 2, ',', ' ') ?> €)
<?php endforeach; ?>
<?php endif; ?>

O ďalších krokoch vás budeme informovať e-mailom.
Doručenie elektronického obsahu

Dobrý deň <?= $user['name'] ?? $user['email'] ?>,

ďakujeme za objednávku č. #<?= (int)$order['id'] ?>.
Zakúpené elektronické produkty si môžete stiahnuť cez nasledujúce odkazy:
<?php if (!empty($downloads) && is_array($downloads)): ?>
<?php foreach ($downloads as $dl): ?>
- <?= $dl['title'] ?>: <?= $dl['url'] ?>

<?php endforeach; ?>
<?php endif; ?>
Odkazy sú aktívne po obmedzený čas, preto odporúčame stiahnuť súbory čo najskôr.
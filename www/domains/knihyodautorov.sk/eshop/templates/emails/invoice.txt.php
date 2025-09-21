Faktúra k objednávke

Dobrý deň <?= $user['name'] ?? $user['email'] ?>,

k vašej objednávke č. #<?= (int)$order['id'] ?> bola vystavená faktúra.

Faktúru si môžete stiahnuť tu: <?= $invoice_url ?>

Celková suma: <?= number_format((float)$order['total'], 2, ',', ' ') ?> €
Platba bola vrátená

Dobrý deň <?= $user['name'] ?? $user['email'] ?>,

k objednávke č. #<?= (int)$order['id'] ?> bola spracovaná refundácia
vo výške <?= number_format((float)$refund['amount'], 2, ',', ' ') ?> €.
<?php if (!empty($refund['reason'])): ?>

Dôvod vrátenia: <?= $refund['reason'] ?>
<?php endif; ?>

Prostriedky budú pripísané na váš účet v priebehu niekoľkých dní.
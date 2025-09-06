// File: www/eshop/thank_you.php
$displayStatus = $statusFromGet ? e($statusFromGet) : ($dbStatus ? e($dbStatus) : 'UNKNOWN');


// Compose page
?><!doctype html>
<html lang="sk">
<head>
<meta charset="utf-8">
<title>Ďakujeme za objednávku</title>
<meta name="robots" content="noindex">
<style>body{font-family:Arial,Helvetica,sans-serif;max-width:800px;margin:2rem auto;padding:1rem}</style>
</head>
<body>
<h1>Ďakujeme za objednávku</h1>


<?php if ($isPaid): ?>
<p>Vaša objednávka číslo <strong>#<?php echo e((string)$order['id']); ?></strong> bola úspešne zaplatená.</p>
<?php else: ?>
<p>Objednávka číslo <strong>#<?php echo e((string)$order['id']); ?></strong> má stav: <strong><?php echo $displayStatus; ?></strong>.</p>
<p>Ak očakávate platbu, skontrolujte prosím stav platby neskôr alebo kontaktujte podporu.</p>
<?php endif; ?>


<h2>Detaily objednávky</h2>
<ul>
<li>Číslo objednávky: <strong>#<?php echo e((string)$order['id']); ?></strong></li>
<li>Vytvorená: <?php echo e((string)$order['created_at']); ?></li>
<li>Medzisúčet: <?php echo e((string)$order['subtotal'] ?? '0.00'); ?> <?php echo e((string)$order['currency'] ?? 'EUR'); ?></li>
<li>DPH (tax): <?php echo e((string)$order['tax_total'] ?? '0.00'); ?> <?php echo e((string)$order['currency'] ?? 'EUR'); ?></li>
<li>Celkom: <strong><?php echo e((string)$order['total'] ?? '0.00'); ?> <?php echo e((string)$order['currency'] ?? 'EUR'); ?></strong></li>
</ul>


<p>Ak máte otázky, kontaktujte nás prosím cez zákaznícku podporu.</p>
</body>
</html>
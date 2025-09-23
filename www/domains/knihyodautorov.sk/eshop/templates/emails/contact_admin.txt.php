<?php
// pages/email_templates/contact_admin.txt.php
// Plain text fallback. Variables: $name, $email, $message, $ip, $site

$senderName = ($name ?? '') !== '' ? ($name) : 'Neznámy odosielateľ';
$senderEmail = ($email ?? '') !== '' ? ($email) : 'neznama@domena';
$site = ($site ?? '') ?: ($_SERVER['SERVER_NAME'] ?? '');
$ip = ($ip ?? '') ?: 'neznáme';
$msg = $message ?? '';
?>
Nová správa z kontaktného formulára
Stránka: <?= $site ?>

Meno: <?= $senderName ?>
E-mail: <?= $senderEmail ?>
IP: <?= $ip ?>

Správa:
<?= $msg ?>


----
Toto je notifikácia zo stránky <?= $site ?>.
&copy; <?= date('Y') ?> <?= ($_ENV['APP_NAME'] ?? 'Vaša stránka') ?>
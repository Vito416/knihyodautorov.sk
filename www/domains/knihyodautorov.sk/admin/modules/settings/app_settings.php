// File: www/admin/modules/settings/app_settings.php


if (empty($errors)) {
// For sensitive values you may want to encrypt before saving; here we save plaintext but recommend secure storage
$ok = saveSettings($db, $toSave);
if ($ok) {
$messages[] = 'Nastavenia boli uložené.';
$existing = loadSettings($db, array_keys($allowedKeys));
} else {
$errors[] = 'Chyba pri ukladaní nastavení.';
}
}
}


$csrf = genCsrfApp();
?><!doctype html>
<html lang="sk">
<head>
<meta charset="utf-8">
<title>Nastavenia aplikácie</title>
<meta name="robots" content="noindex">
<style>body{font-family:Arial,Helvetica,sans-serif;max-width:900px;margin:1rem auto;padding:1rem}label{display:block;margin-bottom:0.5rem}</style>
</head>
<body>
<h1>Nastavenia aplikácie</h1>
<?php foreach ($messages as $m): ?><div style="padding:0.5rem;background:#e6ffed;margin-bottom:0.5rem"><?php echo e($m); ?></div><?php endforeach; ?>
<?php foreach ($errors as $err): ?><div style="padding:0.5rem;background:#fff1f0;margin-bottom:0.5rem"><?php echo e($err); ?></div><?php endforeach; ?>


<form method="post" action="">
<label>GoPay Merchant ID<br><input name="gopay_merchant_id" value="<?php echo e((string)$existing['gopay_merchant_id']); ?>" style="width:100%"></label>
<label>GoPay Secret (citlivé)<br><input name="gopay_secret" value="<?php echo e((string)$existing['gopay_secret']); ?>" style="width:100%"></label>
<hr>
<label>SMTP Host<br><input name="smtp_host" value="<?php echo e((string)$existing['smtp_host']); ?>" style="width:100%"></label>
<label>SMTP Port<br><input name="smtp_port" value="<?php echo e((string)$existing['smtp_port']); ?>" style="width:100%"></label>
<label>SMTP User<br><input name="smtp_user" value="<?php echo e((string)$existing['smtp_user']); ?>" style="width:100%"></label>
<label>SMTP Password (citlivé)<br><input name="smtp_pass" value="<?php echo e((string)$existing['smtp_pass']); ?>" style="width:100%"></label>


<input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
<br><button type="submit">Uložiť nastavenia</button>
</form>


<p style="margin-top:1rem;color:#666">Poznámka: citlivé polia (heslá, tajné kľúče) je lepšie ukladať zašifrované alebo v bezpečnom úložisku mimo DB. Tento editor ukladajú hodnoty priamo do tabuľky <code>settings</code>.</p>
</body>
</html>
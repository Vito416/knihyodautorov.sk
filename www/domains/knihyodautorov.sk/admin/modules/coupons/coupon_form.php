// File: www/admin/modules/coupons/coupon_form.php
'max_redemptions' => $existing['max_redemptions'] ?? '',
'applies_to' => $existing['applies_to'] ?? '',
'is_active' => isset($existing['is_active']) ? (int)$existing['is_active'] : 1,
];
$csrf = genCsrf();


?><!doctype html>
<html lang="sk">
<head>
<meta charset="utf-8">
<title><?php echo $id>0 ? 'Upraviť kupón' : 'Nový kupón'; ?></title>
<meta name="robots" content="noindex">
<style>body{font-family:Arial,Helvetica,sans-serif;max-width:900px;margin:1rem auto;padding:1rem}</style>
</head>
<body>
<h1><?php echo $id>0 ? 'Upraviť kupón' : 'Nový kupón'; ?></h1>


<?php foreach ($errors as $err): ?><div style="background:#fff1f0;padding:0.5rem;margin-bottom:0.5rem"><?php echo e($err); ?></div><?php endforeach; ?>


<form method="post" action="">
<label>Kód kupónu<br><input name="code" value="<?php echo e((string)$values['code']); ?>" required style="width:100%"></label><br><br>
<label>Typ zľavy<br>
<select name="type">
<option value="fixed" <?php echo $values['type']==='fixed'? 'selected':''; ?>>Fixná</option>
<option value="percent" <?php echo $values['type']==='percent'? 'selected':''; ?>>Percentuálna</option>
</select>
</label><br><br>
<label>Hodnota zľavy<br><input name="value" value="<?php echo e((string)$values['value']); ?>" required style="width:100%"></label><br><br>
<label>Mena (3 znaky)<br><input name="currency" value="<?php echo e((string)$values['currency']); ?>" required style="width:100%"></label><br><br>
<label>Minimálna suma (voliteľné)<br><input name="min_amount" value="<?php echo e((string)$values['min_order_amount']); ?>" style="width:100%"></label><br><br>
<label>Platné od (YYYY-MM-DD alebo YYYY-MM-DD HH:MM:SS)<br><input name="starts_at" value="<?php echo e((string)$values['starts_at']); ?>" style="width:100%"></label><br><br>
<label>Platné do (voliteľné)<br><input name="ends_at" value="<?php echo e((string)$values['ends_at']); ?>" style="width:100%"></label><br><br>
<label>Max. počet uplatnení (voliteľné)<br><input name="max_redemptions" value="<?php echo e((string)$values['max_redemptions']); ?>" style="width:100%"></label><br><br>
<label>Applies_to (JSON, voliteľné)<br><textarea name="applies_to" style="width:100%;height:80px"><?php echo e((string)$values['applies_to']); ?></textarea></label><br><br>
<label><input type="checkbox" name="is_active" value="1" <?php echo $values['is_active'] ? 'checked':''; ?>> Aktívny</label><br><br>
<input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
<button type="submit">Uložiť</button>
</form>


</body>
</html>
// File: www/admin/modules/taxes/tax_rates.php
// Handle create/update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
$postedCsrf = $_POST['csrf_token'] ?? '';
if (!validateCsrfTax($postedCsrf)) $errors[] = 'Neplatný CSRF token.';


$id = isset($_POST['id']) && $_POST['id'] !== '' ? (int)$_POST['id'] : null;
$name = isset($_POST['name']) ? trim((string)$_POST['name']) : '';
$percent = isset($_POST['percent']) ? $_POST['percent'] : null;


if ($name === '') $errors[] = 'Názov sazby je povinný.';
if ($percent === null || !is_numeric($percent) || (float)$percent < 0 || (float)$percent > 100) $errors[] = 'Neplatné percento (0-100).';


if (empty($errors)) {
try {
if ($id) {
$upd = $db->prepare('UPDATE tax_rates SET name = :name, percent = :percent, updated_at = NOW() WHERE id = :id');
$upd->execute([':name' => $name, ':percent' => (float)$percent, ':id' => $id]);
$messages[] = 'Sadzba bola aktualizovaná.';
} else {
$ins = $db->prepare('INSERT INTO tax_rates (name, percent, created_at) VALUES (:name, :percent, NOW())');
$ins->execute([':name' => $name, ':percent' => (float)$percent]);
$messages[] = 'Sadzba bola pridaná.';
}
} catch (PDOException $e) {
$errors[] = 'Chyba pri uložení do DB.';
}
}
}


// Fetch existing rates
$stmt = $db->prepare('SELECT id, name, percent FROM tax_rates ORDER BY name ASC');
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);


$csrf = genCsrfTax();


?><!doctype html>
<html lang="sk">
<head>
<meta charset="utf-8">
<title>Správa DPH sadzieb</title>
<meta name="robots" content="noindex">
<style>body{font-family:Arial,Helvetica,sans-serif;max-width:900px;margin:1rem auto;padding:1rem}table{width:100%;border-collapse:collapse}th,td{padding:0.5rem;border:1px solid #ddd}</style>
</head>
<body>
<h1>Správa DPH sadzieb</h1>


<?php foreach ($messages as $m): ?><div style="padding:0.5rem;background:#e6ffed;margin-bottom:0.5rem"><?php echo e($m); ?></div><?php endforeach; ?>
<?php foreach ($errors as $err): ?><div style="padding:0.5rem;background:#fff1f0;margin-bottom:0.5rem"><?php echo e($err); ?></div><?php endforeach; ?>


<h2>Existujúce sadzby</h2>
<table>
<thead><tr><th>Názov</th><th>Percento</th><th>Akcie</th></tr></thead>
<tbody>
<?php foreach ($rows as $r): ?>
<tr>
<td><?php echo e($r['name']); ?></td>
<td><?php echo e((string)$r['percent']); ?>%</td>
<td><a href="?edit=<?php echo e((string)$r['id']); ?>">Upraviť</a></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>


<h2><?php echo isset($_GET['edit']) ? 'Upraviť sazbu' : 'Pridať novú sazbu'; ?></h2>
<?php
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : null;
$editRow = null;
if ($editId) {
foreach ($rows as $r) if ((int)$r['id'] === $editId) { $editRow = $r; break; }
}
?>
<form method="post" action="">
<input type="hidden" name="id" value="<?php echo e((string)($editRow['id'] ?? '')); ?>">
<label>Názov<br><input name="name" value="<?php echo e((string)($editRow['name'] ?? '')); ?>" required style="width:100%"></label><br><br>
<label>Percento<br><input name="percent" value="<?php echo e((string)($editRow['percent'] ?? '')); ?>" required style="width:100%"></label><br><br>
<input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
<button type="submit"><?php echo $editRow ? 'Uložiť zmeny' : 'Pridať sazbu'; ?></button>
</form>


</body>
</html>
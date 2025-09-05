<?php
require __DIR__ . '/../../inc/bootstrap.php';
$stmt = $db->query('SELECT id, email, is_active, created_at FROM pouzivatelia ORDER BY created_at DESC LIMIT 200');
$rows = $stmt->fetchAll();
?><!doctype html><html><head><meta charset="utf-8"><title>Užívatelia</title></head><body>
<?php include __DIR__ . '/../templates/admin-header.php'; ?>
<main>
<h1>Užívatelia</h1>
<table><tr><th>ID</th><th>Email</th><th>Aktívny</th><th>Vytvorený</th></tr>
<?php foreach($rows as $r): ?>
<tr><td><?=e($r['id'])?></td><td><?=e($r['email'])?></td><td><?=e($r['is_active'])?></td><td><?=e($r['created_at'])?></td></tr>
<?php endforeach; ?></table>
</main>
</body></html>
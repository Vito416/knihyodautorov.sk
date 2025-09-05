<?php
// admin/modules/audit/audit_log.php
require __DIR__ . '/../../inc/bootstrap.php';
$from = $_GET['from'] ?? null;
$to = $_GET['to'] ?? null;
$q = 'SELECT * FROM audit_log WHERE 1=1';
$params = [];
if ($from) { $q .= ' AND created_at >= ?'; $params[] = $from . ' 00:00:00'; }
if ($to) { $q .= ' AND created_at <= ?'; $params[] = $to . ' 23:59:59'; }
$q .= ' ORDER BY created_at DESC LIMIT 500';
$stmt = $db->prepare($q);
$stmt->execute($params);
$rows = $stmt->fetchAll();
?>
<!doctype html><html lang="sk"><head><meta charset="utf-8"><title>Audit log</title></head><body>
<?php include __DIR__ . '/../templates/admin-header.php'; ?>
<main>
  <h1>Audit log</h1>
  <form method="get">
    <label>Od<input type="date" name="from" value="<?=e($from)?>"></label>
    <label>Do<input type="date" name="to" value="<?=e($to)?>"></label>
    <button type="submit">Filtrovať</button>
  </form>
  <table border="1" cellpadding="6"><tr><th>ID</th><th>Užívateľ</th><th>Akcia</th><th>Entita</th><th>Detaily</th><th>Čas</th></tr>
    <?php foreach($rows as $r): ?>
      <tr>
        <td><?=e($r['id'])?></td>
        <td><?=e($r['actor_id'] ?? $r['actor_name'] ?? 'system')?></td>
        <td><?=e($r['action'])?></td>
        <td><?=e($r['entity'])?></td>
        <td><pre><?=e($r['details'])?></pre></td>
        <td><?=e($r['created_at'])?></td>
      </tr>
    <?php endforeach; ?>
  </table>
</main>
</body></html>
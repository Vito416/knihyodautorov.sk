<?php
// admin/modules/reports/sales_report.php
require __DIR__ . '/../../inc/bootstrap.php';
$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-d');
$format = $_GET['format'] ?? 'html';
$stmt = $db->prepare('SELECT o.id, o.created_at, o.total, o.currency, COALESCE(u.email, "guest") AS buyer FROM orders o LEFT JOIN pouzivatelia u ON u.id=o.user_id WHERE o.created_at BETWEEN ? AND ? ORDER BY o.created_at ASC');
$stmt->execute([$from.' 00:00:00', $to.' 23:59:59']);
$rows = $stmt->fetchAll();
if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="sales_'.$from.'_'.$to.'.csv"');
    $out = fopen('php://output','w');
    fputcsv($out, ['order_id','created_at','total','currency','buyer']);
    foreach($rows as $r) fputcsv($out, [$r['id'],$r['created_at'],$r['total'],$r['currency'],$r['buyer']]);
    fclose($out); exit;
}
?>
<!doctype html><html lang="sk"><head><meta charset="utf-8"><title>Predajný report</title></head><body>
<?php include __DIR__ . '/../templates/admin-header.php'; ?>
<main>
  <h1>Predajný report</h1>
  <form method="get">
    <label>Od<input type="date" name="from" value="<?=e($from)?>"></label>
    <label>Do<input type="date" name="to" value="<?=e($to)?>"></label>
    <button type="submit">Zobraziť</button>
    <a href="?from=<?=urlencode($from)?>&to=<?=urlencode($to)?>&format=csv">Stiahnuť CSV</a>
  </form>
  <table border="1"><tr><th>ID</th><th>Dátum</th><th>Celkom</th><th>Mena</th><th>Zákazník</th></tr>
  <?php foreach($rows as $r): ?>
    <tr><td><?=e($r['id'])?></td><td><?=e($r['created_at'])?></td><td><?=number_format($r['total'],2,',',' ')?></td><td><?=e($r['currency'])?></td><td><?=e($r['buyer'])?></td></tr>
  <?php endforeach; ?>
  </table>
</main>
</body></html>
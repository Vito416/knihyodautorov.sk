<?php
require __DIR__ . '/../../inc/bootstrap.php';
$what = $_GET['what'] ?? 'orders';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="'.$what.'_'.date('Ymd_His').'.csv"');
$out = fopen('php://output','w');
if ($what === 'orders') {
    fputcsv($out, ['order_id','user_id','status','total','created_at']);
    $rows = $db->query('SELECT id,user_id,status,total,created_at FROM orders ORDER BY created_at DESC')->fetchAll();
    foreach($rows as $r) fputcsv($out, [$r['id'],$r['user_id'],$r['status'],$r['total'],$r['created_at']]);
}
fclose($out);
exit;
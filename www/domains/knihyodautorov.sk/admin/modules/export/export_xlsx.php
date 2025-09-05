<?php
require __DIR__ . '/../../inc/bootstrap.php';
require __DIR__ . '/../../../../libs/Excel.php';
$what = $_GET['what'] ?? 'orders';
$rows_out = [];
if ($what === 'orders') {
    $rows_raw = $db->query('SELECT id,user_id,status,total,created_at FROM orders ORDER BY created_at DESC')->fetchAll();
    $rows_out[] = ['order_id','user_id','status','total','created_at'];
    foreach($rows_raw as $r) $rows_out[] = [$r['id'],$r['user_id'],$r['status'],$r['total'],$r['created_at']];
}
$tmp = sys_get_temp_dir().'/export_'.bin2hex(random_bytes(6)).'.xlsx';
Excel::array_to_xlsx($rows_out, $tmp);
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="'.$what.'_'.date('Ymd_His').'.xlsx"');
readfile($tmp);
unlink($tmp);
exit;
<?php
// /admin/export-orders.php
require_once __DIR__ . '/bootstrap.php';
require_admin();

$rows = $pdo->query("SELECT o.id,o.total_price,o.currency,o.status,o.created_at,u.email AS user_email FROM orders o LEFT JOIN users u ON o.user_id=u.id ORDER BY o.id")->fetchAll(PDO::FETCH_ASSOC);
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=orders-' . date('Ymd') . '.csv');
$out = fopen('php://output','w');
fputcsv($out, ['id','total_price','currency','status','created_at','user_email']);
foreach($rows as $r) fputcsv($out, [$r['id'],$r['total_price'],$r['currency'],$r['status'],$r['created_at'],$r['user_email']]);
fclose($out);
exit;

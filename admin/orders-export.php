<?php
// /admin/orders-export.php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
if (!admin_is_logged()) { header('Location: login.php'); exit; }

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="orders_' . date('Ymd_His') . '.csv"');
$out = fopen('php://output','w');
fputs($out, "\xEF\xBB\xBF");
fputcsv($out, ['order_id','user','email','total_price','currency','status','created_at']);

$stmt = $pdo->query("SELECT o.id,o.total_price,o.currency,o.status,o.created_at,u.meno,u.email FROM orders o LEFT JOIN users u ON o.user_id=u.id ORDER BY o.created_at DESC");
while($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    fputcsv($out, [
        $r['id'],
        $r['meno'] ?? '',
        $r['email'] ?? '',
        $r['total_price'],
        $r['currency'],
        $r['status'],
        $r['created_at']
    ]);
}
fclose($out);
exit;
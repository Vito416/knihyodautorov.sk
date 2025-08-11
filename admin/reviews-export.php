<?php
// /admin/reviews-export.php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
if (!admin_is_logged()) { header('Location: login.php'); exit; }

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="reviews_' . date('Ymd_His') . '.csv"');
$out = fopen('php://output','w'); fputs($out, "\xEF\xBB\xBF");
fputcsv($out, ['id','book','user','rating','comment','created_at','approved']);

$stmt = $pdo->query("SELECT r.*, b.nazov as book_name, u.meno as user_name FROM reviews r LEFT JOIN books b ON r.book_id=b.id LEFT JOIN users u ON r.user_id=u.id ORDER BY r.created_at DESC");
while($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    fputcsv($out, [
        $r['id'],
        $r['book_name'] ?? '',
        $r['user_name'] ?? '',
        $r['rating'],
        $r['comment'],
        $r['created_at'],
        $r['approved'] ?? ''
    ]);
}
fclose($out); exit;
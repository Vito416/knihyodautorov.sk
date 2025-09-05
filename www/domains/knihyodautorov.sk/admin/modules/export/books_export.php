<?php
require __DIR__ . '/../../inc/bootstrap.php';
$format = $_GET['format'] ?? 'csv';
$rows = $db->query('SELECT b.id,b.title,b.slug,b.price,a.meno AS author,b.is_active FROM books b LEFT JOIN authors a ON a.id=b.author_id ORDER BY b.id')->fetchAll();
if ($format === 'xlsx') {
    require __DIR__ . '/../../../../libs/Excel.php';
    $out = [['id','title','slug','price','author','is_active']];
    foreach($rows as $r) $out[] = [$r['id'],$r['title'],$r['slug'],$r['price'],$r['author'],$r['is_active']];
    $tmp = sys_get_temp_dir().'/books_'.bin2hex(random_bytes(5)).'.xlsx';
    Excel::array_to_xlsx($out, $tmp);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="books_'.date('Ymd').'.xlsx"');
    readfile($tmp);
    unlink($tmp);
    exit;
} else {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="books_'.date('Ymd').'.csv"');
    $out = fopen('php://output','w');
    fputcsv($out, ['id','title','slug','price','author','is_active']);
    foreach($rows as $r) fputcsv($out, [$r['id'],$r['title'],$r['slug'],$r['price'],$r['author'],$r['is_active']]);
    fclose($out); exit;
}
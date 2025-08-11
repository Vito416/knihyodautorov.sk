<?php
// /admin/export-books.php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
if (!admin_is_logged()) { header('Location: login.php'); exit; }

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="books_export_' . date('Ymd_His') . '.csv"');
$out = fopen('php://output', 'w');
fputs($out, "\xEF\xBB\xBF"); // BOM pre Excel

fputcsv($out, ['id','nazov','slug','cena','mena','author','category','is_active','pdf_file','obrazok','created_at']);

$stmt = $pdo->query("SELECT b.*, a.meno as author_name, c.nazov as category_name FROM books b LEFT JOIN authors a ON b.author_id = a.id LEFT JOIN categories c ON b.category_id = c.id ORDER BY b.id DESC");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    fputcsv($out, [
        $row['id'],
        $row['nazov'],
        $row['slug'],
        $row['cena'],
        $row['mena'],
        $row['author_name'],
        $row['category_name'],
        $row['is_active'],
        $row['pdf_file'],
        $row['obrazok'],
        $row['created_at'] ?? ''
    ]);
}
fclose($out);
exit;
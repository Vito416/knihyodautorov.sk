<?php
// /admin/exports.php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/partials/notifications.php';
require_admin();

$type = $_GET['type'] ?? 'users';
$format = strtolower((string)($_GET['format'] ?? 'csv'));
$allowed = ['users','books','orders','reviews'];
if (!in_array($type, $allowed, true)) {
    header('Location: /admin/index.php'); exit;
}
$allowedFormats = ['csv','xlsx'];
if (!in_array($format, $allowedFormats, true)) $format = 'csv';

// prepare data
$filenameBase = 'export_'.$type.'_'.date('Ymd_His');
$rows = [];
$headers = [];

try {
    if ($type === 'users') {
        $headers = ['id','meno','email','telefon','datum_registracie','newsletter'];
        $stmt = $pdo->query("SELECT id, meno, email, telefon, datum_registracie, newsletter FROM users ORDER BY id ASC");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($type === 'books') {
        $headers = ['id','nazov','slug','cena','mena','author_id','category_id','is_active','created_at'];
        $stmt = $pdo->query("SELECT id, nazov, slug, cena, mena, author_id, category_id, is_active, created_at FROM books ORDER BY id ASC");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($type === 'orders') {
        $headers = ['id','user_id','total_price','currency','status','payment_method','created_at'];
        $stmt = $pdo->query("SELECT id, user_id, total_price, currency, status, payment_method, created_at FROM orders ORDER BY id ASC");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($type === 'reviews') {
        $headers = ['id','book_id','user_id','rating','comment','created_at'];
        $stmt = $pdo->query("SELECT id, book_id, user_id, rating, comment, created_at FROM reviews ORDER BY id ASC");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    admin_flash_set('error','Chyba pri získavaní dát pre export: '.$e->getMessage());
    header('Location: /admin/index.php'); exit;
}

// If XLSX requested and PhpSpreadsheet exists -> generate XLSX
if ($format === 'xlsx' && class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
    $tmp = sys_get_temp_dir() . '/' . $filenameBase . '.xlsx';
    $spread = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spread->getActiveSheet();
    // headers
    $col = 1;
    foreach ($headers as $h) {
        $sheet->setCellValueByColumnAndRow($col++, 1, $h);
    }
    $r = 2;
    foreach ($rows as $row) {
        $col = 1;
        foreach ($headers as $h) {
            $sheet->setCellValueByColumnAndRow($col++, $r, $row[$h] ?? '');
        }
        $r++;
    }
    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spread);
    $writer->save($tmp);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="'.$filenameBase.'.xlsx"');
    readfile($tmp);
    @unlink($tmp);
    exit;
}

// Otherwise CSV fallback
$csvPath = sys_get_temp_dir() . '/' . $filenameBase . '.csv';
$fh = fopen($csvPath, 'w');
if ($fh === false) {
    admin_flash_set('error','Nepodarilo sa vytvoriť dočasný súbor pre export.');
    header('Location: /admin/index.php'); exit;
}
// UTF-8 BOM for Excel
fwrite($fh, chr(0xEF).chr(0xBB).chr(0xBF));
fputcsv($fh, $headers, ',', '"');
foreach ($rows as $row) {
    $line = [];
    foreach ($headers as $h) $line[] = $row[$h] ?? '';
    fputcsv($fh, $line, ',', '"');
}
fclose($fh);
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="'.$filenameBase.'.csv"');
readfile($csvPath);
@unlink($csvPath);
exit;
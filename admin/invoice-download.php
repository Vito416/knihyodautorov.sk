<?php
// /admin/invoice-download.php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
if (!admin_is_logged()) { header('Location: login.php'); exit; }

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); echo 'Invalid'; exit; }

$stmt = $pdo->prepare("SELECT pdf_file, invoice_number FROM invoices WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$r = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$r) { http_response_code(404); echo 'Not found'; exit; }

if (empty($r['pdf_file'])) {
    // if pdf missing, redirect to view (which shows HTML)
    header('Location: /admin/invoice-view.php?id=' . $id);
    exit;
}

$invoicesDir = realpath(__DIR__ . '/../eshop/invoices') ?: __DIR__ . '/../eshop/invoices';
$path = $invoicesDir . DIRECTORY_SEPARATOR . $r['pdf_file'];
if (!file_exists($path) || !is_readable($path)) { header('Location: /admin/invoice-view.php?id=' . $id); exit; }

$filename = preg_replace('/[^A-Za-z0-9_\-\.]/','_', $r['invoice_number']) . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
exit;
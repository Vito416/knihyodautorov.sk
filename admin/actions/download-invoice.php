<?php
// /admin/actions/download-invoice.php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
require __DIR__ . '/../bootstrap.php';
require_admin();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(404); echo "Neplatné ID"; exit; }

$stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$inv = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$inv) { http_response_code(404); echo "Faktúra nenájdená"; exit; }

$filename = $inv['pdf_file'] ?? '';
$dir = realpath(__DIR__ . '/../eshop/invoices') ?: (__DIR__ . '/../eshop/invoices');
$path = $dir . '/' . $filename;
if (!file_exists($path)) { http_response_code(404); echo "Súbor nenájdený"; exit; }

// bezpečné stiahnutie
$basename = basename($path);
$mime = mime_content_type($path) ?: 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: attachment; filename="' . $basename . '"');
readfile($path);
exit;
<?php
// /admin/serve-book.php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
if (!admin_is_logged()) { http_response_code(403); echo 'Forbidden'; exit; }

$book_id = (int)($_GET['book_id'] ?? 0);
if ($book_id <= 0) { http_response_code(400); echo 'Invalid request'; exit; }

$stmt = $pdo->prepare("SELECT pdf_file, nazov FROM books WHERE id = ? LIMIT 1");
$stmt->execute([$book_id]);
$b = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$b || empty($b['pdf_file'])) { http_response_code(404); echo 'Súbor nenájdený'; exit; }

$root = realpath(__DIR__ . '/../books-pdf');
$filename = basename($b['pdf_file']);
$path = $root . DIRECTORY_SEPARATOR . $filename;
if (!file_exists($path) || !is_readable($path)) { http_response_code(404); echo 'Súbor chýba'; exit; }

$ctype = mime_content_type($path) ?: 'application/pdf';
header('Content-Description: File Transfer');
header('Content-Type: ' . $ctype);
header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_\-\.]/','_', $b['nazov']) . '.pdf"');
header('Content-Length: ' . filesize($path));
readfile($path);
exit;
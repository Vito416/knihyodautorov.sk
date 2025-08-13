<?php
// /eshop/download.php
require __DIR__ . '/_init.php';
$token = trim((string)($_GET['token'] ?? ''));
if ($token === '') { http_response_code(400); echo "Token chýba"; exit; }

$stmt = $pdo->prepare("SELECT dt.id AS token_id, dt.book_id, dt.user_id, dt.expires_at, dt.used, b.pdf_file, b.pdf_file_path, b.obrazok FROM download_tokens dt LEFT JOIN books b ON b.id = dt.book_id WHERE dt.token = ? LIMIT 1");
$stmt->execute([$token]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) { http_response_code(404); echo "Token not found"; exit; }
if ($row['used']) { http_response_code(403); echo "Token already used"; exit; }
if ($row['expires_at'] && (new DateTime($row['expires_at'])) < new DateTime()) { http_response_code(403); echo "Token expired"; exit; }

$bookId = (int)$row['book_id'];
// file path: prefer pdf_file_path or pdf_file or pdf_file in /books-pdf
$possible = [];
if (!empty($row['pdf_file_path'])) $possible[] = $row['pdf_file_path'];
if (!empty($row['pdf_file'])) $possible[] = __DIR__ . '/../books-pdf/' . $row['pdf_file'];
if (!empty($row['pdf_file_path']) && file_exists($row['pdf_file_path'])) $file = $row['pdf_file_path'];
else {
    $file = null;
    foreach ($possible as $p) if (file_exists($p)) { $file = $p; break; }
}
if (!$file) { http_response_code(404); echo "Súbor nie je dostupný"; exit; }

// mark used (one-time)
$pdo->prepare("UPDATE download_tokens SET used = 1 WHERE id = ?")->execute([(int)$row['token_id']]);

// serve file
$basename = basename($file);
$mime = mime_content_type($file) ?: 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . $basename . '"');
header('Content-Length: ' . filesize($file));
readfile($file);
exit;
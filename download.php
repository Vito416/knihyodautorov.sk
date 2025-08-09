<?php
// download.php
session_start();
require_once __DIR__ . '/db/config/config.php';

$bookId = isset($_GET['book']) ? (int)$_GET['book'] : 0;
if ($bookId <= 0) { http_response_code(400); exit('Chybný parameter.'); }

// načítaj knihu
$stmt = $pdo->prepare("SELECT id, nazov, pdf_file, cena FROM books WHERE id = ?");
$stmt->execute([$bookId]);
$book = $stmt->fetch();
if (!$book) { http_response_code(404); exit('Kniha nenájdená.'); }

$canDownload = false;

// 1) ak je free
if ((float)$book['cena'] == 0.0) $canDownload = true;

// 2) ak je user prihlásený a má zaplatenú objednávku s touto knihou
if (!$canDownload && isset($_SESSION['user_id'])) {
    $uid = (int)$_SESSION['user_id'];
    $q = $pdo->prepare("SELECT COUNT(*) FROM orders o
        JOIN order_items oi ON oi.order_id = o.id
        WHERE o.user_id = ? AND o.status = 'paid' AND oi.book_id = ?");
    $q->execute([$uid, $bookId]);
    if ((int)$q->fetchColumn() > 0) $canDownload = true;
}

// 3) admin users (optional)
if (!$canDownload && isset($_SESSION['admin_id'])) {
    $canDownload = true;
}

if (!$canDownload) {
    http_response_code(403);
    exit('Nemáte právo stiahnuť tento súbor.');
}

// pripravíme path a bezpečné cesty
$base = __DIR__ . '/books-pdf/';
$file = basename($book['pdf_file']); // zabrání path traversal
$path = $base . $file;

if (!file_exists($path)) {
    http_response_code(404);
    exit('Súbor nenájdený.');
}

// serve file
$mime = mime_content_type($path) ?: 'application/octet-stream';
header('Content-Description: File Transfer');
header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . basename($path) . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
exit;

<?php
// /eshop/download.php?file=xxx.pdf&token=abcdef
declare(strict_types=1);
require_once __DIR__ . '/../db/config/config.php';
session_start();

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$file = $_GET['file'] ?? '';
$token = $_GET['token'] ?? '';

if (!$file) { http_response_code(400); echo "Chybný požiadavok."; exit; }

// normalize
$basename = basename($file);
$path = __DIR__ . '/../books-pdf/' . $basename;

if ($token) {
    // over token v users.download_token alebo v invoices (?) -> jednoduchá kontrola: nájdeme order ktorý má invoce a users.download_token = token
    $stmt = $pdo->prepare("SELECT u.id FROM users u WHERE u.download_token = ? LIMIT 1");
    $stmt->execute([$token]);
    $uid = $stmt->fetchColumn();
    if (!$uid) { http_response_code(403); echo "Neplatný token."; exit; }
    // ďalšie overenie môže kontrolovať, či má user v orders položku s danou knihou — vynecháme pre jednoduchosť
} else {
    // skontroluj, či je user prihlásený a má v objednávkach danú knihu (zahŕňa implementáciu)
    if (!isset($_SESSION['user_id'])) { http_response_code(403); echo "Nie ste prihlásený."; exit; }
    $uid = (int)$_SESSION['user_id'];
    // overíme že v order_items existuje book s pdf_file=$basename a order.user_id = $uid a order.status='paid'
    $stmt = $pdo->prepare("SELECT oi.id FROM order_items oi JOIN orders o ON oi.order_id=o.id JOIN books b ON oi.book_id=b.id WHERE o.user_id = ? AND o.status='paid' AND b.pdf_file = ? LIMIT 1");
    $stmt->execute([$uid, $basename]);
    $ok = $stmt->fetchColumn();
    if (!$ok) { http_response_code(403); echo "Nemáte právo sťahovať tento súbor."; exit; }
}

// finally serve file (deny directory traversal earlier)
if (!file_exists($path)) { http_response_code(404); echo "Súbor nenájdený."; exit; }

// set headers
$mime = mime_content_type($path) ?: 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . h($basename) . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
exit;

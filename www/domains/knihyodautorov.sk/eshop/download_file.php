<?php
require __DIR__ . '/inc/bootstrap.php';
Auth::requireLogin();
$id = (int)($_GET['order_item_id'] ?? 0);
$stmt = $db->prepare('SELECT oi.*, o.user_id, ba.storage_path FROM order_items oi JOIN orders o ON o.id=oi.order_id LEFT JOIN book_assets ba ON ba.book_id=oi.book_id AND ba.asset_type = ? WHERE oi.id = ? LIMIT 1');
$stmt->execute(['pdf', $id]);
$r = $stmt->fetch();
if (!$r || $r['user_id'] != ($_SESSION['user_id'] ?? 0)) { http_response_code(403); echo 'Forbidden'; exit; }
$path = $r['storage_path'] ?? null;
if (!$path || !file_exists($path)) { http_response_code(404); echo 'Súbor nenájdený'; exit; }
// stream the file (if encrypted, decrypt on-the-fly)
header('Content-Description: File Transfer');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="'.basename($path).'"');
readfile($path);
exit;
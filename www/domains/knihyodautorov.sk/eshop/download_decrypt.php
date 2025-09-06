<?php
// New download endpoint that decrypts .enc storage files on-the-fly and streams to user.
// This file does NOT replace existing download_file.php — it is an improved endpoint you can use instead.
require __DIR__ . '/inc/bootstrap.php';
Auth::requireLogin();
$id = (int)($_GET['order_item_id'] ?? 0);
if (!$id) { http_response_code(400); echo 'Chýba parameter'; exit; }
// load order_item + order + asset
$stmt = $db->prepare('SELECT oi.*, o.user_id, ba.storage_path, ba.download_filename FROM order_items oi JOIN orders o ON o.id=oi.order_id LEFT JOIN book_assets ba ON ba.book_id=oi.book_id AND ba.asset_type = ? WHERE oi.id = ? LIMIT 1');
$stmt->execute(['pdf',$id]);
$r = $stmt->fetch();
if (!$r) { http_response_code(404); echo 'Nenájdené'; exit; }
if ($r['user_id'] != ($_SESSION['user_id'] ?? 0)) { http_response_code(403); echo 'Prístup odmietnutý'; exit; }
$encPath = $r['storage_path'] ?? null;
$downloadName = $r['download_filename'] ?? ('book_'.$r['book_id'].'.pdf');
if (!$encPath || !file_exists($encPath)) { http_response_code(404); echo 'Súbor neexistuje'; exit; }
// Decrypt and stream
require_once __DIR__ . '/../../libs/FileVault.php';
$ok = FileVault::streamDecryptedFile($encPath, $downloadName);
if (!$ok) { http_response_code(500); echo 'Chyba pri dešifrovaní'; exit; }
exit;
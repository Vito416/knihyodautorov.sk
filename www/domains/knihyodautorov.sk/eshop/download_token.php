<?php
// Download via token (public link). Validates max uses, expiry, and increments used counter.
require __DIR__ . '/inc/bootstrap.php';
$token = $_GET['t'] ?? '';
if (!$token) { http_response_code(400); echo 'Missing token'; exit; }
$stmt = $db->prepare('SELECT d.*, ba.storage_path, ba.download_filename FROM order_item_downloads d LEFT JOIN book_assets ba ON ba.book_id=d.book_id AND ba.asset_type = ? WHERE d.download_token = ? LIMIT 1');
$stmt->execute(['pdf', $token]);
$row = $stmt->fetch();
if (!$row) { http_response_code(404); echo 'Token not found'; exit; }
if ($row['expires_at'] && strtotime($row['expires_at']) < time()) { http_response_code(410); echo 'Token expired'; exit; }
if ($row['max_uses'] > 0 && $row['used'] >= $row['max_uses']) { http_response_code(403); echo 'Maximum downloads reached'; exit; }
$path = $row['storage_path'] ?? null;
if (!$path || !file_exists($path)) { http_response_code(404); echo 'File missing'; exit; }
// increment atomically
$upd = $db->prepare('UPDATE order_item_downloads SET used = used + 1, last_used_at = NOW(), last_ip = ? WHERE id = ? AND (max_uses = 0 OR used < max_uses)');
$upd->execute([$_SERVER['REMOTE_ADDR'] ?? '', $row['id']]);
if ($upd->rowCount() === 0) { http_response_code(409); echo 'Could not claim download token'; exit; }
// stream decrypted file
require_once __DIR__ . '/../../libs/FileVault.php';
$downloadName = $row['download_filename'] ?? ('book_'.$row['book_id'].'.pdf');
$ok = FileVault::streamDecryptedFile($path, $downloadName);
if (!$ok) { http_response_code(500); echo 'Decryption failed'; exit; }
exit;
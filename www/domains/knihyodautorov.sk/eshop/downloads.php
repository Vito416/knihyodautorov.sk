// File: www/eshop/download_token.php
<?php
declare(strict_types=1);
// One-time download token endpoint
// GET parameter: t (token)


require_once __DIR__ . '/inc/bootstrap.php'; // expects $db (PDO), session, Auth, FileVault/Crypto


function not_found(): void { http_response_code(404); echo 'Nie je k dispozícii.'; exit; }
function forbidden(): void { http_response_code(403); echo 'Prístup zamietnutý.'; exit; }


$token = isset($_GET['t']) ? trim((string)$_GET['t']) : '';
if ($token === '') not_found();


// Basic validation of token format
if (!preg_match('/^[0-9a-zA-Z\-\_]{16,255}$/', $token)) {
not_found();
}


try {
// load token row and asset
$stmt = $db->prepare('SELECT oid.id, oid.order_id, oid.asset_id, oid.max_uses, oid.used, oid.expires_at, a.storage_path, a.download_filename, a.mime_type FROM order_item_downloads oid JOIN book_assets a ON a.id = oid.asset_id WHERE oid.download_token = :tok LIMIT 1');
$stmt->execute([':tok' => $token]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) not_found();


// expiry
if (!empty($row['expires_at']) && strtotime($row['expires_at']) < time()) {
forbidden();
}
// uses
if ($row['max_uses'] !== null && (int)$row['used'] >= (int)$row['max_uses']) {
forbidden();
}


$storagePath = $row['storage_path'];
if (!file_exists($storagePath)) not_found();


// Increase used counter atomically
try {
$db->beginTransaction();
$up = $db->prepare('UPDATE order_item_downloads SET used = used + 1, last_used_at = NOW(), last_ip = :ip WHERE id = :id');
$up->execute([':ip' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', ':id' => $row['id']]);
$db->commit();
} catch (PDOException $e) {
if ($db->inTransaction()) $db->rollBack();
// If we cannot update usage, do not expose file
forbidden();
}


$downloadName = $row['download_filename'] ?? basename((string)$storagePath);
$mime = $row['mime_type'] ?? 'application/octet-stream';


// Stream decrypted content using FileVault preferred
if (class_exists('FileVault') && method_exists('FileVault', 'decryptAndStream')) {
FileVault::decryptAndStream($storagePath, $downloadName, $mime);
exit;
}


// Fallback to Crypto helper (expects decryptFileToString)
if (class_exists('Crypto') && method_exists('Crypto', 'decryptFileToString')) {
$enc = file_get_contents($storagePath);
$decrypted = Crypto::decryptFileToString($enc);
if ($decrypted === false) not_found();
header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . basename($downloadName) . '"');
header('Content-Length: ' . strlen($decrypted));
echo $decrypted;
exit;
}


// Last resort: if files are plaintext (not recommended)
header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . basename($downloadName) . '"');
header('Content-Length: ' . filesize($storagePath));
readfile($storagePath);
exit;


} catch (PDOException $e) {
if ($db->inTransaction()) $db->rollBack();
http_response_code(500);
echo 'Server error.';
exit;
}
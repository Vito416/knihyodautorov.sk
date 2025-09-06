<?php
header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . basename($downloadName) . '"');
header('Content-Length: ' . strlen($decrypted));
echo $decrypted;
exit;
} else {
// As a last resort, if files are stored unencrypted (not recommended), stream directly
header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . basename($downloadName) . '"');
header('Content-Length: ' . filesize($storagePath));
readfile($storagePath);
exit;
}


} else {
// logged-in user flow via order_item_id
if (empty($_SESSION['user_id'])) {
send_forbidden();
}
$stmt = $db->prepare('SELECT oi.id AS oi_id, oi.order_id, oi.book_id, a.storage_path, a.download_filename, a.mime_type, o.user_id FROM order_items oi JOIN orders o ON o.id = oi.order_id JOIN book_assets a ON a.book_id = oi.book_id WHERE oi.id = :oi LIMIT 1');
$stmt->execute([':oi' => $orderItemId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) send_not_found();


$ownerId = $row['user_id'];
if ((int)$ownerId !== (int)$_SESSION['user_id']) {
// allow admin
$isAdmin = false;
if (class_exists('Auth') && method_exists('Auth', 'user')) {
$u = Auth::user();
$isAdmin = isset($u['is_admin']) && (int)$u['is_admin'] === 1;
}
if (!$isAdmin) send_forbidden();
}


$storagePath = $row['storage_path'];
$downloadName = $row['download_filename'] ?? basename((string)$storagePath);
$mime = $row['mime_type'] ?? 'application/octet-stream';


if (!file_exists($storagePath)) send_not_found();


// stream via FileVault or Crypto
if (class_exists('FileVault') && method_exists('FileVault', 'decryptAndStream')) {
FileVault::decryptAndStream($storagePath, $downloadName, $mime);
exit;
} elseif (class_exists('Crypto') && method_exists('Crypto', 'decryptFileToString')) {
$enc = file_get_contents($storagePath);
$decrypted = Crypto::decryptFileToString($enc);
if ($decrypted === false) send_not_found();
header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . basename($downloadName) . '"');
header('Content-Length: ' . strlen($decrypted));
echo $decrypted;
exit;
} else {
header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . basename($downloadName) . '"');
header('Content-Length: ' . filesize($storagePath));
readfile($storagePath);
exit;
}
}
} catch (PDOException $e) {
if ($db->inTransaction()) $db->rollBack();
http_response_code(500);
echo 'Server error.';
exit;
}
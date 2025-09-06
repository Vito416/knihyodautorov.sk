// File: www/admin/modules/catalog/book_delete.php
<?php
declare(strict_types=1);
// Admin book deletion endpoint
// Path: www/admin/modules/catalog/book_delete.php


require_once __DIR__ . '/../../inc/bootstrap.php';


if (!class_exists('Auth') || !Auth::isLoggedIn() || !Auth::user()) {
header('Location: /admin/login.php');
exit;
}
$u = Auth::user();
if (!isset($u['is_admin']) || (int)$u['is_admin'] !== 1) {
http_response_code(403);
echo '<h1>Prístup zamietnutý</h1>';
exit;
}


// Expect POST with id and csrf_token
$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$postedCsrf = $_POST['csrf_token'] ?? '';


if ($id <= 0) {
http_response_code(400);
echo 'Neplatné ID.';
exit;
}


$csrfValid = false;
if (class_exists('CSRF') && method_exists('CSRF', 'validate')) {
$csrfValid = CSRF::validate($postedCsrf);
} else {
$sessKey = 'book_delete_csrf_' . $id;
if (!empty($_SESSION[$sessKey]) && hash_equals($_SESSION[$sessKey], (string)$postedCsrf)) {
$csrfValid = true;
unset($_SESSION[$sessKey]);
}
}
if (!$csrfValid) {
http_response_code(403);
echo 'Neplatný CSRF token.';
exit;
}


try {
// Verify book exists
$stmt = $db->prepare('SELECT id, title FROM books WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $id]);
$book = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$book) {
http_response_code(404);
echo 'Kniha nenájdená.';
exit;
}


// Begin transaction to delete related assets and book record
$db->beginTransaction();


// Fetch assets
$ast = $db->prepare('SELECT id, storage_path FROM book_assets WHERE book_id = :bid');
$ast->execute([':bid' => $id]);
$assets = $ast->fetchAll(PDO::FETCH_ASSOC);

// Delete asset records and physical files
$delAsset = $db->prepare('DELETE FROM book_assets WHERE id = :aid');
foreach ($assets as $a) {
$path = $a['storage_path'];
try {
if (class_exists('FileVault') && method_exists('FileVault', 'deleteFile')) {
// FileVault should handle safe deletion of encrypted files
FileVault::deleteFile($path);
} else {
// ensure path is inside allowed storage directory to avoid path traversal deletions
if (file_exists($path) && strpos(realpath($path), realpath(__DIR__ . '/../../storage')) === 0) {
@unlink($path);
}
}
} catch (Throwable $e) {
// swallow file deletion errors but log in production
}
$delAsset->execute([':aid' => $a['id']]);
}

// Delete other dependent records if exist (order_items? invoice_items?) - best effort
$delOrderItems = $db->prepare('DELETE FROM order_items WHERE book_id = :bid');
$delOrderItems->execute([':bid' => $id]);


// Finally delete the book
$delBook = $db->prepare('DELETE FROM books WHERE id = :id');
$delBook->execute([':id' => $id]);


$db->commit();


// Redirect back to books list
header('Location: /admin/modules/catalog/books.php?deleted=' . $id);
exit;
} catch (PDOException $e) {
if ($db->inTransaction()) $db->rollBack();
http_response_code(500);
echo 'Chyba pri mazaní.';
exit;
}
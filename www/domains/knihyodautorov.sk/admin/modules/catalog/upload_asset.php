// File: www/admin/modules/catalog/upload_asset.php
<?php
declare(strict_types=1);
// Secure upload handler for assets (covers admin uploads of covers and book PDFs)
// Path: www/admin/modules/catalog/upload_asset.php


require_once __DIR__ . '/../../inc/bootstrap.php'; // admin bootstrap should provide $db (PDO), Auth, CSRF, FileVault


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


// Allowed types and max sizes
$allowedMime = [
'image/jpeg' => ['ext' => ['jpg','jpeg'], 'max' => 5 * 1024 * 1024], // 5 MB
'image/png' => ['ext' => ['png'], 'max' => 5 * 1024 * 1024],
'application/pdf' => ['ext' => ['pdf'], 'max' => 50 * 1024 * 1024], // 50 MB for PDFs
];


$errors = [];
$success = false;


// CSRF
$csrfToken = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';
$csrfValid = false;
if (class_exists('CSRF') && method_exists('CSRF', 'validate')) {
$csrfValid = CSRF::validate($csrfToken);
} else {
if (!empty($_SESSION['upload_asset_csrf']) && hash_equals($_SESSION['upload_asset_csrf'], (string)$csrfToken)) {
$csrfValid = true;
unset($_SESSION['upload_asset_csrf']);
}
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
if (!$csrfValid) {
$errors[] = 'Neplatný CSRF token.';
}


// expected fields: book_id (optional), asset_type (cover|book), file
$bookId = isset($_POST['book_id']) && $_POST['book_id'] !== '' ? (int)$_POST['book_id'] : null;
$assetType = isset($_POST['asset_type']) ? trim((string)$_POST['asset_type']) : 'book';
if (!in_array($assetType, ['cover','book'], true)) $errors[] = 'Neznámy typ assetu.';


if (!isset($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
$errors[] = 'Súbor nebol nahratý.';
} else {
$file = $_FILES['file'];
// basic server-side limits
if ($file['error'] !== UPLOAD_ERR_OK) $errors[] = 'Chyba pri nahrávaní súboru.';


// Detect real mime type
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($file['tmp_name']);
if ($mime === false) $mime = $file['type'] ?? 'application/octet-stream';


// check allowed
if (!isset($allowedMime[$mime])) {
$errors[] = 'Nepovolený typ súboru: ' . htmlspecialchars((string)$mime, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
} else {
$meta = $allowedMime[$mime];
$maxSize = $meta['max'];
if ($file['size'] > $maxSize) $errors[] = 'Súbor je príliš veľký (max. ' . ($maxSize/1024/1024) . ' MB).';


// ensure extension matches
$origName = $file['name'];
$ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
if (!in_array($ext, $meta['ext'], true)) {
$errors[] = 'Neplatná prípona súboru.';
}
}
}


if (empty($errors)) {
try {
// Decide storage path: store outside webroot, e.g. /var/www/storage/assets/YYYY/MM/
$storageBase = __DIR__ . '/../../storage/assets';
$subdir = date('Y') . '/' . date('m');
$destDir = $storageBase . '/' . $subdir;
if (!is_dir($destDir) && !mkdir($destDir, 0750, true) && !is_dir($destDir)) {
throw new RuntimeException('Nie je možné vytvoriť priečinok pre uloženie.');
}


// generate safe filename
$safeName = bin2hex(random_bytes(12)) . '.' . $ext;
$destPathPlain = $destDir . '/' . $safeName; // temporary path before encryption


// Use FileVault::uploadAndEncrypt to store encrypted file
if (class_exists('FileVault') && method_exists('FileVault', 'uploadAndEncrypt')) {
// move uploaded file to temp then encrypt using FileVault
$tmp = $file['tmp_name'];
$encryptedFilename = $safeName . '.enc';
$encryptedPath = $destDir . '/' . $encryptedFilename;
// We call uploadAndEncrypt which reads source tmp and writes encryptedPath
$writtenPath = FileVault::uploadAndEncrypt($tmp, $encryptedPath);


$storagePath = $writtenPath; // store this path in DB
$isEncrypted = 1;
} else {
// fallback: move file and leave plaintext (not recommended)
if (!move_uploaded_file($file['tmp_name'], $destPathPlain)) throw new RuntimeException('Nepodarilo sa presunúť súbor.');
$storagePath = $destPathPlain;
$isEncrypted = 0;
}


// compute content hash
$contentHash = hash_file('sha256', $storagePath);


// Save record to book_assets (book_id may be null for cover not linked)
$stmt = $db->prepare('INSERT INTO book_assets (book_id, asset_type, filename, mime_type, size_bytes, storage_path, content_hash, download_filename, is_encrypted, created_at) VALUES (:book_id, :asset_type, :filename, :mime, :size, :storage, :hash, :download_name, :enc, NOW())');
$stmt->execute([
':book_id' => $bookId,
':asset_type' => $assetType,
':filename' => $origName,
':mime' => $mime,
':size' => $file['size'],
':storage' => $storagePath,
':hash' => $contentHash,
':download_name' => $origName,
':enc' => $isEncrypted,
]);


$success = true;
header('Location: /admin/modules/catalog/assets.php?uploaded=1');
exit;


} catch (Throwable $e) {
$errors[] = 'Chyba pri uložení súboru.';
// optionally log $e
}
}
}


// ensure CSRF token for form
if (empty($_SESSION['upload_asset_csrf'])) $_SESSION['upload_asset_csrf'] = bin2hex(random_bytes(32));
$csrfForForm = $_SESSION['upload_asset_csrf'];


?><!doctype html>
<html lang="sk">
<head>
<meta charset="utf-8">
<title>Nahratie assetu</title>
<meta name="robots" content="noindex">
<style>body{font-family:Arial,Helvetica,sans-serif;max-width:900px;margin:1rem auto;padding:1rem}</style>
</head>
<body>
<h1>Nahratie assetu</h1>
<?php foreach ($errors as $err): ?><div style="background:#fff1f0;padding:0.5rem;margin-bottom:0.5rem"><?php echo htmlspecialchars($err, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div><?php endforeach; ?>


<form method="post" enctype="multipart/form-data" action="">
<label>Typ assetu<br>
<select name="asset_type"><option value="book">Book (PDF)</option><option value="cover">Obal (JPG/PNG)</option></select>
</label><br><br>
<label>Priradiť ku knihe (id, voliteľné)<br><input name="book_id" type="number" min="1" placeholder="ID knihy"></label><br><br>
<label>Vybrať súbor<br><input name="file" type="file" required></label><br><br>
<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfForForm, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
<button type="submit">Nahrať</button>
</form>


</body>
</html>
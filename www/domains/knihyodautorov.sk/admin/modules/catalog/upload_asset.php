<?php
require __DIR__ . '/../../inc/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: books.php'); exit; }
if (!CSRF::validate($_POST['csrf_token'] ?? '')) { http_response_code(400); echo 'CSRF error'; exit; }
$book_id = (int)($_POST['book_id'] ?? 0);
$asset_type = $_POST['asset_type'] ?? 'pdf';
if (!$book_id || empty($_FILES['file'])) { header('Location: book_form.php?id='.$book_id); exit; }
$file = $_FILES['file'];
if ($file['error'] !== UPLOAD_ERR_OK) { $_SESSION['flash_error']='Upload error'; header('Location: book_form.php?id='.$book_id); exit; }
$uploadDir = $cfg['paths']['uploads'];
if (!is_dir($uploadDir)) mkdir($uploadDir,0750,true);
$origName = basename($file['name']);
$ext = pathinfo($origName, PATHINFO_EXTENSION);
$targetName = $book_id . '_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
$targetPath = $uploadDir . '/' . $targetName;
if (!move_uploaded_file($file['tmp_name'], $targetPath)) { $_SESSION['flash_error']='Move error'; header('Location: book_form.php?id='.$book_id); exit; }
// encrypt file at rest using Crypto::encrypt (reads whole file)
$contents = file_get_contents($targetPath);
$encrypted = Crypto::encrypt($contents);
file_put_contents($targetPath.'.enc', $encrypted);
unlink($targetPath);
$storagePath = $targetPath.'.enc';
$sha = hash_file('sha256', $storagePath);
$mime = mime_content_type($storagePath);
$size = filesize($storagePath);
$stmt = $db->prepare('INSERT INTO book_assets (book_id, asset_type, filename, mime_type, size_bytes, storage_path, content_hash, download_filename, is_encrypted, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
$stmt->execute([$book_id, $asset_type, $origName, $mime, $size, $storagePath, $sha, $origName, 1]);
header('Location: book_form.php?id='.$book_id);
exit;
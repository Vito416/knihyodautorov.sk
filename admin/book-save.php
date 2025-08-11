<?php
// /admin/book-save.php
require_once __DIR__ . '/bootstrap.php';
require_admin();
require_once __DIR__ . '/inc/csrf.php';
require_once __DIR__ . '/inc/upload.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: books.php'); exit; }
if (!csrf_check_token($_POST['csrf'] ?? '')) { die('Neplatný CSRF token'); }

$id = (int)($_POST['id'] ?? 0);
$nazov = trim((string)($_POST['nazov'] ?? ''));
$author_id = $_POST['author_id'] ? (int)$_POST['author_id'] : null;
$category_id = $_POST['category_id'] ? (int)$_POST['category_id'] : null;
$cena = (float)str_replace(',', '.', (string)($_POST['cena'] ?? '0.00'));
$pdf = trim((string)($_POST['pdf_file'] ?? ''));
$popis = trim((string)($_POST['popis'] ?? ''));

$booksImgDir = __DIR__ . '/../books-img';

$uploaded = null;
if (!empty($_FILES['cover']) && $_FILES['cover']['error'] === UPLOAD_ERR_OK) {
    $uploaded = adm_handle_image_upload('cover', $booksImgDir);
}

if ($id) {
    $sql = "UPDATE books SET nazov=?, cena=?, pdf_file=?, popis=?, author_id=?, category_id=?, updated_at = NOW()";
    $params = [$nazov, number_format($cena,2,'.',''), $pdf, $popis, $author_id, $category_id];
    if ($uploaded) { $sql .= ", obrazok = ?"; $params[] = $uploaded; }
    $sql .= " WHERE id = ?";
    $params[] = $id;
    $pdo->prepare($sql)->execute($params);
} else {
    $slug = preg_replace('/[^a-z0-9\-]/','',mb_strtolower(str_replace(' ','-',iconv('UTF-8','ASCII//TRANSLIT',$nazov))));
    $stmt = $pdo->prepare("INSERT INTO books (nazov, slug, popis, cena, pdf_file, obrazok, author_id, category_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$nazov, $slug, $popis, number_format($cena,2,'.',''), $pdf, $uploaded, $author_id, $category_id]);
}

header('Location: books.php'); exit;

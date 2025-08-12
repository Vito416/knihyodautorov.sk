<?php
// /admin/book-action.php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
if (!admin_is_logged()) { header('Location: login.php'); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: books-admin.php'); exit; }

$csrf = $_POST['csrf'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'],$csrf)) die('CSRF token invalid');

$id = (int)($_POST['id'] ?? 0);
$action = $_POST['action'] ?? 'save';

$nazov = trim((string)($_POST['nazov'] ?? ''));
$popis = trim((string)($_POST['popis'] ?? ''));
$cena = (float)($_POST['cena'] ?? 0.00);
$mena = trim((string)($_POST['mena'] ?? 'EUR'));
$obrazok = trim((string)($_POST['obrazok'] ?? ''));
$pdf = trim((string)($_POST['pdf_file'] ?? ''));
$author_id = !empty($_POST['author_id']) ? (int)$_POST['author_id'] : null;
$cat_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
$is_active = isset($_POST['is_active']) ? 1 : 0;

try {
  if ($action === 'delete' && $id) {
    $pdo->prepare("DELETE FROM books WHERE id = ?")->execute([$id]);
    $_SESSION['flash_success'] = 'Kniha odstránená.';
    header('Location: books-admin.php'); exit;
  }

  if ($id) {
    $stmt = $pdo->prepare("UPDATE books SET nazov=?, popis=?, cena=?, mena=?, pdf_file=?, obrazok=?, author_id=?, category_id=?, is_active=? WHERE id=?");
    $stmt->execute([$nazov,$popis,number_format($cena,2,'.',''),$mena,$pdf,$obrazok,$author_id,$cat_id,$is_active,$id]);
    $_SESSION['flash_success'] = 'Kniha aktualizovaná.';
  } else {
    $stmt = $pdo->prepare("INSERT INTO books (nazov, slug, popis, cena, mena, pdf_file, obrazok, author_id, category_id, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $slug = preg_replace('/[^a-z0-9\-]/','',strtolower(str_replace(' ','-',iconv('UTF-8','ASCII//TRANSLIT',$nazov))));
    $stmt->execute([$nazov,$slug,$popis,number_format($cena,2,'.',''),$mena,$pdf,$obrazok,$author_id,$cat_id,$is_active]);
    $_SESSION['flash_success'] = 'Kniha pridaná.';
  }
} catch (Throwable $e) {
  error_log("book-action.php ERROR: ".$e->getMessage());
  $_SESSION['flash_error'] = 'Chyba pri ukladaní.';
}

header('Location: books-admin.php');
exit;
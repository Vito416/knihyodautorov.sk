<?php
// /admin/category-save.php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
if (!admin_is_logged()) { header('Location: login.php'); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: categories.php'); exit; }
$csrf = $_POST['csrf'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) die('CSRF token invalid.');

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$nazov = trim((string)($_POST['nazov'] ?? ''));
$slug = trim((string)($_POST['slug'] ?? ''));

if ($nazov === '') { $_SESSION['flash_error']='Názov je povinný.'; header('Location: categories.php'); exit; }
if ($slug==='') {
    $slug = strtolower(trim(preg_replace('/[^a-z0-9\-]+/i','-',iconv('UTF-8','ASCII//TRANSLIT',$nazov))));
    $slug = trim(preg_replace('/-+/','-',$slug),'-');
}
try {
    if ($id>0) {
        $pdo->prepare("UPDATE categories SET nazov=?, slug=? WHERE id=?")->execute([$nazov, $slug, $id]);
    } else {
        $pdo->prepare("INSERT INTO categories (nazov, slug) VALUES (?, ?)")->execute([$nazov, $slug]);
    }
    $_SESSION['flash_success']='Kategória uložená.';
    header('Location: categories.php'); exit;
} catch (Throwable $e) {
    error_log("category-save.php ERROR: ".$e->getMessage());
    $_SESSION['flash_error']='Chyba pri ukladaní.';
    header('Location: categories.php'); exit;
}
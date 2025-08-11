<?php
// /admin/category-delete.php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
if (!admin_is_logged()) { header('Location: login.php'); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: categories.php'); exit; }
$csrf = $_POST['csrf'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) die('CSRF token invalid.');

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($id<=0) { header('Location: categories.php'); exit; }

try {
    $pdo->prepare("UPDATE books SET category_id = NULL WHERE category_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM categories WHERE id = ?")->execute([$id]);
    $_SESSION['flash_success']='Kategória odstránená.';
} catch (Throwable $e) {
    error_log("category-delete.php ERROR: ".$e->getMessage());
    $_SESSION['flash_error']='Chyba pri odstraňovaní kategórie.';
}
header('Location: categories.php'); exit;
<?php
// /admin/book-delete.php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
if (!admin_is_logged()) { header('Location: login.php'); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: books.php'); exit; }
$csrf = $_POST['csrf'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) die('CSRF token invalid.');

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($id<=0) { header('Location: books.php'); exit; }

try {
    $stmt = $pdo->prepare("SELECT obrazok, pdf_file FROM books WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($r) {
        if (!empty($r['obrazok'])) @unlink(__DIR__ . '/../books-img/' . $r['obrazok']);
        if (!empty($r['pdf_file'])) @unlink(__DIR__ . '/../books-pdf/' . $r['pdf_file']);
    }
    $pdo->prepare("DELETE FROM books WHERE id = ?")->execute([$id]);
    $_SESSION['flash_success'] = 'Kniha odstránená.';
} catch (Throwable $e) {
    error_log("book-delete.php ERROR: " . $e->getMessage());
    $_SESSION['flash_error'] = 'Chyba pri odstraňovaní knihy.';
}
header('Location: books.php');
exit;
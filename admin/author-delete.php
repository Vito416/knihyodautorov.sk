<?php
// /admin/author-delete.php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

if (!admin_is_logged()) { header('Location: login.php'); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: authors.php'); exit; }

// CSRF
$csrf = $_POST['csrf'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
    die('CSRF token invalid.');
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($id <= 0) { header('Location: authors.php'); exit; }

try {
    // remove foto
    $stmt = $pdo->prepare("SELECT foto FROM authors WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($r && !empty($r['foto'])) {
        @unlink(__DIR__ . '/../assets/authors/' . $r['foto']);
    }

    // optionally: set books author_id = NULL before deleting
    $pdo->prepare("UPDATE books SET author_id = NULL WHERE author_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM authors WHERE id = ?")->execute([$id]);

    $_SESSION['flash_success'] = 'Autor bol odstránený.';
} catch (Throwable $e) {
    error_log("author-delete.php ERROR: " . $e->getMessage());
    $_SESSION['flash_error'] = 'Chyba pri odstraňovaní autora.';
}

header('Location: authors.php');
exit;
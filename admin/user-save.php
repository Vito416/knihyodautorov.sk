<?php
// /admin/user-save.php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/inc/csrf.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: users.php'); exit;
}
if (!csrf_check_token($_POST['csrf'] ?? '')) {
    die('Neplatný CSRF token.');
}

$action = $_POST['action'] ?? 'save';
$id = (int)($_POST['id'] ?? 0);

if ($action === 'delete') {
    if ($id) {
        $pdo->prepare("DELETE FROM users WHERE id = ? LIMIT 1")->execute([$id]);
    }
    header('Location: users.php'); exit;
}

// save / update
$meno = trim((string)($_POST['meno'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$newsletter = isset($_POST['newsletter']) ? (int)$_POST['newsletter'] : 0;

if ($id) {
    $stmt = $pdo->prepare("UPDATE users SET meno = ?, email = ?, newsletter = ? WHERE id = ?");
    $stmt->execute([$meno, $email, $newsletter, $id]);
} else {
    // password default random
    $tmp = bin2hex(random_bytes(5));
    $stmt = $pdo->prepare("INSERT INTO users (meno, email, heslo, newsletter, datum_registracie) VALUES (?, ?, ?, ?, NOW())");
    $stmt->execute([$meno, $email, password_hash($tmp, PASSWORD_DEFAULT), $newsletter]);
}
header('Location: users.php'); exit;

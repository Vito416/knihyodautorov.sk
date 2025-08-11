<?php
// /admin/order-action.php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
if (!admin_is_logged()) { header('Location: login.php'); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: orders.php'); exit; }

$csrf = $_POST['csrf'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) { die('CSRF token invalid'); }

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$action = $_POST['action'] ?? 'update';
$status = $_POST['status'] ?? '';

try {
    if ($action === 'delete') {
        // odstránenie: vymazať order_items potom order
        $pdo->prepare("DELETE FROM order_items WHERE order_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM orders WHERE id = ?")->execute([$id]);
        $_SESSION['flash_success'] = 'Objednávka odstránená.';
    } else {
        // update status
        $allowed = ['pending','paid','fulfilled','cancelled','refunded'];
        if (!in_array($status, $allowed)) $status = 'pending';
        $pdo->prepare("UPDATE orders SET status = ? WHERE id = ?")->execute([$status, $id]);
        $_SESSION['flash_success'] = 'Status objednávky aktualizovaný.';
    }
} catch (Throwable $e) {
    error_log("order-action.php ERROR: " . $e->getMessage());
    $_SESSION['flash_error'] = 'Chyba pri spracovaní.';
}
header('Location: orders.php');
exit;
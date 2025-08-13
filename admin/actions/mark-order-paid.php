<?php
// /admin/actions/mark-order-paid.php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
require __DIR__ . '/../bootstrap.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /admin/orders.php'); exit; }
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf'] ?? '')) {
    $_SESSION['flash_error'] = 'Neplatný CSRF token.';
    header('Location: /admin/orders.php'); exit;
}

$orderId = (int)($_POST['order_id'] ?? 0);
if ($orderId <= 0) {
    $_SESSION['flash_error'] = 'Neplatné ID objednávky.';
    header('Location: /admin/orders.php'); exit;
}

try {
    $pdo->prepare("UPDATE orders SET status = 'paid' WHERE id = ?")->execute([$orderId]);
    // audit
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS audit_log (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT DEFAULT NULL, action VARCHAR(255), meta TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $adminUser = admin_user($pdo);
        $uid = $adminUser['id'] ?? null;
        $meta = json_encode(['order_id'=>$orderId], JSON_UNESCAPED_UNICODE);
        $pdo->prepare("INSERT INTO audit_log (user_id, action, meta) VALUES (?, ?, ?)")->execute([$uid, 'mark_order_paid', $meta]);
    } catch (Throwable $_e) { }

    $_SESSION['flash_message'] = 'Objednávka označená ako zaplatená.';
} catch (Throwable $e) {
    $_SESSION['flash_error'] = 'Chyba: ' . $e->getMessage();
}

header('Location: /admin/orders.php');
exit;
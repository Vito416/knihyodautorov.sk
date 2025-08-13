<?php
// /eshop/newsletter-subscribe.php
require __DIR__ . '/_init.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
$csrf = $_POST['csrf'] ?? '';
if (!eshop_verify_csrf($csrf)) { http_response_code(403); exit; }
$email = trim((string)($_POST['email'] ?? ''));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { http_response_code(400); echo "Invalid email"; exit; }

// if logged in, set users.newsletter
if (!empty($_SESSION['user_id'])) {
    $pdo->prepare("UPDATE users SET newsletter = 1 WHERE id = ?")->execute([(int)$_SESSION['user_id']]);
    echo "OK";
    exit;
}

// else insert to subscribers table (create table if needed)
try {
    $pdo->prepare("CREATE TABLE IF NOT EXISTS newsletter_subscribers (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, email VARCHAR(255) UNIQUE, created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4")->execute();
    $stmt = $pdo->prepare("INSERT IGNORE INTO newsletter_subscribers (email) VALUES (?)");
    $stmt->execute([$email]);
    echo "OK";
} catch (Throwable $e) {
    error_log("newsletter error: ".$e->getMessage());
    http_response_code(500);
    echo "Error";
}
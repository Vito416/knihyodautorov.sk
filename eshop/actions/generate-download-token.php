<?php
// /eshop/actions/generate-download-token.php
require __DIR__ . '/../_init.php';
// expect admin auth in production; here simple POST CSRF
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
$csrf = $_POST['csrf'] ?? '';
if (!eshop_verify_csrf($csrf)) { http_response_code(403); exit; }

$book_id = (int)($_POST['book_id'] ?? 0);
$user_id = (int)($_POST['user_id'] ?? 0);
if (!$book_id || !$user_id) { http_response_code(400); exit; }

$token = bin2hex(random_bytes(20));
$ttlDays = (int)(eshop_settings($pdo, 'eshop_download_token_ttl') ?? 7);
$expires = (new DateTime())->modify("+{$ttlDays} days")->format('Y-m-d H:i:s');
$pdo->prepare("INSERT INTO download_tokens (user_id, book_id, token, expires_at, used, created_at) VALUES (?, ?, ?, ?, 0, NOW())")->execute([$user_id, $book_id, $token, $expires]);

header('Content-Type: application/json');
echo json_encode(['ok'=>true,'token'=>$token,'expires'=>$expires]);
exit;
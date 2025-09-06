<?php
session_start();
$cfg = require __DIR__ . '/../../secure/config.php';
require __DIR__ . '/../../libs/autoload.php';
Database::init($cfg['db']);
Crypto::init_from_base64($cfg['crypto_key']);
$db = Database::get();
function e($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
// simple admin check
if (empty($_SESSION['user_id'])) { header('Location: /eshop/login.php'); exit; }
// verify role admin (simple query)
$stmt = $db->prepare('SELECT r.nazov FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id = ? LIMIT 1');
$stmt->execute([$_SESSION['user_id']]); $role = $stmt->fetchColumn();
if ($role !== 'admin') { http_response_code(403); echo 'Access denied'; exit; }
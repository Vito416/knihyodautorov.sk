<?php
// /admin/bootstrap.php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
$cfg = __DIR__ . '/../db/config/config.php';
if (!file_exists($cfg)) die('Chýba DB konfig: ' . $cfg);
$maybe = require $cfg;
if ($maybe instanceof PDO) $pdo = $maybe; else die('Konfig musí vracať PDO.');

function admin_is_logged() { return !empty($_SESSION['admin_user_id']); }
function require_admin() { if (!admin_is_logged()) { header('Location: login.php'); exit; } }
function admin_user(PDO $pdo) {
    if (!admin_is_logged()) return null;
    $s = $pdo->prepare("SELECT id, username, email, role FROM admin_users WHERE id = ? LIMIT 1");
    $s->execute([(int)$_SESSION['admin_user_id']]);
    return $s->fetch(PDO::FETCH_ASSOC);
}
function esc($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
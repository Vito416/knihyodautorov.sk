<?php
// /eshop/bootstrap.php
// Spoločné nastavenia pre súbory v /eshop
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    // bezpečné session cookie
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => $_SERVER['HTTP_HOST'] ?? '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

// načítanie PDO z root db/config/config.php
$cfgPath = __DIR__ . '/../db/config/config.php';
if (!file_exists($cfgPath)) {
    die('Chýba konfigurácia DB: ' . htmlspecialchars($cfgPath));
}
$maybe = require $cfgPath;
if ($maybe instanceof PDO) {
    $pdo = $maybe;
} elseif (isset($pdo) && $pdo instanceof PDO) {
    // ok
} else {
    die('Konfig súbor musí vracať PDO alebo nastaviť $pdo.');
}

// Helpery
function esc($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function set_flash(string $k, string $v) {
    $_SESSION['flash'][$k] = $v;
}
function get_flash(string $k) {
    if (!isset($_SESSION['flash'][$k])) return null;
    $v = $_SESSION['flash'][$k];
    unset($_SESSION['flash'][$k]);
    return $v;
}

// CSRF
if (!isset($_SESSION['eshop_csrf'])) $_SESSION['eshop_csrf'] = bin2hex(random_bytes(24));
function csrf_token(): string { return $_SESSION['eshop_csrf']; }
function verify_csrf($t): bool { return is_string($t) && hash_equals((string)$_SESSION['eshop_csrf'], (string)$t); }

// Auth
function is_logged_in(): bool { return !empty($_SESSION['user_id']); }
function require_login() {
    if (!is_logged_in()) {
        $ret = $_SERVER['REQUEST_URI'] ?? '/eshop/';
        header('Location: login.php?return=' . urlencode($ret));
        exit;
    }
}
function current_user(PDO $pdo) {
    if (!is_logged_in()) return null;
    static $cache = null;
    if ($cache !== null) return $cache;
    $stmt = $pdo->prepare("SELECT id, meno, email, telefon, adresa, newsletter FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([(int)$_SESSION['user_id']]);
    $cache = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    return $cache;
}

// Small helper for redirects
function redirect_to(string $url) {
    header('Location: ' . $url);
    exit;
}

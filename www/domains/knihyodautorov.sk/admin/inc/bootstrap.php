// File: www/admin/inc/bootstrap.php
<?php
declare(strict_types=1);

// secure/config.php je includnuto už dříve -> $config existuje
require_once __DIR__ . '/../../../../../secure/config.php'; // adjust path
require_once __DIR__ . '/../../../../../libs/KeyManager.php';
require_once __DIR__ . '/../../../../../libs/Crypto.php';

// Initialize Crypto with key from KeyManager (prefer $_ENV, otherwise key file)
try {
    // v dev můžeš povolit generování, v prod false
    $b64 = KeyManager::getBase64Key('APP_CRYPTO_KEY', $config['paths']['keys'] . '/crypto_key.bin', ($config['app_env'] ?? '') === 'dev');
    Crypto::init_from_base64($b64);
} catch (Throwable $e) {
    // fatal - crypto must be initialized for app to run
    error_log('[bootstrap] Crypto initialization failed: ' . $e->getMessage());
    throw $e;
}
// Admin bootstrap: initialize session, DB and enforce admin-only access
// Path: www/admin/inc/bootstrap.php


// Basic assumptions: there's a Database::init() that returns PDO in $db or $GLOBALS['db']
// and Auth class with isLoggedIn(), user() and optionally restoreSessionFromToken()


// Start session with secure cookie params
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_set_cookie_params([
'lifetime' => 0,
'path' => '/',
'domain' => $_SERVER['HTTP_HOST'] ?? '',
'secure' => $secure,
'httponly' => true,
'samesite' => 'Lax',
]);
session_start();


// Initialize DB
if (class_exists('Database') && method_exists('Database', 'init')) {
$db = Database::init();
} elseif (isset($GLOBALS['db']) && $GLOBALS['db'] instanceof PDO) {
$db = $GLOBALS['db'];
} else {
// try to require config
if (file_exists(__DIR__ . '/../../secure/config.php')) require_once __DIR__ . '/../../secure/config.php';
// expect $db to be set by config
if (isset($GLOBALS['db']) && $GLOBALS['db'] instanceof PDO) $db = $GLOBALS['db'];
}


// Ensure $db exists
if (!isset($db) || !($db instanceof PDO)) {
// fatal: cannot continue
http_response_code(500);
echo 'Server configuration error: database not initialized.';
exit;
}


// Restore persistent session from cookie if present
if (!isset($_SESSION['user_id']) && !empty($_COOKIE['session_token'])) {
$sid = $_COOKIE['session_token'];
try {
$stmt = $db->prepare('SELECT id, user_id, revoked, expires_at FROM sessions WHERE id = :sid LIMIT 1');
$stmt->execute([':sid' => $sid]);
$srow = $stmt->fetch(PDO::FETCH_ASSOC);
if ($srow && (int)$srow['revoked'] === 0 && strtotime($srow['expires_at']) > time()) {
// restore session
$_SESSION['user_id'] = $srow['user_id'];
// update last_seen_at
$u = $db->prepare('UPDATE sessions SET last_seen_at = NOW() WHERE id = :sid');
$u->execute([':sid' => $sid]);
} else {
// invalidate cookie
setcookie('session_token', '', time() - 3600, '/', $_SERVER['HTTP_HOST'] ?? '', $secure, true);
}
} catch (PDOException $e) {
// ignore restore errors
}
}


// Initialize Auth helper if exists
if (class_exists('Auth') && method_exists('Auth', 'init')) {
Auth::init($db);
}


// Ensure only admin users access admin area by default (pages can override for public admin pages)
if (class_exists('Auth') && Auth::isLoggedIn()) {
$currentUser = Auth::user();
if (empty($currentUser) || !isset($currentUser['is_admin']) || (int)$currentUser['is_admin'] !== 1) {
// redirect to admin login
header('Location: /admin/login.php');
exit;
}
} else {
// not logged in
header('Location: /admin/login.php');
exit;
}


// Enforce password change if required
if (class_exists('EnforcePasswordChange') && method_exists('EnforcePasswordChange', 'check')) {
EnforcePasswordChange::check($db);
}


// Set secure headers
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer-when-downgrade');


// The admin bootstrap returns $db and session is active
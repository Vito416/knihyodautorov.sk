// File: www/eshop/inc/bootstrap.php
<?php
declare(strict_types=1);
// E-shop public bootstrap
// Initializes session, database, Auth, optional CSRF, FileVault key availability, and security headers

// Start session with secure cookie params similar to admin
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => $_SERVER['HTTP_HOST'] ?? '',
    'secure' => $secure,
    'httponly' => true,
    'samesite' => 'Lax',
]);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

// Load configuration
if (file_exists(__DIR__ . '/../../secure/config.php')) {
    require_once __DIR__ . '/../../secure/config.php'; // expects $GLOBALS['config'] or similar
}

// Initialize DB
if (class_exists('Database') && method_exists('Database', 'init')) {
    $db = Database::init();
} elseif (isset($GLOBALS['db']) && $GLOBALS['db'] instanceof PDO) {
    $db = $GLOBALS['db'];
} else {
    // try to create PDO if config present
    if (!empty($GLOBALS['config']['db'])) {
        $c = $GLOBALS['config']['db'];
        $dsn = $c['dsn'] ?? null;
        $user = $c['user'] ?? null;
        $pass = $c['pass'] ?? null;
        $opts = $c['options'] ?? [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
        if ($dsn) {
            try {
                $db = new PDO($dsn, $user, $pass, $opts);
            } catch (PDOException $e) {
                http_response_code(500);
                echo 'Server database error.';
                exit;
            }
        }
    }
}

if (!isset($db) || !($db instanceof PDO)) {
    http_response_code(500);
    echo 'Server configuration error: database not available.';
    exit;
}

// Restore persistent session from cookie if present (non-admin flow)
if (!isset($_SESSION['user_id']) && !empty($_COOKIE['session_token'])) {
    $sid = $_COOKIE['session_token'];
    try {
        $stmt = $db->prepare('SELECT id, user_id, revoked, expires_at FROM sessions WHERE id = :sid LIMIT 1');
        $stmt->execute([':sid' => $sid]);
        $srow = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($srow && (int)$srow['revoked'] === 0 && strtotime($srow['expires_at']) > time()) {
            $_SESSION['user_id'] = $srow['user_id'];
            $u = $db->prepare('UPDATE sessions SET last_seen_at = NOW() WHERE id = :sid');
            $u->execute([':sid' => $sid]);
        } else {
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

// Initialize CSRF if available
if (class_exists('CSRF') && method_exists('CSRF', 'init')) {
    CSRF::init();
}

// Ensure FileVault key is present (helpful early warning)
try {
    if (defined('FILEVAULT_KEY') && FILEVAULT_KEY !== '') {
        // ok
    } elseif (!empty($GLOBALS['config']['filevault_key'])) {
        // ok
    } elseif (getenv('FILEVAULT_KEY')) {
        // ok
    } else {
        // not fatal, but warn in logs
        error_log('Warning: FileVault key not configured. Define FILEVAULT_KEY or $GLOBALS["config"]["filevault_key"].');
    }
} catch (Throwable $e) {
    // ignore
}

// Enforce password change if needed
if (class_exists('EnforcePasswordChange') && method_exists('EnforcePasswordChange', 'check')) {
    EnforcePasswordChange::check($db);
}

// Security headers
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer-when-downgrade');
// Consider adding Content-Security-Policy depending on frontend

// Provide $db globally
$GLOBALS['db'] = $db;
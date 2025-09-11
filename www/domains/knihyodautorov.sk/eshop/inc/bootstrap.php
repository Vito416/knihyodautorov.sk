<?php
declare(strict_types=1);

// File: www/eshop/inc/bootstrap.php
// Secure public/eshop bootstrap — modernized to match admin area patterns.
// - expects secure/config.php to set $config array
// - eager DB init via Database::init($config['db'])
// - Crypto init via KeyManager + Crypto::init_from_base64
// - FileVault configured explicitly (no env/globals inside library)
// - avoids getenv()/ $GLOBALS usage inside libs

function clearSessionCookie(bool $secure, ?string $cookieDomain = null, string $sameSite = 'Lax'): void {
    if (PHP_VERSION_ID >= 70300) {
        $cookieOpts = [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => $sameSite,
        ];
        if (!empty($cookieDomain)) {
            $cookieOpts['domain'] = $cookieDomain;
        }
        setcookie('session_token', '', $cookieOpts);
    } else {
        $domain = $cookieDomain ?? '';
        setcookie('session_token', '', time() - 3600, '/', $domain, $secure, true);
    }
}

// Resolve project root (same approach as admin)
$PROJECT_ROOT = realpath(__DIR__ . '/../../../../..');
if ($PROJECT_ROOT === false) {
    http_response_code(500);
    echo 'Server configuration error: cannot resolve PROJECT_ROOT.';
    exit;
}

// Load configuration (expected to set $config array)
require_once $PROJECT_ROOT . '/secure/config.php';
if (!isset($config) || !is_array($config)) {
    error_log('[eshop bootstrap] Missing or invalid secure/config.php (expected $config array).');
    http_response_code(500);
    echo 'Server configuration error.';
    exit;
}

// Autoloader / libs (optional)
$autoloadPath = $PROJECT_ROOT . '/libs/autoload.php';
if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
} else {
    // fallback: include minimal libs you need (KeyManager, Crypto)
    $maybe = $PROJECT_ROOT . '/libs/KeyManager.php';
    if (file_exists($maybe)) require_once $maybe;
    $maybe2 = $PROJECT_ROOT . '/libs/Crypto.php';
    if (file_exists($maybe2)) require_once $maybe2;
}
if (!defined('KEYS_DIR')) {
    define('KEYS_DIR', $config['paths']['keys']);
}
// ---------------------------
// Crypto initialization (using KeyManager)
// ---------------------------
try {
    if (!class_exists('KeyManager')) {
        throw new RuntimeException('KeyManager class not found (autoload missing?)');
    }
    if (!isset($config['paths']['keys'])) {
        throw new RuntimeException('Missing keys path in config.');
    }

    $keyDir = $config['paths']['keys'];
    $cryptoKeyInfo = KeyManager::locateLatestKeyFile($keyDir, 'crypto_key');
    if ($cryptoKeyInfo === null) {
        throw new RuntimeException('No crypto_key file found in ' . $keyDir);
    }

    // Use KeyManager helper to get base64 key (keeps logic centralized)
    $b64 = KeyManager::getBase64Key('APP_CRYPTO_KEY', $keyDir, 'crypto_key', ($config['app_env'] ?? '') === 'dev');
    if (empty($b64)) {
        throw new RuntimeException('Failed to read base64 crypto key.');
    }

    if (!class_exists('Crypto')) {
        throw new RuntimeException('Crypto class not found (autoload missing?)');
    }
    Crypto::init_from_base64($b64);
} catch (Throwable $e) {
    error_log('[eshop bootstrap] Crypto initialization failed: ' . $e->getMessage());
    http_response_code(500);
    echo 'Fatal error: cryptography not available.';
    exit;
}

// ---------------------------
// Session cookie params and start session (public site uses Lax)
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
$cookieDomain = $_ENV['SESSION_DOMAIN'] ?? ($_SERVER['HTTP_HOST'] ?? null);

$sessionCookieParams = [
    'lifetime' => 0,
    'path' => '/',
    'secure' => $secure,
    'httponly' => true,
    'samesite' => 'Lax',
];
if (!empty($cookieDomain)) {
    $sessionCookieParams['domain'] = $cookieDomain;
}

if (PHP_VERSION_ID >= 70300) {
    session_set_cookie_params($sessionCookieParams);
} else {
    $domain = $sessionCookieParams['domain'] ?? '';
    session_set_cookie_params(0, '/', $domain, $secure, true);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// ---------------------------
// Ensure Database wrapper is initialized and return PDO instance
// ---------------------------
try {
    if (class_exists('Database') && method_exists('Database', 'init')) {
        // use config['db'] (bootstrap už nahrál $config)
        Database::init($config['db'] ?? []);
        $db = Database::getInstance()->getPdo();
    } elseif (isset($GLOBALS['db']) && $GLOBALS['db'] instanceof PDO) {
        // backward-compat (pokud někde starší kód nastavuje globals) - fallback only
        $db = $GLOBALS['db'];
    } else {
        // last-resort: try to create raw PDO if config exists
        $dsn = $config['db']['dsn'] ?? '';
        $user = $config['db']['user'] ?? '';
        $pass = $config['db']['pass'] ?? '';
        if ($dsn !== '' && $user !== '') {
            $options = ($config['db']['options'] ?? []) + [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 3,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            $db = new PDO($dsn, $user, $pass, $options);
        }
    }
} catch (Throwable $e) {
    error_log('[eshop bootstrap] DB init failed: ' . $e->getMessage());
    http_response_code(500);
    echo 'Server configuration error: database not initialized.';
    exit;
}

// sanity check
if (!($db instanceof PDO)) {
    error_log('[eshop bootstrap] DB not initialized after attempts');
    http_response_code(500);
    echo 'Server configuration error: database not initialized.';
    exit;
}

// ---------------------------
// Configure FileVault explicitly (no env/globals inside FileVault)
// ---------------------------
if (class_exists('FileVault')) {
    $fvKeysDir = $config['paths']['keys'] ?? ($PROJECT_ROOT . '/secure/keys');
    $fvStorage = $config['paths']['storage'] ?? ($PROJECT_ROOT . '/secure/storage');
    $fvAuditDir = $config['paths']['audit'] ?? null;

    $actorProvider = function() : ?string {
        if (session_status() === PHP_SESSION_NONE) return null;
        return isset($_SESSION['user_id']) ? (string) $_SESSION['user_id'] : null;
    };

    // Prefer configure() helper; fallback to explicit setters for backwards compatibility
    if (method_exists('FileVault', 'configure')) {
        FileVault::configure([
            'keys_dir' => $fvKeysDir,
            'storage_base' => $fvStorage,
            'audit_dir' => $fvAuditDir,
            'audit_pdo' => $db,
            'actor_provider' => $actorProvider,
        ]);
    } else {
        if (method_exists('FileVault', 'setKeysDir')) FileVault::setKeysDir($fvKeysDir);
        if (method_exists('FileVault', 'setStorageBase')) FileVault::setStorageBase($fvStorage);
        if (method_exists('FileVault', 'setAuditDir') && $fvAuditDir !== null) FileVault::setAuditDir($fvAuditDir);
        if (method_exists('FileVault', 'setAuditPdo')) FileVault::setAuditPdo($db);
        if (method_exists('FileVault', 'setActorProvider')) FileVault::setActorProvider($actorProvider);
    }

    // Optional quick check for key presence (non-fatal)
    try {
        if (class_exists('KeyManager')) {
            $fvKey = KeyManager::locateLatestKeyFile($fvKeysDir, 'filevault_key');
            if ($fvKey === null) {
                error_log('[eshop bootstrap] Warning: filevault_key not found in ' . $fvKeysDir);
            }
        }
    } catch (Throwable $_) {
        // swallow
    }
}

// ---------------------------
// Restore persistent session from cookie if present (non-admin flow)
// ---------------------------
if (empty($_SESSION['user_id']) && !empty($_COOKIE['session_token'])) {
    $sid = $_COOKIE['session_token'];
    // require 64 hex chars token
    if (ctype_xdigit((string)$sid) && strlen((string)$sid) === 64) {
        try {
            // lookup by token_hash (sha256) — keep raw token out of DB
            $tokenHash = hash('sha256', $sid);
            $stmt = $db->prepare('SELECT id, user_id, revoked, expires_at, token_hash FROM sessions WHERE token_hash = :token_hash LIMIT 1');
            $stmt->execute([':token_hash' => $tokenHash]);
            $srow = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($srow && (int)($srow['revoked'] ?? 0) === 0 && strtotime($srow['expires_at']) > time()) {
                $_SESSION['user_id'] = (int)$srow['user_id'];
                $u = $db->prepare('UPDATE sessions SET last_seen_at = UTC_TIMESTAMP() WHERE token_hash = :token_hash');
                $u->execute([':token_hash' => $tokenHash]);
            } else {
                // clear cookie using same domain/secure flags
                if (PHP_VERSION_ID >= 70300) {
                    $cookieOpts = [
                        'expires' => time() - 3600,
                        'path' => '/',
                        'secure' => $secure,
                        'httponly' => true,
                        'samesite' => 'Lax',
                    ];
                    if (!empty($cookieDomain)) $cookieOpts['domain'] = $cookieDomain;
                    setcookie('session_token', '', $cookieOpts);
                } else {
                    $domain = $cookieDomain ?? '';
                    setcookie('session_token', '', time() - 3600, '/', $domain, $secure, true);
                }
            }
        } catch (Throwable $_) {
            // ignore restore errors
        }
    } else {
        // clear invalid cookie
        if (PHP_VERSION_ID >= 70300) {
            $cookieOpts = [
                'expires' => time() - 3600,
                'path' => '/',
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ];
            if (!empty($cookieDomain)) $cookieOpts['domain'] = $cookieDomain;
            setcookie('session_token', '', $cookieOpts);
        } else {
            $domain = $cookieDomain ?? '';
            setcookie('session_token', '', time() - 3600, '/', $domain, $secure, true);
        }
    }
}

// ---------------------------
// Initialize Auth helper if exists
try {
    if (class_exists('Auth')) {
        if (method_exists('Auth', 'validateSession')) {
            Auth::validateSession($db);
        } 
    }
} catch (Throwable $e) {
    error_log('[eshop bootstrap] Auth init/validateSession failed: ' . $e->getMessage());
}

if (!empty($_SESSION['user_id']) && class_exists('AuditLogger') && method_exists('AuditLogger', 'log')) {
    try {
        AuditLogger::log($db, (string)$_SESSION['user_id'], 'user_session_restore', json_encode([
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            'ua' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    } catch (Throwable $e) {
        error_log('[eshop bootstrap] Audit log user_session_restore failed: ' . $e->getMessage());
    }
}

// Enforce password change if needed
if (class_exists('EnforcePasswordChange') && method_exists('EnforcePasswordChange', 'check')) {
    try {
        EnforcePasswordChange::check($db);
    } catch (Throwable $e) {
        error_log('[eshop bootstrap] EnforcePasswordChange failed: ' . $e->getMessage());
    }
}

// Security headers
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer-when-downgrade');

// return PDO so callers (login.php etc.) can do: $db = require __DIR__ . '/inc/bootstrap.php';
return $db;
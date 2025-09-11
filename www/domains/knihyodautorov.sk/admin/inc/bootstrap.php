<?php
// File: www/admin/inc/bootstrap.php
declare(strict_types=1);

// ------------------------------------------------------------
// Secure bootstrap for admin area
// ------------------------------------------------------------

$PROJECT_ROOT = realpath(__DIR__ . '/../../../../..');
if ($PROJECT_ROOT === false) {
    http_response_code(500);
    echo 'Server configuration error: cannot resolve PROJECT_ROOT.';
    exit;
}

// Load configuration (expected to set $config array) and autoloader
require_once $PROJECT_ROOT . '/secure/config.php';
if (!isset($config) || !is_array($config)) {
    error_log('[bootstrap] Missing or invalid secure/config.php (expected $config array).');
    http_response_code(500);
    echo 'Server configuration error.';
    exit;
}

$autoloadPath = $PROJECT_ROOT . '/libs/autoload.php';
if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
} else {
    error_log('[bootstrap] libs/autoload.php not found — ensure autoloader or include libs manually.');
}
if (!defined('KEYS_DIR')) {
    define('KEYS_DIR', $config['paths']['keys']);
}
// ----------------------------
// Shared login URL + redirect helper for admin area
// ----------------------------
$sharedLoginUrl = $config['routes']['login'] ?? '/eshop/login.php';

/**
 * Přesměrování na sdílený login s bezpečným return_to parametrem.
 * Vrací se pouze interní admin path (nikdy externí).
 *
 * @param string $fallback path used when current request path is not under /admin
 */
$adminRedirectToLogin = function(string $fallback = '/admin/') use ($sharedLoginUrl) : void {
    $req = $_SERVER['REQUEST_URI'] ?? $fallback;
    $path = parse_url($req, PHP_URL_PATH) ?: $fallback;
    $query = parse_url($req, PHP_URL_QUERY);

    // Normalize: pokud request path není pro admin, použij fallback
    if (strpos($path, '/admin') !== 0) {
        $path = $fallback;
        $query = null;
    }

    // build safe return_to (only path + query)
    $returnPath = $path . ($query ? ('?' . $query) : '');

    $sep = (strpos($sharedLoginUrl, '?') === false) ? '?' : '&';
    $location = $sharedLoginUrl . $sep . 'return_to=' . urlencode($returnPath);

    header('Location: ' . $location);
    exit;
};

// ------------------------------------------------------------
// Initialize Crypto (KeyManager/Crypto should be available via autoload)
// ------------------------------------------------------------
try {
    if (!class_exists('KeyManager')) {
        throw new RuntimeException('KeyManager class not found (autoload missing?)');
    }
    if (!isset($config['paths']['keys'])) {
        throw new RuntimeException('Missing keys path in config.');
    }

    $keyDir = $config['paths']['keys'];
    if (!class_exists('Crypto')) {
        throw new RuntimeException('Crypto class not found (autoload missing?)');
    }
    $b64 = KeyManager::getBase64Key('APP_CRYPTO_KEY', $keyDir, 'crypto_key');
    Crypto::init_from_base64($b64);

} catch (Throwable $e) {
    error_log('[bootstrap] Crypto initialization failed: ' . $e->getMessage());
    http_response_code(500);
    echo 'Fatal error: cryptography not available.';
    exit;
}

// ------------------------------------------------------------
// Secure session cookie params + start session
// ------------------------------------------------------------
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
$cookieDomain = parse_url('http://' . ($_ENV['SESSION_DOMAIN'] ?? $_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST);

// Funkce pro bezpečné odstranění session cookie
function clearSessionCookie(string $name = 'session_token', bool $secure = false, ?string $domain = null): void {
    if (PHP_VERSION_ID >= 70300) {
        $cookieOpts = [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Strict',
        ];
        if (!empty($domain)) $cookieOpts['domain'] = $domain;
        setcookie($name, '', $cookieOpts);
    } else {
        $domain = $domain ?? '';
        setcookie($name, '', time() - 3600, '/', $domain, $secure, true);
    }
}

if (class_exists('Auth') && method_exists('Auth', 'initSessionCookie')) {
    Auth::initSessionCookie();
} else {
    $sessionCookieParams = [
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Strict',
    ];
    if (!empty($cookieDomain)) {
        $sessionCookieParams['domain'] = $cookieDomain;
    }

    // vymazání staré/neplatné cookie
    if (!empty($_COOKIE['session_token'])) {
        clearSessionCookie('session_token', $secure, $cookieDomain);
    }

    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params($sessionCookieParams);
    } else {
        $domain = $sessionCookieParams['domain'] ?? '';
        session_set_cookie_params(
            $sessionCookieParams['lifetime'],
            $sessionCookieParams['path'],
            $domain,
            $sessionCookieParams['secure'],
            $sessionCookieParams['httponly']
        );
    }

    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
}

// ------------------------------------------------------------
// Database initialization (support namespaced or global Database)
// ------------------------------------------------------------

try {
    if (class_exists('Database') && method_exists('Database', 'init')) {
        Database::init($config['db'] ?? []);
        $db = Database::getInstance()->getPdo();
    } elseif (isset($GLOBALS['db']) && $GLOBALS['db'] instanceof PDO) {
        $db = $GLOBALS['db'];
    } else {
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
    error_log('[admin bootstrap] DB init failed: ' . $e->getMessage());
    http_response_code(500);
    echo 'Server configuration error: database not initialized.';
    exit;
}

if (!($db instanceof PDO)) {
    error_log('[admin bootstrap] DB not initialized after attempts');
    http_response_code(500);
    echo 'Server configuration error: database not initialized.';
    exit;
}

// ------------------------------------------------------------
// Restore persistent session: prefer Auth::validateSession(PDO) if present
// ------------------------------------------------------------
try {
    if (class_exists('Auth') && method_exists('Auth', 'validateSession')) {
        Auth::validateSession($db);
    }
} catch (Throwable $e) {
    error_log('[bootstrap] Session restore failed: ' . $e->getMessage());
    // do not die — session restore failure shouldn't break admin UI entirely
}

// ------------------------------------------------------------
// Configure FileVault (explicit DI) — admin should pass keys/storage and audit PDO
// ------------------------------------------------------------
if (class_exists('FileVault')) {
    $fvKeysDir = $config['paths']['keys'] ?? ($PROJECT_ROOT . '/secure/keys');
    $fvStorage = $config['paths']['storage'] ?? ($PROJECT_ROOT . '/secure/storage');
    $fvAuditDir = $config['paths']['audit'] ?? (rtrim($config['paths']['storage'] ?? ($PROJECT_ROOT . '/secure/storage'), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'audit');


    // ensure keys dir exists (fatal in admin area)
    if (!is_dir($fvKeysDir) || !is_readable($fvKeysDir)) {
        error_log('[bootstrap] FileVault keys directory not available: ' . $fvKeysDir);
        http_response_code(500);
        echo 'Server configuration error: encryption keys missing.';
        exit;
    }

    $actorProvider = function() : ?string {
        if (session_status() === PHP_SESSION_NONE) return null;
        return isset($_SESSION['user_id']) ? (string) $_SESSION['user_id'] : null;
    };

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

// ------------------------------------------------------------
// Restrict admin area: require login + check is_admin in DB
// ------------------------------------------------------------
if (empty($_SESSION['user_id'])) {
    $adminRedirectToLogin('/admin/');
}

try {
    $stmt = $db->prepare('SELECT id, is_admin FROM pouzivatelia WHERE id = ? LIMIT 1');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user || ((int)($user['is_admin'] ?? 0) !== 1)) {
        $adminRedirectToLogin('/admin/');
    }
} catch (Throwable $e) {
    error_log('[bootstrap] Admin check failed: ' . $e->getMessage());
    $adminRedirectToLogin('/admin/');
}

if (class_exists('AuditLogger') && method_exists('AuditLogger', 'log')) {
    try {
        AuditLogger::log($db, (string)$_SESSION['user_id'], 'admin_login', json_encode([
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            'ua' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    } catch (Throwable $e) {
        error_log('[bootstrap] Audit log admin_login failed: ' . $e->getMessage());
    }
}

// ------------------------------------------------------------
// Enforce password change if needed (optional component)
if (class_exists('EnforcePasswordChange') && method_exists('EnforcePasswordChange', 'check')) {
    try {
        EnforcePasswordChange::check($db);
    } catch (Throwable $e) {
        error_log('[bootstrap] EnforcePasswordChange failed: ' . $e->getMessage());
    }
}

// ------------------------------------------------------------
// Security headers
if ($secure) {
    header('Strict-Transport-Security: max-age=63072000; includeSubDomains; preload');
}
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer-when-downgrade');

// Bootstrap done: $db (PDO) and session available
return $db;
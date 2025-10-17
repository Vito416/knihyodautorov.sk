<?php
declare(strict_types=1);

use BlackCat\Core\Database;
use BlackCat\Core\Security\KeyManager;
use BlackCat\Core\Security\Crypto;
use BlackCat\Core\Security\FileVault;
Use BlackCat\Core\Helpers\DeferredHelper;
use BlackCat\Core\Log\AuditLogger;
use BlackCat\Core\Log\Logger;
use BlackCat\Core\Templates\Templates;
use BlackCat\Core\Helpers\EnforcePasswordChange;
use BlackCat\Core\Cache\FileCache;
use BlackCat\Core\Adapter\LoggerPsrAdapter;
use BlackCat\Core\Payment\GoPaySdkWrapper;
use BlackCat\Core\Payment\GoPayAdapter;

$PROJECT_ROOT = realpath(dirname(__DIR__, 5));
if ($PROJECT_ROOT === false) {
    error_log('[bootstrap_full] Cannot resolve PROJECT_ROOT');
    http_response_code(500);
    exit;
}

require_once __DIR__ . '/config_loader.php';
try {
    $config = load_project_config($PROJECT_ROOT);
} catch (Throwable $e) {
    error_log('[bootstrap_full] Cannot load config');
    http_response_code(500);
    exit;
}

$autoloadPath = $PROJECT_ROOT . '/libs/autoload.php';
if (!file_exists($autoloadPath)) {
    error_log('[bootstrap] Autoloader not found at ' . $autoloadPath);
    http_response_code(500);
    exit;
}
require_once $autoloadPath;

// small helper to log via Logger if available, else error_log
$logBootstrapError = function(string $msg, ?Throwable $ex = null) {
    if (class_exists(Logger::class, true)) {
        try {
            Logger::systemMessage('critical', $msg, null, ['exception' => $ex ? $ex->getMessage() : null, 'stage' => 'bootstrap']);
            return;
        } catch (Throwable $_) { /* swallow */ }
    }
    error_log('[bootstrap] ' . $msg . ($ex ? ' - ' . $ex->getMessage() : ''));
};

$loggerShim = new LoggerPsrAdapter();

// -------------------- constants from config critical for encryption --------------------
if (empty($config['paths']['keys'])) {
    $logBootstrapError('config.paths.keys is required');
    http_response_code(500);
    exit;
}
if (!defined('KEYS_DIR')) define('KEYS_DIR', $config['paths']['keys']);
if (!defined('APP_NAME')) define('APP_NAME', $config['app_name'] ?? ($_ENV['APP_NAME'] ?? 'app'));
if (!defined('APP_URL')) define('APP_URL', $config['app_url'] ?? ($_ENV['APP_URL'] ?? ''));

// -------------------- Crypto / KeyManager (fail fast) --------------------
try {
    if (!class_exists(KeyManager::class, true)) throw new RuntimeException('KeyManager class not available');
    if (!class_exists(Crypto::class, true)) throw new RuntimeException('Crypto class not available');
    if (empty(KEYS_DIR)) throw new RuntimeException('keys dir not configured in config');

    KeyManager::requireSodium();
    Crypto::initFromKeyManager(KEYS_DIR);
} catch (Throwable $e) {
    $logBootstrapError('Crypto initialization failed', $e);
    http_response_code(500);
    exit;
}

// -------------------- Database init (must succeed) --------------------
try {
    if (!class_exists(Database::class, true)) {
        throw new RuntimeException('Database class not available (autoload error)');
    }

    Database::init($config['db']);
    $database = Database::getInstance();
    $db = $database->getPdo();

    if (class_exists(DeferredHelper::class, true)) {
        try { DeferredHelper::flush(); } catch (Throwable $_) { /* silent */ }
    }
} catch (Throwable $e) {
    $logBootstrapError('Database initialization failed', $e);
    http_response_code(500);
    exit;
}

if (!($db instanceof PDO)) {
    $logBootstrapError('DB variable is not a PDO instance after init');
    http_response_code(500);
    exit;
}
// -------------------- FileCache pro Session (šifrovaná) --------------------
$sessionCacheDir = $PROJECT_ROOT . '/cache/session';
if (!is_dir($sessionCacheDir)) {
    if (!@mkdir($sessionCacheDir, 0700, true) && !is_dir($sessionCacheDir)) {
        $logBootstrapError('Failed to create CSRF cache dir: ' . $sessionCacheDir);
        http_response_code(500);
        exit;
    }
}

// FileCache instance (už se šifrováním)
$sessionFileCache = new FileCache($sessionCacheDir, true, KEYS_DIR, 'CACHE_CRYPTO_KEY', 'cache_crypto', 2, 500*1024*1024, 200000, 2*1024*1024);

// -------------------- Session restore using loader (best-effort) --------------------
require_once __DIR__ . '/loaders/session_loader.php';
try {
    $userId = init_session_and_restore($db, $sessionFileCache);
} catch (Throwable $e) {
    $logBootstrapError('Session restore failed', $e);
    // continue as guest
    $userId = null;
}

// -------------------- FileCache pro CSRF (šifrovaná) --------------------
$csrfCacheDir = $PROJECT_ROOT . '/cache/csrf';
if (!is_dir($csrfCacheDir)) {
    if (!@mkdir($csrfCacheDir, 0700, true) && !is_dir($csrfCacheDir)) {
        $logBootstrapError('Failed to create CSRF cache dir: ' . $csrfCacheDir);
        http_response_code(500);
        exit;
    }
}

// FileCache instance (už se šifrováním)
$csrfFileCache = new FileCache($csrfCacheDir, true, KEYS_DIR, 'CACHE_CRYPTO_KEY', 'cache_crypto', 2, 500*1024*1024, 200000, 2*1024*1024);

// -------------------- CSRF init using loader (best-effort) --------------------
require_once __DIR__ . '/loaders/csrf_loader.php';
try {
    init_csrf_from_session($csrfFileCache, $loggerShim);
} catch (Throwable $e) {
    $logBootstrapError('CSRF init failed', $e);
}

// -------------------- FileVault configuration (if used) --------------------
if (class_exists(FileVault::class, true)) {
    // ... keep your existing FileVault config code (unchanged) ...
}

// -------------------- Optional inits: Audit / EnforcePasswordChange --------------------
if (!empty($userId) && class_exists(AuditLogger::class, true)) {
    try {
        AuditLogger::log($db, (string)$userId, 'user_session_restore', json_encode([
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            'ua' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    } catch (Throwable $e) {
        $logBootstrapError('AuditLogger.log failed', $e);
    }
}

if (class_exists(EnforcePasswordChange::class, true)) {
    try {
        EnforcePasswordChange::check($database ?? $db);
    } catch (Throwable $e) {
        $logBootstrapError('EnforcePasswordChange check failed', $e);
    }
}

if (class_exists(DeferredHelper::class, true)) {
    try { DeferredHelper::flush(); } catch (Throwable $_) { /* silent */ }
}

// Templates init
Templates::init($config);

// Mailer init (safe loader)
require_once __DIR__ . '/loaders/mailer_loader.php';
init_mailer_from_config($config, $db); // $db je PDO returned by Database::getPdo()

// -------------------- FileCache pro GoPay (šifrovaná) --------------------
$gopayCacheDir = $PROJECT_ROOT . '/cache/gopay';
if (!is_dir($gopayCacheDir)) {
    if (!@mkdir($gopayCacheDir, 0700, true) && !is_dir($gopayCacheDir)) {
        $logBootstrapError('Failed to create GoPay cache dir: ' . $gopayCacheDir);
        http_response_code(500);
        exit;
    }
}

// FileCache instance (už se šifrováním)
$gopayFileCache = new FileCache($gopayCacheDir, true, KEYS_DIR, 'CACHE_CRYPTO_KEY', 'cache_crypto', 2, 500*1024*1024, 200000, 2*1024*1024);

// -------------------- GoPay wrapper + adapter --------------------
$gopayWrapper = new GoPaySdkWrapper($config['gopay'], $loggerShim, $gopayFileCache);

$gopayAdapter = new GoPayAdapter(
    $database,
    $gopayWrapper,
    $loggerShim,
    null,            // mailer
    $config['gopay']['notify_url'],
    $config['gopay']['return_url'],
    $gopayFileCache
);

// Return $db for backwards compatibility (keep old code working)
return $db;
<?php
declare(strict_types=1);

// -------------------- resolve project root --------------------
$PROJECT_ROOT = realpath(__DIR__ . '/../../../../..');
if ($PROJECT_ROOT === false) {
    error_log('[bootstrap] Cannot resolve PROJECT_ROOT');
    http_response_code(500);
    exit;
}

// -------------------- load config --------------------
// secure/config.php MUST set $config array
$configFile = $PROJECT_ROOT . '/secure/config.php';
if (!file_exists($configFile)) {
    error_log('[bootstrap] Missing secure/config.php');
    http_response_code(500);
    exit;
}
require_once $configFile;
if (!isset($config) || !is_array($config)) {
    error_log('[bootstrap] secure/config.php must define $config array');
    http_response_code(500);
    exit;
}

// -------------------- autoloader (required) --------------------
$autoloadPath = $PROJECT_ROOT . '/libs/autoload.php';
if (!file_exists($autoloadPath)) {
    error_log('[bootstrap] Autoloader not found at ' . $autoloadPath);
    http_response_code(500);
    exit;
}
require_once $autoloadPath;

// If logger implementation file sits outside autoload, require it now (non-fatal if missing)
$loggerPath = $PROJECT_ROOT . '/libs/logger.php';
if (file_exists($loggerPath)) {
    require_once $loggerPath;
}

// small helper to log via Logger if available, else error_log
$logBootstrapError = function(string $msg, ?Throwable $ex = null) {
    if (class_exists('Logger') && method_exists('Logger', 'systemMessage')) {
        // minimal context for bootstrap errors
        try {
            Logger::systemMessage('critical', $msg, null, ['exception' => $ex ? $ex->getMessage() : null, 'stage' => 'bootstrap']);
            return;
        } catch (Throwable $_) {
            // swallow and fallback to error_log
        }
    }
    error_log('[bootstrap] ' . $msg . ($ex ? ' - ' . $ex->getMessage() : ''));
};

// -------------------- constants from config --------------------
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
    if (!class_exists('KeyManager')) throw new RuntimeException('KeyManager class not available');
    if (!class_exists('Crypto')) throw new RuntimeException('Crypto class not available');
    if (empty(KEYS_DIR)) throw new RuntimeException('keys dir not configured in config');

    // Ensure libsodium is present
    KeyManager::requireSodium();

    // Initialize Crypto from KeyManager (reads versioned key file: crypto_key_vN.bin)
    Crypto::initFromKeyManager(KEYS_DIR);
} catch (Throwable $e) {
    $logBootstrapError('Crypto initialization failed', $e);
    http_response_code(500);
    exit;
}

// -------------------- Database init (must succeed) --------------------
try {
    if (!class_exists('Database')) {
        throw new RuntimeException('Database class not available (autoload error)');
    }
    if (empty($config['db']) || !is_array($config['db'])) {
        throw new RuntimeException('Missing $config[\'db\']');
    }

    Database::init($config['db']);              // may throw DatabaseException
    $database = Database::getInstance();        // Database instance for DI / new code
    $db = $database->getPdo();                  // keep $db as PDO for backwards compatibility

    // Flush any deferred items (Logger, EnforcePasswordChange, future libs)
    if (class_exists('DeferredHelper') && method_exists('DeferredHelper', 'flush')) {
        try { DeferredHelper::flush(); } catch (Throwable $_) { /* silent */ }
    }
} catch (Throwable $e) {
    $logBootstrapError('Database initialization failed', $e);
    http_response_code(500);
    exit;
}

// sanity
if (!($db instanceof PDO)) {
    $logBootstrapError('DB variable is not a PDO instance after init');
    http_response_code(500);
    exit;
}

// -------------------- Session restore from cookie (use Database API) --------------------
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$userId = SessionManager::validateSession($db);

// -------------------- FileVault configuration (if used) --------------------
if (class_exists('FileVault')) {
    $fvKeysDir = $config['paths']['keys'] ?? ($PROJECT_ROOT . '/secure/keys');
    $fvStorage = $config['paths']['storage'] ?? ($PROJECT_ROOT . '/secure/storage');
    $fvAuditDir = $config['paths']['audit'] ?? null;

    $actorProvider = fn(): ?string => $userId ? (string)$userId : null;

    if (method_exists('FileVault', 'configure')) {
        FileVault::configure([
            'keys_dir' => $fvKeysDir,
            'storage_base' => $fvStorage,
            'audit_dir' => $fvAuditDir,
            'audit_db' => $database,   // prefer Database instance
            'actor_provider' => $actorProvider,
        ]);
    } else {
        if (method_exists('FileVault', 'setKeysDir')) FileVault::setKeysDir($fvKeysDir);
        if (method_exists('FileVault', 'setStorageBase')) FileVault::setStorageBase($fvStorage);
        if (method_exists('FileVault', 'setAuditPdo')) FileVault::setAuditPdo($db);
        if (method_exists('FileVault', 'setActorProvider')) FileVault::setActorProvider($actorProvider);
    }
}

// -------------------- Optional inits: Auth / Audit / EnforcePasswordChange --------------------

if (!empty($userId) && class_exists('AuditLogger') && method_exists('AuditLogger', 'log')) {
    try {
        // prefer Database API
        AuditLogger::log(
            $database ?? $db,
            (string)$userId,
            'user_session_restore',
            json_encode([
                'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                'ua' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    } catch (Throwable $e) {
        $logBootstrapError('AuditLogger.log failed', $e);
    }
}

if (class_exists('EnforcePasswordChange') && method_exists('EnforcePasswordChange', 'check')) {
    try {
        EnforcePasswordChange::check($database ?? $db);
    } catch (Throwable $e) {
        $logBootstrapError('EnforcePasswordChange check failed', $e);
    }
}

// Final flush for any deferred items (Logger, EnforcePasswordChange, etc.)
if (class_exists('DeferredHelper') && method_exists('DeferredHelper', 'flush')) {
    try { DeferredHelper::flush(); } catch (Throwable $_) { /* silent */ }
}

// Return PDO for backwards compatibility (old code expects $db = require 'bootstrap.php';)
return $db;
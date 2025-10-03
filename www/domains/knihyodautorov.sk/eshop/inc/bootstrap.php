<?php
declare(strict_types=1);

// -------------------- resolve project root --------------------
$PROJECT_ROOT = realpath(__DIR__ . '/../../../../..');
if ($PROJECT_ROOT === false) {
    error_log('[bootstrap] Cannot resolve PROJECT_ROOT');
    http_response_code(500);
    exit;
}

// --- require config_loader early ---
require_once __DIR__ . '/config_loader.php';

try {
    $config = load_project_config($PROJECT_ROOT);
} catch (Throwable $e) {
    error_log('[bootstrap] ' . $e->getMessage());
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
        try {
            Logger::systemMessage('critical', $msg, null, ['exception' => $ex ? $ex->getMessage() : null, 'stage' => 'bootstrap']);
            return;
        } catch (Throwable $_) { /* swallow */ }
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

use GoPay\Definition\Language;
use GoPay\Definition\TokenScope;

$gopayCfg = [
    'goid' => $_ENV['GOPAY_GOID'] ?? '',
    'clientId' => $_ENV['GOPAY_CLIENT_ID'] ?? '',
    'clientSecret' => $_ENV['GOPAY_CLIENT_SECRET'] ?? '',
    'gatewayUrl' => $_ENV['GOPAY_GATEWAY_URL'] ?? 'https://gw.sandbox.gopay.com',
    'language' => $_ENV['GOPAY_LANGUAGE'] ?? Language::CZECH,
    'scope' => $_ENV['GOPAY_SCOPE'] ?? TokenScope::ALL,
];

// logger shim: adapt your existing Logger static API to object expected by adapter
$loggerShim = new class {
    public function info($message, $userId = null, $context = null) { try { Logger::info($message, $userId, $context); } catch (\Throwable $_) {} }
    public function warn($message, $userId = null, $context = null) { try { Logger::warn($message, $userId, $context); } catch (\Throwable $_) {} }
    public function systemError($e, $userId = null, $token = null, $context = null) { try { Logger::systemError($e, $userId, $token, $context); } catch (\Throwable $_) {} }
    public function systemMessage($level, $message, $userId = null, $context = null) { try { Logger::systemMessage($level, $message, $userId, $context); } catch (\Throwable $_) {} }
};

$notificationUrl = (string)($_ENV['APP_GOPAY_NOTIFY_URL'] ?? ($_ENV['APP_URL'] ?? '') . 'gopay/notify');
$returnUrl = (string)($_ENV['APP_GOPAY_RETURN_URL'] ?? ($_ENV['APP_URL'] ?? '') . 'order/return');

// -------------------- Crypto / KeyManager (fail fast) --------------------
try {
    if (!class_exists('KeyManager')) throw new RuntimeException('KeyManager class not available');
    if (!class_exists('Crypto')) throw new RuntimeException('Crypto class not available');
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
    if (!class_exists('Database')) {
        throw new RuntimeException('Database class not available (autoload error)');
    }

    Database::init($config['db']);
    $database = Database::getInstance();
    $db = $database->getPdo();

    if (class_exists('DeferredHelper') && method_exists('DeferredHelper', 'flush')) {
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

// -------------------- Session restore using loader (best-effort) --------------------
require_once __DIR__ . '/loaders/session_loader.php';
try {
    $userId = init_session_and_restore($db);
} catch (Throwable $e) {
    $logBootstrapError('Session restore failed', $e);
    // continue as guest
    $userId = null;
}

// -------------------- CSRF init using loader (best-effort) --------------------
require_once __DIR__ . '/loaders/csrf_loader.php';
try {
    init_csrf_from_session();
} catch (Throwable $e) {
    $logBootstrapError('CSRF init failed', $e);
}

// -------------------- FileVault configuration (if used) --------------------
if (class_exists('FileVault')) {
    // ... keep your existing FileVault config code (unchanged) ...
}

// -------------------- Optional inits: Audit / EnforcePasswordChange --------------------
if (!empty($userId) && class_exists('AuditLogger') && method_exists('AuditLogger', 'log')) {
    try {
        AuditLogger::log($db, (string)$userId, 'user_session_restore', json_encode([
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            'ua' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
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

if (class_exists('DeferredHelper') && method_exists('DeferredHelper', 'flush')) {
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
$gopayFileCache = new FileCache($gopayCacheDir, true, KEYS_DIR);

// -------------------- GoPay wrapper + adapter --------------------
$gopayWrapper = new GoPaySdkWrapper($gopayCfg, $gopayFileCache);

$gopayAdapter = new GoPayAdapter(
    $database,       // DB instance
    $gopayWrapper,   // wrapper
    $loggerShim,     // logger
    null,
    $notificationUrl, 
    $returnUrl
);

// Return $db for backwards compatibility (keep old code working)
return $db;
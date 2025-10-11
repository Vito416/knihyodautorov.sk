<?php

declare(strict_types=1);

require_once __DIR__ . '/load_env.php';

/**
 * Minimal secure config — production-ready.
 * DO NOT store raw keys here.
 */

$config = [
    'db' => [
        'dsn' => 'mysql:host=' . ($_ENV['DB_HOST'] ?? '') . ';dbname=' . ($_ENV['DB_NAME'] ?? '') . ';charset=utf8mb4',
        'user' => $_ENV['DB_USER'] ?? '',
        'pass' => $_ENV['DB_PASS'] ?? '',
        'options' => [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ],
    ],

    'adb' => [
        'dsn' => 'mysql:host=' . ($_ENV['ADB_HOST'] ?? '') . ';dbname=' . ($_ENV['ADB_NAME'] ?? '') . ';charset=utf8mb4',
        'user' => $_ENV['ADB_USER'] ?? '',
        'pass' => $_ENV['ADB_PASS'] ?? '',
        'options' => [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ],
    ],

    'paths' => [
    'templates' => __DIR__ . '/../www/domains/knihyodautorov.sk/eshop/templates',
    'email_templates' => __DIR__ . '/../www/domains/knihyodautorov.sk/eshop/templates/emails',
    'storage' => __DIR__ . '/../storage',
    'uploads' => __DIR__ . '/../storage/uploads',
    'keys' => __DIR__ . '/keys',
    ],
    'debug' => false,

    'google' => [
        'client_id' => $_ENV['GOOGLE_CLIENT_ID'] ?? '',
        'client_secret' => $_ENV['GOOGLE_CLIENT_SECRET'] ?? '',
        'redirect_uri' => $_ENV['GOOGLE_REDIRECT_URI'] ?? '',
    ],

    'gopay' => [
        'merchant_id' => $_ENV['GOPAY_MERCHANT_ID'] ?? '',
        'client_id' => $_ENV['GOPAY_CLIENT_ID'] ?? '',
        'client_secret' => $_ENV['GOPAY_CLIENT_SECRET'] ?? '',
        'return_url' => $_ENV['GOPAY_RETURN_URL'] ?? '',
        'notify_url' => $_ENV['GOPAY_NOTIFY_URL'] ?? '',
    ],

    'smtp' => [
    'host' => $_ENV['SMTP_HOST'] ?? '',
    'port' => (int)($_ENV['SMTP_PORT'] ?? 0),
    'user' => $_ENV['SMTP_USER'] ?? '',
    'pass' => $_ENV['SMTP_PASS'] ?? '',
    'from_email' => $_ENV['SMTP_FROM_EMAIL'] ?? '',
    'from_name' => $_ENV['SMTP_FROM_NAME'] ?? '',
    'secure' => $_ENV['SMTP_SECURE'] ?? '', // 'ssl'|'tls' or ''
    'timeout' => 10,
    'max_retries' => 6,
    ],
    'table_names' => [],

    'capchav3' => [
    'site_key' => $_ENV['CAPCHA_SITE_KEY'] ?? '',
    'secret_key' => $_ENV['CAPCHA_SECRET_KEY'] ?? '',
    'min_score' => $_ENV['CAPCHA_MIN_SCORE'] ?? '',
    ],
    'app_domain' => $_ENV['APP_DOMAIN'] ?? '',
];

// helper functions (resolve_path, warn_if_within_document_root) — reuse earlier implementations
// include resolve_path and warn_if_within_document_root from your prior file (unchanged).
// For brevity assume they exist above or require once.

function resolve_path(string $path): string
{
    // je-li již absolutní (linux/unix) nebo windows drive letter
    if ($path === '') {
        return $path;
    }

    $isAbsolute = ($path[0] === '/' || preg_match('#^[A-Za-z]:\\\\#', $path) === 1);
    if ($isAbsolute) {
        // normalize .. and . segments
        $parts = preg_split('#[\\/\\\\]+#', $path);
    } else {
        $base = realpath(__DIR__ . '/..') ?: (__DIR__ . '/..'); // fallback pokud realpath selže
        $combined = rtrim($base, '/\\') . '/' . $path;
        $parts = preg_split('#[\\/\\\\]+#', $combined);
    }

    $stack = [];
    foreach ($parts as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }
        if ($part === '..') {
            array_pop($stack);
            continue;
        }
        $stack[] = $part;
    }

    $normalized = DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $stack);
    // Pokud původní path měl drive letter (windows), zachovej ho
    if (preg_match('#^[A-Za-z]:#', $path) === 1) {
        $normalized = ltrim($normalized, DIRECTORY_SEPARATOR); // remove leading slash
    }

    return $normalized;
}

function warn_if_within_document_root(string $resolvedPath): void
{
    if (!isset($_SERVER['DOCUMENT_ROOT']) || $_SERVER['DOCUMENT_ROOT'] === '') {
        return;
    }

    $docRoot = realpath($_SERVER['DOCUMENT_ROOT']) ?: rtrim($_SERVER['DOCUMENT_ROOT'], "/\\");
    // normalizovat oba
    $rp = realpath($resolvedPath) ?: $resolvedPath;

    // porovnej prefix
    if (strpos($rp, $docRoot) === 0) {
        trigger_error(sprintf('Security warning: configured path "%s" appears to be inside DOCUMENT_ROOT ("%s"). Prefer storing files outside webroot.', $resolvedPath, $docRoot), E_USER_WARNING);
    }
}

try {
    // resolve and canonicalize paths (storage/uploads/keys)
    foreach (['storage','uploads','keys'] as $p) {
        if (empty($config['paths'][$p])) throw new RuntimeException(sprintf('paths[%s] must be set', $p));
        $resolved = resolve_path($config['paths'][$p]);
        $real = realpath($resolved);
        if ($real !== false) $resolved = $real;
        $config['paths'][$p] = $resolved;
        warn_if_within_document_root($resolved);
    }
} catch (RuntimeException $e) {
    throw $e;
}

return $config;
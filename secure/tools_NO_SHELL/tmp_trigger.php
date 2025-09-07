<?php
declare(strict_types=1);
// public/tmp_trigger.php  — TEMPORARY WRAPPER (put in webroot) 
// AFTER use: delete this file and remove KEY_GEN_TOKEN from .env

// only POST allowed
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Method Not Allowed';
    exit;
}

// minimal boot: load .env that we keep in /secure (loader must populate $_ENV)
$secureLoad = __DIR__ . '/../../../secure/load_env.php';
if (!is_readable($secureLoad)) {
    http_response_code(500);
    echo 'Configuration loader not available';
    exit;
}
require_once $secureLoad;

// get token from env and request
$expectedToken = $_ENV['KEY_GEN_TOKEN'] ?? '';
$token = $_POST['token'] ?? '';

// basic checks
if (!is_string($expectedToken) || $expectedToken === '') {
    http_response_code(403);
    echo 'Key generation disabled (no token configured)';
    exit;
}
if (!is_string($token) || !hash_equals($expectedToken, $token)) {
    http_response_code(403);
    echo 'Invalid token';
    exit;
}

// optional IP allowlist
$allow = trim((string)($_ENV['ADMIN_ALLOW_IPS'] ?? ''));
if ($allow !== '') {
    $list = preg_split('/[\s,]+/', $allow, -1, PREG_SPLIT_NO_EMPTY);
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($remote, $list, true)) {
        http_response_code(403);
        echo 'IP not allowed';
        exit;
    }
}

// basic rate-limit: prevent rapid re-use (5 min window)
$lockFile = sys_get_temp_dir() . '/tmp_trigger_lock';
$lockTimeout = 300; // seconds
if (file_exists($lockFile) && (time() - filemtime($lockFile)) < $lockTimeout) {
    http_response_code(429);
    echo 'Too many requests - try later';
    exit;
}
@touch($lockFile);

// Which secure script to invoke? (example: generate keys or run audit)
// Accept minimal whitelist via POST parameter 'action'
$action = $_POST['action'] ?? 'generate_keys';
$allowedActions = [
    'generate_keys' => __DIR__ . '/../../../secure/generate_keys.php',
    'run_audit'     => __DIR__ . '/../../../secure/run_security_audit.php'
];

if (!isset($allowedActions[$action])) {
    @unlink($lockFile);
    http_response_code(400);
    echo 'Invalid action';
    exit;
}

$secureScript = $allowedActions[$action];
if (!is_readable($secureScript)) {
    @unlink($lockFile);
    http_response_code(500);
    echo 'Requested secure operation not available';
    exit;
}

// Execute the secure script and capture its output
// The secure script must itself be protected (it already checks tokens).
ob_start();
try {
    require $secureScript; // note: this file is executed within this request
} catch (Throwable $e) {
    ob_end_clean();
    @unlink($lockFile);
    http_response_code(500);
    error_log('[tmp_trigger] secure script exception: ' . $e->getMessage());
    echo 'Operation failed (see server log)';
    exit;
}
$output = ob_get_clean();

// ensure output is safe JSON (secure scripts should output JSON)
header('Content-Type: application/json; charset=utf-8');
echo $output;

// cleanup lock
@unlink($lockFile);

// optional self-delete
$autoDelete = (string)($_ENV['AUTO_SELFDELETE'] ?? '0');
if ($autoDelete === '1') {
    @unlink(__FILE__); // best-effort; if fails, delete via FTP manually
}
exit;
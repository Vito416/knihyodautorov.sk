<?php
declare(strict_types=1);

/**
 * internal.php – kompletní health check
 */

$start = microtime(true);
$checks = [];

/** ---------------- Database ---------------- */
try {
    if (\Database::isInitialized()) {
        $db = \Database::getInstance();
        $ok = $db->ping();
        $version = $ok ? $db->fetchValue('SELECT VERSION()') : null;
        $checks['database.default'] = [
            'ok' => $ok,
            'server_version' => $version,
        ];
    } else {
        $checks['database.default'] = [
            'ok' => false,
            'error' => 'Database not initialized',
        ];
    }
} catch (\Throwable $e) {
    $checks['database.default'] = [
        'ok' => false,
        'error' => substr($e->getMessage(), 0, 200),
    ];
}

/** ---------------- PHP extensions ---------------- */
$requiredExt = ['pdo_mysql', 'mbstring', 'gd', 'openssl'];
foreach ($requiredExt as $ext) {
    $checks['ext.' . $ext] = [
        'ok' => extension_loaded($ext),
    ];
}

/** ---------------- DNS resolution ---------------- */
try {
    $ip = gethostbyname('knihyodautorov.sk');
    $checks['dns'] = [
        'ok' => $ip !== 'knihyodautorov.sk',
        'resolved_ip' => $ip,
    ];
} catch (\Throwable $e) {
    $checks['dns'] = ['ok' => false, 'error' => substr($e->getMessage(), 0, 200)];
}

/** ---------------- Outbound connectivity ---------------- */
function tcp_check(string $host, int $port, float $timeout = 2.0): bool {
    try {
        $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if (!$fp) return false;
        fclose($fp);
        return true;
    } catch (\Throwable $e) {
        return false;
    }
}

$checks['connectivity.https'] = [
    'ok' => tcp_check('google.com', 443),
];

/** ---------------- SMTP check ---------------- */
try {
    $smtpCfg = $config['smtp'];
    if (is_array($smtpCfg) && isset($smtpCfg['host'], $smtpCfg['port'])) {
        $checks['smtp'] = [
            'ok' => tcp_check($smtpCfg['host'], (int)$smtpCfg['port']),
            'host' => $smtpCfg['host'],
            'port' => (int)$smtpCfg['port'],
        ];
    } else {
        $checks['smtp'] = ['ok' => false, 'error' => 'Missing SMTP config'];
    }
} catch (\Throwable $e) {
    $checks['smtp'] = ['ok' => false, 'error' => substr($e->getMessage(), 0, 200)];
}

/** ---------------- Meta ---------------- */
$meta = [
    'php_version' => PHP_VERSION,
    'time' => gmdate('c'),
];

/** ---------------- Result ---------------- */
$duration = (microtime(true) - $start) * 1000.0;

return [
    'ok' => array_reduce($checks, fn($carry, $c) => $carry && ($c['ok'] ?? false), true),
    'checks' => $checks,
    'meta' => $meta,
    'duration_ms' => round($duration, 2),
];
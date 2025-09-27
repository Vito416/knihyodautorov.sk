<?php
declare(strict_types=1);
require_once __DIR__ . '/eshop/inc/bootstrap.php';
// veřejný wrapper healthcheck
header('Content-Type: application/json; charset=utf-8');

$internal = realpath(dirname(__DIR__, 3)) . '/health/internal.php';

$secret = $_ENV['HEALTH_TOKEN'] ?? '';

// klient poslal?
$sent = $_GET['TOKEN'] ?? '';
if ($sent === '' || !hash_equals($secret, $sent)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    $health = include $internal;
    if (!is_array($health)) {
        throw new RuntimeException('Internal health did not return array');
    }
    http_response_code($health['ok'] ? 200 : 500);
    echo json_encode($health, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok'      => false,
        'message' => 'Internal error',
        'error'   => $e->getMessage(), // volitelně – pro debug
    ]);
}
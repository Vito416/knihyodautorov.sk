<?php
declare(strict_types=1);

/**
 * notify.php
 * Webhook receiver for GoPay notifications.
 *
 * Expected to have in bootstrap:
 *   - $gopayAdapter  (GoPayAdapter or compatible wrapper with handleNotify)
 *   - $logger
 *
 * GoPay may call GET /notify?id=<id> or POST JSON. We pass raw body + headers to adapter->handleNotify,
 * which will verify signature if GOPAY_WEBHOOK_SECRET is set in env and perform atomic DB updates.
 */

require_once __DIR__ . '/../inc/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');

// collect raw body and headers
$rawBody = file_get_contents('php://input');

// build headers array (preserve common names)
$headers = [];
foreach ($_SERVER as $k => $v) {
    if (strpos($k, 'HTTP_') === 0) {
        $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($k, 5)))));
        $headers[$name] = $v;
    }
}
// Common direct SERVER keys not in HTTP_*
if (!empty($_SERVER['CONTENT_TYPE'])) $headers['Content-Type'] = $_SERVER['CONTENT_TYPE'];
if (!empty($_SERVER['CONTENT_LENGTH'])) $headers['Content-Length'] = $_SERVER['CONTENT_LENGTH'];

// If GoPay used GET ?id=... some merchants prefer to forward rawBody empty and handle via id param.
// Our adapter::handleNotify will parse raw body (and expects paymentId/id inside) but to be resilient:
if (trim($rawBody) === '') {
    // create a minimal payload with id from GET to let adapter handle it consistently
    $id = trim((string)($_GET['id'] ?? $_GET['paymentId'] ?? ''));
    if ($id !== '') {
        $rawBody = json_encode(['paymentId' => $id]);
    }
}

try {
    // Pass raw body + headers to adapter handleNotify (it will verify signature if configured and do DB changes)
    $result = $gopayAdapter->handleNotify($rawBody, $headers);
    // expected: ['status'=>'processed', 'gopay_id'=>...]
    http_response_code(200);
    echo 'OK';
    exit;
} catch (\Throwable $e) {
    try { Logger::systemError($e, null, null, ['phase' => 'notify.handle', 'raw' => $rawBody]); } catch (\Throwable $_) {}
    // Non-200 => GoPay will retry delivery.
    http_response_code(500);
    echo 'ERROR';
    exit;
}
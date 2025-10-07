<?php
declare(strict_types=1);

/*
 * Minimal notify.php — silent on bad requests
 * - bootstrap must initialize Database::init(...) beforehand
 * - if bootstrap or DB missing -> 500
 * - accepts ?id=BIGINT (only digits)
 * - invalid/missing id -> silently 200 (ACK)
 * - INSERT errors are ignored
 */

if (!class_exists('Database') || !Database::isInitialized()) { http_response_code(500); exit; }

$id = $_GET['id'] ?? null;
if (!is_string($id) || $id === '' || !ctype_digit($id) || strlen($id) > 20 || $id === '0') {
    http_response_code(200);
    exit;
}

try {
    Database::getInstance()->execute(
        "INSERT INTO gopay_notify_log (order_id, received_at) VALUES (:order_id, NOW(6))",
        [':order_id' => $id]
    );
} catch (\Throwable $_) {
    // intentionally ignored
}

http_response_code(200);
exit;
// EOF
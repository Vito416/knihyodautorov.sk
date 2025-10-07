<?php
declare(strict_types=1);

/**
 * gopay_return.php
 * Front-controller friendly user return handler after GoPay redirect.
 *
 * - Must be included from front controller which already bootstraps the app
 *   (so do NOT require bootstrap here).
 * - Must NOT call header(...); exit; or echo raw HTML. Instead return an array
 *   with 'template' => 'pages/order_status.php' and optional 'vars' => [...]
 *
 * Expected variables injected by frontcontroller (TrustedShared):
 *   - $gopayAdapter
 *   - $db
 *   - $user (optional)
 *   - $logger (optional) or Logger static class may be used
 *
 * The returned template receives the following variables:
 *   - gopayId: string
 *   - mapped: array with payment_status/order_status
 *   - paymentRow: payment DB row or null
 *   - gatewayStatus: array (decoded) from GoPay
 *   - message: optional human friendly message
 */

// Read payment id from GET (compat with older param names)
$gopayId = trim((string)($_GET['id'] ?? $_GET['paymentId'] ?? ''));

if ($gopayId === '') {
    return [
        'template' => 'pages/order_status.php',
        'vars' => [
            'gopayId' => null,
            'mapped' => ['payment_status' => 'error', 'order_status' => 'error'],
            'paymentRow' => null,
            'gatewayStatus' => null,
            'message' => 'Missing payment identifier.',
        ],
    ];
}

// Local mapping (duplicate of adapter mapping if private)
$mapGatewayStatusToLocal = function ($status): array {
    $state = '';
    if (is_array($status)) {
        $state = strtolower((string)($status['state'] ?? $status['paymentState'] ?? ''));
    } elseif (is_object($status)) {
        $state = strtolower((string)($status->state ?? $status->paymentState ?? ''));
    } else {
        $state = strtolower((string)$status);
    }

    if (in_array($state, ['paid', 'completed', 'ok'], true)) {
        return ['payment_status' => 'paid', 'order_status' => 'paid'];
    }
    if ($state === 'authorized') {
        return ['payment_status' => 'authorized', 'order_status' => 'pending'];
    }
    if (in_array($state, ['cancelled', 'failed', 'declined'], true)) {
        return ['payment_status' => 'failed', 'order_status' => 'cancelled'];
    }
    return ['payment_status' => 'pending', 'order_status' => 'pending'];
};

$gatewayStatus = null;
try {
    $gatewayStatus = $gopayAdapter->fetchStatus($gopayId);
} catch (\Throwable $e) {
    // log, then return friendly template instructing user to try again / check later
    try {
        if (isset($logger) && method_exists($logger, 'systemError')) {
            $logger->systemError($e, null, null, ['phase' => 'return.fetchStatus', 'gopay_id' => $gopayId]);
        } elseif (class_exists('Logger')) {
            Logger::systemError($e, null, null, ['phase' => 'return.fetchStatus', 'gopay_id' => $gopayId]);
        }
    } catch (\Throwable $_) {}

    return [
        'template' => 'pages/order_status.php',
        'vars' => [
            'gopayId' => $gopayId,
            'mapped' => ['payment_status' => 'unknown', 'order_status' => 'unknown'],
            'paymentRow' => null,
            'gatewayStatus' => null,
            'message' => 'Unable to fetch payment status right now — please try again later.',
        ],
    ];
}

$mapped = $mapGatewayStatusToLocal($gatewayStatus);

// persist minimal payment row (idempotent)
// ONLY persist when we have an authoritative gateway response (not a pseudo/cache-miss).
if (is_array($gatewayStatus) && empty($gatewayStatus['_pseudo'])) {
    try {
        $db->transaction(function ($d) use ($gopayId, $gatewayStatus, $mapped) {
            $p = $d->fetch('SELECT * FROM payments WHERE transaction_id = :tx AND gateway = :gw LIMIT 1', [
                ':tx' => $gopayId,
                ':gw' => 'gopay',
            ]);

            $details = json_encode($gatewayStatus);

            if ($p === null) {
                $d->prepareAndRun('INSERT INTO payments (order_id, gateway, transaction_id, status, amount, currency, details, webhook_payload_hash, created_at)
                    VALUES (NULL, :gw, :tx, :st, 0, :cur, :det, NULL, NOW())', [
                    ':gw' => 'gopay',
                    ':tx' => $gopayId,
                    ':st' => $mapped['payment_status'],
                    ':cur' => 'EUR',
                    ':det' => $details,
                ]);
            } else {
                $d->prepareAndRun('UPDATE payments SET status = :st, details = :det, updated_at = NOW() WHERE id = :id', [
                    ':st' => $mapped['payment_status'],
                    ':det' => $details,
                    ':id' => $p['id'],
                ]);
            }
        });
    } catch (\Throwable $e) {
        try {
            if (isset($logger) && method_exists($logger, 'systemError')) {
                $logger->systemError($e, null, null, ['phase' => 'return.db_update', 'gopay_id' => $gopayId]);
            } elseif (class_exists('Logger')) {
                Logger::systemError($e, null, null, ['phase' => 'return.db_update', 'gopay_id' => $gopayId]);
            }
        } catch (\Throwable $_) {}
        // swallow — UX should continue even if DB update fails
    }
} 

// fetch payment row for display (best-effort) — only when we had an authoritative gateway response
$paymentRow = null;
if (is_array($gatewayStatus) && empty($gatewayStatus['_pseudo'])) {
    try {
        $paymentRow = $db->fetch('SELECT * FROM payments WHERE transaction_id = :tx AND gateway = :gw LIMIT 1', [':tx' => $gopayId, ':gw' => 'gopay']);
    } catch (\Throwable $_) {
        $paymentRow = null;
    }
}

// Prepare data for template
$gatewayStatusForTpl = null;
if (is_array($gatewayStatus)) {
    $gatewayStatusForTpl = $gatewayStatus;
} elseif (is_object($gatewayStatus)) {
    $gatewayStatusForTpl = json_decode(json_encode($gatewayStatus), true);
} else {
    $gatewayStatusForTpl = ['raw' => (string)$gatewayStatus];
}

// Allow template to render contextual links or messages
$vars = [
    'gopayId' => $gopayId,
    'mapped' => $mapped,
    'paymentRow' => $paymentRow,
    'gatewayStatus' => $gatewayStatusForTpl,
    'message' => null,
];

// If linked order exists, populate order_url for convenience
if (!empty($paymentRow['order_id'])) {
    $vars['order_url'] = '/order/' . rawurlencode((string)$paymentRow['order_id']);
}

// Provide friendly message hints (optional)
switch ($mapped['order_status']) {
    case 'paid':
        $vars['message'] = 'Payment completed. Thank you!';
        break;
    case 'pending':
        $vars['message'] = 'Payment pending. Please wait a moment and refresh this page.';
        break;
    case 'cancelled':
    case 'failed':
        $vars['message'] = 'Payment failed or was cancelled. Please try again or contact support.';
        break;
    default:
        $vars['message'] = null;
}

// Render order_status template via frontcontroller
return [
    'template' => 'pages/order_status.php',
    'vars' => $vars,
];
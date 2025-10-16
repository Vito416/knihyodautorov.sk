<?php
declare(strict_types=1);

/**
 * cart_clear.php (strict, injection-only)
 *
 * Endpoint: POST /eshop/cart_clear
 * Required injected variables (frontcontroller MUST pass):
 *   - Logger (class-string or object with logging methods)
 *   - CSRF   (class-string or object with validate(token): bool and token(): string)
 *   - db     (object with methods: transaction(callable), fetch(sql, params), execute(sql, params))
 *
 * Behavior:
 * - Validates CSRF via injected CSRF.
 * - If session-backed cart exists, deletes cart_items for that cart (transactional) and updates cart.updated_at.
 * - Returns JSON summary of empty cart. No silent fallbacks.
 */

// --- response helper (matching verify.php / cart_add strict style) ---
function respondJson(array $payload, int $status = 200): void {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    http_response_code($status);
    try { $payload['csrfToken'] = \BlackCat\Core\Security\CSRF::token(); } catch (\Throwable $_) {}
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

// --- require injected dependencies (strict) ---
$required = ['Logger','CSRF','db'];
$missing = [];
foreach ($required as $r) {
    if (!isset($$r)) $missing[] = $r;
}
if (!empty($missing)) {
    $msg = 'Interná konfigurácia chýba: ' . implode(', ', $missing) . '.';
    try {
        if (isset($Logger)) {
            if (is_string($Logger) && class_exists($Logger) && method_exists($Logger, 'systemError')) {
                forward_static_call_array([$Logger, 'systemError'], [new \RuntimeException($msg)]);
            } elseif (is_object($Logger) && method_exists($Logger, 'systemError')) {
                $Logger->systemError(new \RuntimeException($msg));
            }
        }
    } catch (\Throwable $_) {}
    respondJson(['ok' => false, 'error' => $msg], 500);
}

// --- helpers: call, resolveTarget, loggerInvoke (same pattern as cart_add/verify) ---
$call = function($target, string $method, array $args = []) {
    try {
        if (is_string($target) && class_exists($target) && method_exists($target, $method)) {
            return forward_static_call_array([$target, $method], $args);
        }
        if (is_object($target) && method_exists($target, $method)) {
            return call_user_func_array([$target, $method], $args);
        }
    } catch (\Throwable $_) {}
    return null;
};

$resolveTarget = function($injected) {
    if (!empty($injected)) {
        if (is_string($injected) && class_exists($injected)) return $injected;
        if (is_object($injected)) return $injected;
    }
    return null;
};

$loggerInvoke = function(?string $method, string $msg, $userId = null, array $ctx = []) use (&$Logger, $call, $resolveTarget) {
    if (empty($Logger)) return;
    try {
        $target = $resolveTarget($Logger);
        if ($target === null) return;
        if ($method === 'systemMessage') {
            if (is_string($target) && method_exists($target, 'systemMessage')) {
                return $call($target, 'systemMessage', [$ctx['level'] ?? 'notice', $msg, $userId, $ctx]);
            }
            if (is_object($target) && method_exists($target, 'systemMessage')) {
                return $target->systemMessage($ctx['level'] ?? 'notice', $msg, $userId, $ctx);
            }
            return;
        }
        if (is_string($target) && method_exists($target, $method)) {
            return $call($target, $method, [$msg, $userId, $ctx]);
        }
        if (is_object($target) && method_exists($target, $method)) {
            return $target->{$method}($msg, $userId, $ctx);
        }
    } catch (\Throwable $_) {}
    return null;
};

// --- assert db has the required interface (strict) ---
$needsDbMethods = ['transaction','fetch','execute'];
$okDb = is_object($db);
if ($okDb) {
    foreach ($needsDbMethods as $m) {
        if (!method_exists($db, $m)) {
            $okDb = false;
            break;
        }
    }
}
if (!$okDb) {
    $loggerInvoke('error', 'cart_clear: injected $db missing required methods', null, []);
    respondJson(['ok' => false, 'error' => 'Interná chyba (DB).'], 500);
}

// --- only allow POST ---
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respondJson(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

// --- parse input (JSON preferred) ---
$input = [];
$contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
if (stripos((string)$contentType, 'application/json') !== false) {
    $raw = file_get_contents('php://input');
    $decoded = json_decode($raw, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        $input = $decoded;
    } else {
        respondJson(['ok' => false, 'error' => 'invalid_json'], 400);
    }
} else {
    $input = $_POST;
}

// --- CSRF validation using injected CSRF (strict) ---
// accepted token locations: X-CSRF-Token header, POST body 'csrf' or JSON 'csrf'
$csrfProvided = null;
if (!empty($_SERVER['HTTP_X_CSRF_TOKEN'])) {
    $csrfProvided = (string) $_SERVER['HTTP_X_CSRF_TOKEN'];
} elseif (!empty($_SERVER['HTTP_X_CSRF'])) {
    $csrfProvided = (string) $_SERVER['HTTP_X_CSRF'];
} elseif (isset($input['csrf'])) {
    $csrfProvided = (string) $input['csrf'];
}

$csrfValid = $call($CSRF, 'validate', [$csrfProvided]);
if ($csrfValid !== true) {
    $loggerInvoke('warn', null, null, ['msg' => 'cart_clear: CSRF validation failed', 'provided' => isset($csrfProvided)]);
    respondJson(['ok' => false, 'error'  => 'Neplatný CSRF token.'], 400);
}

    // --- main logic: clear cart if present ---
    // Note: frontcontroller is expected to have started session if session-backed carts are used.
    $sessionCartId = $_SESSION['cart_id'] ?? null;
    $userId = $user['id'] ?? null;

    // determine cart_id: session first, fallback to user's latest cart
    $cartId = $sessionCartId ?? null;

    if ($cartId === null && $userId !== null) {
        $row = $db->fetch(
            'SELECT id FROM carts WHERE user_id = :uid ORDER BY updated_at DESC LIMIT 1',
            ['uid' => $userId]
        );
        if ($row && !empty($row['id'])) {
            $cartId = $row['id'];
            $_SESSION['cart_id'] = $cartId; // synchronize session with DB cart
        }
    }

    try {
        if (empty($cartId)) {
            // nothing to clear; return empty cart payload
            $cartSummary = [
                'cart_id' => null,
                'items_count' => 0,
                'items_total_qty' => 0,
                'subtotal' => number_format(0, 2, '.', ''),
                'currency' => 'EUR',
                'items' => [],
            ];
            $payload = ['ok' => true, 'cart' => $cartSummary];
            respondJson($payload);
        }

    // transactional delete of cart items and update cart updated_at
    $db->transaction(function($dbtx) use ($cartId) {
        // ensure cart exists
        $exists = $dbtx->fetch('SELECT id FROM carts WHERE id = :id LIMIT 1', ['id' => $cartId]);
        if (!$exists) {
            return;
        }

        // delete items
        $dbtx->execute('DELETE FROM cart_items WHERE cart_id = :cart_id', ['cart_id' => $cartId]);

        // update cart timestamp (keep row)
        $dbtx->execute('UPDATE carts SET updated_at = NOW(6) WHERE id = :id', ['id' => $cartId]);
    });

    // Optionally detach cart_id from session — keep as-is by default.
    // If you prefer to remove session cart_id after clearing, uncomment:
    // unset($_SESSION['cart_id']);
    // $cartId = null;

    // build empty cart summary; currency defaults to EUR (no silent DB lookups after deletion)
    $cartSummary = [
        'cart_id' => $cartId,
        'items_count' => 0,
        'items_total_qty' => 0,
        'subtotal' => number_format(0, 2, '.', ''),
        'currency' => 'EUR',
        'items' => [],
    ];

    $payload = ['ok' => true, 'cart' => $cartSummary];
    respondJson($payload);

} catch (\Throwable $e) {
    $loggerInvoke('systemError', 'cart_clear.exception', $userId ?? null, ['ex' => (string)$e, 'cart_id' => $cartId]);
    respondJson(['ok' => false, 'error' => 'server_error'], 500);
}
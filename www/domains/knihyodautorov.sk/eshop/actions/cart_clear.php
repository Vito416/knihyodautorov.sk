<?php
declare(strict_types=1);

/**
 * cart_clear.php
 *
 * Endpoint: POST /eshop/cart_clear
 * Requires: Database instance available as $db (injected by index.php),
 *           optional $user (injected by index.php), optional $csrfToken.
 *
 * Behavior:
 * - Validates CSRF if possible.
 * - If a session-backed cart exists, removes all cart_items for that cart (transactional).
 * - Updates cart.updated_at if cart row remains.
 * - Optionally unsets session cart_id if cart was deleted.
 * - Returns JSON summary of the (now empty) cart and a fresh csrf_token when available.
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

// --- helpers (kept small & compatible with cart_add.php) ---
function respondJson(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// CSRF validation helper (same behavior as cart_add.php)
function validateCsrfToken(?string $csrfTokenShared, array $parsedInput = []): bool {
    $provided = null;
    if (!empty($_SERVER['HTTP_X_CSRF_TOKEN'])) {
        $provided = (string) $_SERVER['HTTP_X_CSRF_TOKEN'];
    } elseif (!empty($_SERVER['HTTP_X_CSRF'])) {
        $provided = (string) $_SERVER['HTTP_X_CSRF'];
    } elseif (isset($_POST['csrf'])) {
        $provided = (string) $_POST['csrf'];
    } elseif (isset($parsedInput['csrf'])) {
        $provided = (string) $parsedInput['csrf'];
    }

    if ($provided === null || $provided === '') {
        return false;
    }

    if (class_exists('CSRF') && method_exists('CSRF', 'validate')) {
        try {
            return (bool) CSRF::validate($provided);
        } catch (\Throwable $e) {
            try { if (class_exists('Logger')) Logger::warn('CSRF.validate threw', null, ['exception' => (string)$e]); } catch (\Throwable $_) {}
            return false;
        }
    }

    if ($csrfTokenShared !== null && $csrfTokenShared !== '') {
        return hash_equals((string)$csrfTokenShared, (string)$provided);
    }

    return false;
}

// --- begin handler ---
// očekáváme, že index.php injektuje $db (Database instance) a volitelně $user, $csrfToken
/** @var Database $db */
if (!isset($db) || !($db instanceof Database)) {
    try { if (class_exists('Logger')) Logger::error('cart_clear: Database not injected'); } catch (\Throwable $_) {}
    respondJson(['ok' => false, 'error' => 'server_error'], 500);
}

// ensure session started
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

// parse input (JSON body preferred)
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

// CSRF
try {
    $csrfShared = $csrfToken ?? null;
    if (!validateCsrfToken($csrfShared, $input)) {
        respondJson(['ok' => false, 'error' => 'csrf_invalid'], 403);
    }
} catch (\Throwable $e) {
    try { if (class_exists('Logger')) Logger::warn('cart_clear: CSRF validation threw', null, ['exception' => (string)$e]); } catch (\Throwable $_) {}
    respondJson(['ok' => false, 'error' => 'csrf_error'], 403);
}

// main logic: clear cart if present
$sessionCartId = $_SESSION['cart_id'] ?? null;
$userId = $user['id'] ?? null;
$cartId = $sessionCartId ?? null;

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

        // provide fresh CSRF token if available
        $newCsrf = null;
        try { if (class_exists('CSRF')) $newCsrf = CSRF::token(); } catch (\Throwable $_) {}
        $payload = ['ok' => true, 'cart' => $cartSummary];
        if ($newCsrf) $payload['csrf_token'] = $newCsrf;
        respondJson($payload);
    }

    // run transactional delete of cart items and update cart row
    $db->transaction(function(Database $dbtx) use ($cartId, $userId) {
        // optionally verify cart belongs to session/user
        $exists = $dbtx->fetch('SELECT id FROM carts WHERE id = :id LIMIT 1', ['id' => $cartId]);
        if (!$exists) {
            // nothing to do
            return;
        }

        // Delete cart items for this cart
        $dbtx->execute('DELETE FROM cart_items WHERE cart_id = :cart_id', ['cart_id' => $cartId]);

        // Update updated_at timestamp (keep cart row; do not delete cart id)
        $dbtx->execute('UPDATE carts SET updated_at = NOW(6) WHERE id = :id', ['id' => $cartId]);
    });

    // optionally detach cart_id from session: we keep the cart_id so client still references same cart.
    // If you prefer to clear session cart id, uncomment the following lines:
    // unset($_SESSION['cart_id']);
    // $cartId = null;

    // --- safe currency detection (use cart_items.currency if possible, otherwise default to EUR) ---
    $currency = 'EUR';

    if (!empty($cartId)) {
        try {
            // Prefer currency from any existing cart_items for this cart (likely present)
            $rowItem = $db->fetch('SELECT currency FROM cart_items WHERE cart_id = :id LIMIT 1', ['id' => $cartId]);
            if ($rowItem && !empty($rowItem['currency'])) {
                $currency = $rowItem['currency'];
            }
        } catch (\Throwable $ex) {
            // Log for debugging but don't break API contract
            try { if (class_exists('Logger')) Logger::warn('cart_clear: unable to read currency from cart_items', null, ['exception' => (string)$ex, 'cart_id' => $cartId]); } catch (\Throwable $_) {}
            // fallback to default EUR
        }
    }

    $cartSummary = [
        'cart_id' => $cartId,
        'items_count' => 0,
        'items_total_qty' => 0,
        'subtotal' => number_format(0, 2, '.', ''),
        'currency' => $currency,
        'items' => [],
    ];

    // logging
    try {
        if (class_exists('Logger')) {
            Logger::info('cart_clear.success', $cartId, [
                'user_id' => $user['id'] ?? null,
                'cart_id' => $cartId,
            ]);
        }
    } catch (\Throwable $_) {}

    // fresh CSRF token for client (to avoid consume-once issues)
    $newCsrf = null;
    try { if (class_exists('CSRF')) $newCsrf = CSRF::token(); } catch (\Throwable $_) {}

    $payload = ['ok' => true, 'cart' => $cartSummary];
    if ($newCsrf) $payload['csrf_token'] = $newCsrf;
    respondJson($payload);

} catch (\Throwable $e) {
    try { if (class_exists('Logger')) Logger::systemError($e, null, ['phase' => 'cart_clear']); } catch (\Throwable $_) {}
    respondJson(['ok' => false, 'error' => 'server_error'], 500);
}
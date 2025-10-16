<?php
declare(strict_types=1);

/**
 * cart_mini.php (strict, injection-only)
 *
 * Endpoint: GET /eshop/cart_mini
 * Required injected variables (frontcontroller MUST pass):
 *   - Logger (class-string or object with logging methods)
 *   - db     (object with methods: fetch(sql, params), fetchAll(sql, params))
 *
 * Behavior:
 * - Najde aktivní cart podle session (nebo uživatele).
 * - Vrátí JSON souhrn košíku.
 * - Žádné implicitní fallbacky; pokud něco chybí, vrací 500 s chybou.
 */

// pouze GET
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

function respondJson(array $data, int $status = 200): void {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// --- require injected dependencies (strict) ---
$required = ['Logger','db'];
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

// --- helpers: call, resolveTarget, loggerInvoke (same style) ---
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

// --- assert db has required interface (strict) ---
$needsDbMethods = ['fetch','fetchAll'];
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
    $loggerInvoke('error', 'cart_mini: injected $db missing required methods', null, []);
    respondJson(['ok' => false, 'error' => 'Interná chyba (DB).'], 500);
}

// --- main: find cart by session or user ---
// NOTE: frontcontroller MUST have started session if session-backed carts are used.
$sessionCartId = $_SESSION['cart_id'] ?? null;
$userId = $user['id'] ?? null;
$cartId = null;

try {
    if (!empty($sessionCartId)) {
        $exists = $db->fetch('SELECT id FROM carts WHERE id = :id LIMIT 1', ['id' => $sessionCartId]);
        if ($exists) {
            $cartId = $sessionCartId;
        } else {
            // session contains invalid cart id - do not start session here; frontcontroller expected to manage session lifecycle
            unset($_SESSION['cart_id']);
            $cartId = null;
        }
    }

    // fallback: user's most recent cart
    if ($cartId === null && $userId !== null) {
        $row = $db->fetch('SELECT id FROM carts WHERE user_id = :uid ORDER BY updated_at DESC LIMIT 1', ['uid' => $userId]);
        if ($row && !empty($row['id'])) {
            $cartId = $row['id'];
            $_SESSION['cart_id'] = $cartId;
        }
    }
} catch (\Throwable $e) {
    $loggerInvoke('systemError', 'cart_mini: failed to locate cart', $userId ?? null, ['ex' => (string)$e]);
    respondJson(['ok' => false, 'error' => 'server_error'], 500);
}

if ($cartId === null) {
    respondJson([
        'ok' => true,
        'items' => [],
        'items_count' => 0,
        'items_total_qty' => 0,
        'subtotal' => number_format(0, 2, '.', ''),
        'currency' => 'EUR',
    ]);
}

// --- fetch cart items and build mini snapshot ---
try {
    $cartRows = $db->fetchAll('
        SELECT ci.id, ci.book_id, ci.sku, ci.variant, ci.quantity, ci.unit_price, ci.price_snapshot, ci.currency, b.title, b.slug
        FROM cart_items ci
        LEFT JOIN books b ON b.id = ci.book_id
        WHERE ci.cart_id = :cart_id
        ORDER BY ci.id ASC
    ', ['cart_id' => $cartId]);

    $items = [];
    $itemsCountDistinct = 0;
    $itemsTotalQty = 0;
    $subtotal = 0.0;

    foreach ($cartRows as $r) {
        $itemsCountDistinct++;
        $qtyLine = (int)($r['quantity'] ?? 0);
        $itemsTotalQty += $qtyLine;
        $linePrice = (float)($r['price_snapshot'] ?? $r['unit_price'] ?? 0.0) * $qtyLine;
        $subtotal += $linePrice;

        $variantDecoded = null;
        if (!empty($r['variant'])) {
            $decoded = json_decode((string)$r['variant'], true);
            $variantDecoded = json_last_error() === JSON_ERROR_NONE ? $decoded : $r['variant'];
        }

        $items[] = [
            'id' => (int)$r['id'],
            'book_id' => (int)$r['book_id'],
            'slug' => $r['slug'] ?? null,
            'title' => $r['title'] ?? null,
            'sku' => $r['sku'] ?? null,
            'variant' => $variantDecoded,
            'qty' => $qtyLine,
            'unit_price' => number_format((float)($r['unit_price'] ?? 0.0), 2, '.', ''),
            'line_price' => number_format($linePrice, 2, '.', ''),
            'currency' => $r['currency'] ?? null,
        ];
    }

    $currency = 'EUR';
    if (!empty($cartRows) && !empty($cartRows[0]['currency'])) {
        $currency = $cartRows[0]['currency'];
    }

    respondJson([
        'ok' => true,
        'cart_id' => $cartId,
        'items' => $items,
        'items_count' => $itemsCountDistinct,
        'items_total_qty' => $itemsTotalQty,
        'subtotal' => number_format($subtotal, 2, '.', ''),
        'currency' => $currency,
    ]);
} catch (\Throwable $e) {
    $loggerInvoke('systemError', 'cart_mini: failed to read cart items', $userId ?? null, ['ex' => (string)$e, 'cart_id' => $cartId]);
    respondJson(['ok' => false, 'error' => 'server_error'], 500);
}
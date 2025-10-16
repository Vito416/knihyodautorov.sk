<?php
declare(strict_types=1);

/**
 * cart_add.php (strict, injection-only)
 *
 * Endpoint: POST /eshop/cart_add
 * Required injected variables (frontcontroller MUST pass):
 *   - Logger (class-string or object with logging methods)
 *   - CSRF   (class-string or object with validate(token): bool and token(): string)
 *   - db     (object with methods: transaction(callable), fetch(sql, params), fetchAll(sql, params), execute(sql, params))
 *
 * Behavior: podobné původnímu handleru, ale bez fallbacků a bez implicitních globálních záložních cest.
 */

// --- response helper (matching verify.php style) ---
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
    // try to log via injected Logger if present as value (but we treat its absence as part of missing)
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

// --- helpers: call, resolveTarget, loggerInvoke (copied/adapted from verify.php style) ---
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
$needsDbMethods = ['transaction','fetch','fetchAll','execute'];
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
    $loggerInvoke('error', 'cart_add: injected $db missing required methods', null, []);
    respondJson(['ok' => false, 'error' => 'Interná chyba (DB).'], 500);
}

// --- only allow POST ---
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respondJson(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

// --- small utilities ---
function uuidv4(): string {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function toInt(mixed $v, int $default = 0): int {
    if (is_int($v)) return $v;
    if (is_string($v) && preg_match('/^-?\d+$/', $v)) return (int)$v;
    return $default;
}

// --- CSRF validation using injected CSRF (strict) ---
$csrfTokenFromInput = $_POST['csrf'] ?? null;
$csrfValid = $call($CSRF, 'validate', [$csrfTokenFromInput]);
if ($csrfValid !== true) {
    $loggerInvoke('warn', 'cart_add: CSRF validation failed', null, ['provided' => isset($csrfTokenFromInput)]);
    respondJson(['ok' => false, 'error'  => 'Neplatný CSRF token.'], 400);
}

// --- extract payload ---
$bookId = isset($_POST['book_id']) ? toInt($_POST['book_id'], 0) : 0;
$slug = isset($_POST['slug']) ? trim((string)$_POST['slug']) : null;
$qty = max(1, toInt($_POST['qty'] ?? 1, 1));
if ($qty > 999) $qty = 999;
$sku = isset($_POST['sku']) ? (string)$_POST['sku'] : null;
$variant = null;
if (isset($_POST['variant'])) {
    if (is_string($_POST['variant'])) {
        $maybe = json_decode($_POST['variant'], true);
        $variant = (json_last_error() === JSON_ERROR_NONE) ? $maybe : $_POST['variant'];
    } else {
        $variant = $_POST['variant'];
    }
}

// --- fetch book (strict DB calls) ---
try {
    if ($bookId > 0) {
        $book = $db->fetch('SELECT id, price, currency, is_available, stock_quantity, title, slug FROM books WHERE id = :id LIMIT 1', ['id' => $bookId]);
    } elseif ($slug) {
        $book = $db->fetch('SELECT id, price, currency, is_available, stock_quantity, title, slug FROM books WHERE slug = :slug LIMIT 1', ['slug' => $slug]);
    } else {
        respondJson(['ok' => false, 'error' => 'invalid_payload', 'message' => 'book_id or slug required'], 400);
    }
} catch (\Throwable $e) {
    $loggerInvoke('systemError', 'cart_add.book_fetch exception', null, ['ex' => (string)$e]);
    respondJson(['ok' => false, 'error' => 'server_error'], 500);
}

if (empty($book)) {
    respondJson(['ok' => false, 'error' => 'invalid_book'], 400);
}

// --- availability checks ---
if (isset($book['is_available']) && !$book['is_available']) {
    respondJson(['ok' => false, 'error' => 'out_of_stock', 'available' => 0], 409);
}
if (isset($book['stock_quantity']) && is_numeric($book['stock_quantity'])) {
    $available = (int)$book['stock_quantity'];
    if ($available <= 0) {
        respondJson(['ok' => false, 'error' => 'out_of_stock', 'available' => 0], 409);
    }
    if ($qty > $available) {
        respondJson(['ok' => false, 'error' => 'insufficient_stock', 'available' => $available], 409);
    }
}

// --- prepare cart: session-backed, but frontcontroller MUST have started session if needed ---
$sessionCartId = $_SESSION['cart_id'] ?? null;
$cartId = null;
$userId = $user['id'] ?? null;

// --- create or reuse cart (transactional using injected $db) ---
try {
    $cartId = $db->transaction(function($dbtx) use (&$sessionCartId, $userId) {
        // if session cart present and exists, reuse
        if (!empty($sessionCartId)) {
            $exists = $dbtx->fetch('SELECT id FROM carts WHERE id = :id LIMIT 1', ['id' => $sessionCartId]);
            if ($exists) {
                return $sessionCartId;
            }
            // if not found, clear session key (frontcontroller/session management expected)
            unset($_SESSION['cart_id']);
            $sessionCartId = null;
        }

        // try user's most recent cart if logged in
        if ($userId !== null) {
            $row = $dbtx->fetch('SELECT id FROM carts WHERE user_id = :uid ORDER BY updated_at DESC LIMIT 1', ['uid' => $userId]);
            if ($row && !empty($row['id'])) {
                $_SESSION['cart_id'] = $row['id'];
                return $row['id'];
            }
        }

        // create new cart
        $newId = uuidv4();
        $dbtx->execute('INSERT INTO carts (id, user_id, created_at, updated_at) VALUES (:id, :uid, NOW(6), NOW(6))', [
            'id' => $newId,
            'uid' => $userId,
        ]);
        $_SESSION['cart_id'] = $newId;
        return $newId;
    });
} catch (\Throwable $e) {
    $loggerInvoke('systemError', 'cart_add.create_cart exception', $userId ?? null, ['ex' => (string)$e]);
    respondJson(['ok' => false, 'error' => 'server_error'], 500);
}

// --- upsert cart_items (transactional) ---
try {
    $db->transaction(function($dbtx) use ($cartId, $book, $qty, $sku, $variant) {
        $sql = 'SELECT id, quantity FROM cart_items WHERE cart_id = :cart_id AND book_id = :book_id';
        $params = ['cart_id' => $cartId, 'book_id' => $book['id']];
        if ($sku !== null) {
            $sql .= ' AND sku = :sku';
            $params['sku'] = $sku;
        } else {
            $sql .= ' AND (sku IS NULL OR sku = \'\')';
        }

        $existing = $dbtx->fetch($sql . ' LIMIT 1', $params);

        $unitPrice = isset($book['price']) ? number_format((float)$book['price'], 2, '.', '') : '0.00';
        $priceSnapshot = $unitPrice;
        $currency = $book['currency'] ?? 'EUR';

        if ($existing) {
            $newQty = max(1, (int)$existing['quantity'] + (int)$qty);
            $dbtx->execute('UPDATE cart_items SET quantity = :qty, unit_price = :unit_price, price_snapshot = :price_snapshot, currency = :currency, sku = :sku WHERE id = :id', [
                'qty' => $newQty,
                'unit_price' => $unitPrice,
                'price_snapshot' => $priceSnapshot,
                'currency' => $currency,
                'sku' => $sku,
                'id' => $existing['id'],
            ]);
        } else {
            $dbtx->execute('INSERT INTO cart_items (cart_id, book_id, sku, variant, quantity, unit_price, price_snapshot, currency, meta) VALUES (:cart_id, :book_id, :sku, :variant, :quantity, :unit_price, :price_snapshot, :currency, :meta)', [
                'cart_id' => $cartId,
                'book_id' => $book['id'],
                'sku' => $sku,
                'variant' => $variant !== null ? json_encode($variant, JSON_UNESCAPED_UNICODE) : null,
                'quantity' => $qty,
                'unit_price' => $unitPrice,
                'price_snapshot' => $priceSnapshot,
                'currency' => $currency,
                'meta' => null,
            ]);
        }
    });
} catch (\Throwable $e) {
    $loggerInvoke('systemError', 'cart_add.upsert exception', $userId ?? null, ['ex' => (string)$e]);
    respondJson(['ok' => false, 'error' => 'server_error'], 500);
}

// --- build and return cart snapshot ---
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
        $qtyLine = (int)$r['quantity'];
        $itemsTotalQty += $qtyLine;
        $linePrice = (float)$r['price_snapshot'] * $qtyLine;
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
            'unit_price' => number_format((float)$r['unit_price'], 2, '.', ''),
            'line_price' => number_format($linePrice, 2, '.', ''),
            'currency' => $r['currency'] ?? null,
        ];
    }

    $currency = 'EUR';
    if (!empty($cartRows) && !empty($cartRows[0]['currency'])) {
        $currency = $cartRows[0]['currency'];
    } elseif (!empty($book['currency'])) {
        $currency = $book['currency'];
    }

    $cartSummary = [
        'cart_id' => $cartId,
        'items_count' => $itemsCountDistinct,
        'items_total_qty' => $itemsTotalQty,
        'subtotal' => number_format($subtotal, 2, '.', ''),
        'currency' => $currency,
        'items' => $items,
    ];

    $payload = ['ok' => true, 'cart' => $cartSummary];
    respondJson($payload);
} catch (\Throwable $e) {
    $loggerInvoke('systemError', 'cart_add.summary exception', $userId ?? null, ['ex' => (string)$e]);
    respondJson(['ok' => false, 'error' => 'server_error'], 500);
}
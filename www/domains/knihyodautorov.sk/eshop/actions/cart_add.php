<?php
declare(strict_types=1);

/**
 * cart_add.php
 *
 * Endpoint: POST /eshop/cart_add
 * Requires: Database instance available as $db (injected by index.php),
 *           optional $user (injected by index.php), optional $csrfToken.
 *
 * Behavior:
 * - Accepts JSON or form input: { book_id | slug, qty, sku?, variant? }
 * - Validates CSRF if possible, validates payload.
 * - Creates a carts row if none in session, or reuses existing cart.
 * - Upserts cart_items (increase qty if item exists).
 * - Returns JSON summary of cart.
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

// --- helpers ---
function respondJson(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function uuidv4(): string {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); // version 4
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80); // variant
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

// CSRF validation
function validateCsrfToken(?string $csrfTokenShared, array $parsedInput = []): bool {
    // 1) get provided token from common places (header, form, parsed JSON)
    $provided = null;

    // header: X-CSRF-Token (PHP exposes as HTTP_X_CSRF_TOKEN)
    if (!empty($_SERVER['HTTP_X_CSRF_TOKEN'])) {
        $provided = (string) $_SERVER['HTTP_X_CSRF_TOKEN'];
    } elseif (!empty($_SERVER['HTTP_X_CSRF'])) {
        $provided = (string) $_SERVER['HTTP_X_CSRF'];
    } elseif (isset($_POST['csrf'])) {
        $provided = (string) $_POST['csrf'];
    } elseif (isset($parsedInput['csrf'])) {
        $provided = (string) $parsedInput['csrf'];
    }

    // nothing provided -> invalid
    if ($provided === null || $provided === '') {
        return false;
    }

    // prefer class validator if exists
    if (class_exists('CSRF') && method_exists('CSRF', 'validate')) {
        try {
            return (bool) CSRF::validate($provided);
        } catch (\Throwable $e) {
            // if CSRF::validate throws, treat as invalid (logger optional)
            try { if (class_exists('Logger')) Logger::warn('CSRF.validate threw', null, ['exception' => (string)$e]); } catch (\Throwable $_) {}
            return false;
        }
    }

    // fallback: compare with server-side shared token (if available)
    if ($csrfTokenShared !== null && $csrfTokenShared !== '') {
        // use timing-safe comparison
        return hash_equals((string)$csrfTokenShared, (string)$provided);
    }

    // no validator and no shared token -> invalid
    return false;
}

// sanitize integers
function toInt(mixed $v, int $default = 0): int {
    if (is_int($v)) return $v;
    if (is_string($v) && preg_match('/^-?\d+$/', $v)) return (int)$v;
    return $default;
}

// --- begin handler ---
// očekáváme, že index.php injektuje $db (Database instance) a volitelně $user, $csrfToken
/** @var Database $db */
if (!isset($db) || !($db instanceof Database)) {
    // nemáme přístup k DB - fatal
    try { if (class_exists('Logger')) Logger::error('cart_add: Database not injected'); } catch (\Throwable $_) {}
    respondJson(['ok' => false, 'error' => 'server_error'], 500);
}

// ensure session started (bootstrap should start session, ale pojistíme se)
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
    // form-encoded
    $input = $_POST;
}

// CSRF
try {
    $csrfShared = $csrfToken ?? null;
    if (!validateCsrfToken($csrfShared, $input)) {
        respondJson(['ok' => false, 'error' => 'csrf_invalid'], 403);
    }
} catch (\Throwable $e) {
    // bezpečnostní fallback - považovat za neautorizované
    try { if (class_exists('Logger')) Logger::warn('cart_add: CSRF validation threw', null, ['exception' => (string)$e]); } catch (\Throwable $_) {}
    respondJson(['ok' => false, 'error' => 'csrf_error'], 403);
}

// extract and validate payload
$bookId = isset($input['book_id']) ? toInt($input['book_id'], 0) : 0;
$slug = isset($input['slug']) ? trim((string)$input['slug']) : null;
$qty = max(1, toInt($input['qty'] ?? 1, 1));
if ($qty > 999) $qty = 999; // sanity cap
$sku = isset($input['sku']) ? (string)$input['sku'] : null;
$variant = null;
if (isset($input['variant'])) {
    if (is_string($input['variant'])) {
        // try decode JSON variant or keep raw string
        $maybe = json_decode($input['variant'], true);
        $variant = (json_last_error() === JSON_ERROR_NONE) ? $maybe : $input['variant'];
    } else {
        $variant = $input['variant'];
    }
}

// identify book row either by id or slug
try {
    if ($bookId > 0) {
        $book = $db->fetch('SELECT id, price, currency, is_available, stock_quantity FROM books WHERE id = :id LIMIT 1', ['id' => $bookId]);
    } elseif ($slug) {
        $book = $db->fetch('SELECT id, price, currency, is_available, stock_quantity FROM books WHERE slug = :slug LIMIT 1', ['slug' => $slug]);
    } else {
        respondJson(['ok' => false, 'error' => 'invalid_payload', 'message' => 'book_id or slug required'], 400);
    }
} catch (\Throwable $e) {
    try { if (class_exists('Logger')) Logger::systemError($e, null, ['phase' => 'cart_add.book_fetch']); } catch (\Throwable $_) {}
    respondJson(['ok' => false, 'error' => 'server_error'], 500);
}

if (!$book) {
    respondJson(['ok' => false, 'error' => 'invalid_book'], 400);
}

// check availability (we don't reserve stock here; just inform user)
if (isset($book['is_available']) && !$book['is_available']) {
    respondJson(['ok' => false, 'error' => 'out_of_stock', 'available' => 0], 409);
}
if (isset($book['stock_quantity']) && is_numeric($book['stock_quantity'])) {
    $available = (int)$book['stock_quantity'];
    if ($available <= 0) {
        respondJson(['ok' => false, 'error' => 'out_of_stock', 'available' => 0], 409);
    }
    // optionally, cap requested qty to available (but better to inform)
    if ($qty > $available) {
        respondJson(['ok' => false, 'error' => 'insufficient_stock', 'available' => $available], 409);
    }
}

// Prepare cart (session-backed)
$sessionCartId = $_SESSION['cart_id'] ?? null;
$cartId = null;
$userId = $user['id'] ?? null;

try {
    $cartId = $db->transaction(function(Database $dbtx) use (&$sessionCartId, $userId) {
        // Reuse existing session cart if present and exists
        if (!empty($sessionCartId)) {
            $exists = $dbtx->fetch('SELECT id FROM carts WHERE id = :id LIMIT 1', ['id' => $sessionCartId]);
            if ($exists) {
                // Optionally attach to user if user is logged in and cart.user_id IS NULL
                return $sessionCartId;
            } else {
                // session cart id invalid -> clear session key and create new
                unset($_SESSION['cart_id']);
                $sessionCartId = null;
            }
        }

        // Try to find an active cart for user (if user logged in)
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
    try { if (class_exists('Logger')) Logger::systemError($e, null, ['phase' => 'cart_add.create_cart']); } catch (\Throwable $_) {}
    respondJson(['ok' => false, 'error' => 'server_error'], 500);
}

// Upsert cart_items (transactional)
try {
    $db->transaction(function(Database $dbtx) use ($cartId, $book, $qty, $sku, $variant) {
        // find existing line for same cart + book + sku (sku can be NULL)
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
            // update quantity (increment)
            $newQty = max(1, (int)$existing['quantity'] + (int)$qty);
            $dbtx->execute('UPDATE cart_items SET quantity = :qty, unit_price = :unit_price, price_snapshot = :price_snapshot, currency = :currency, meta = JSON_MERGE_PATCH(COALESCE(meta, JSON_OBJECT()), JSON_OBJECT()) , sku = :sku WHERE id = :id', [
                'qty' => $newQty,
                'unit_price' => $unitPrice,
                'price_snapshot' => $priceSnapshot,
                'currency' => $currency,
                'sku' => $sku,
                'id' => $existing['id'],
            ]);
        } else {
            // insert new line
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
    try { if (class_exists('Logger')) Logger::systemError($e, null, ['phase' => 'cart_add.upsert']); } catch (\Throwable $_) {}
    respondJson(['ok' => false, 'error' => 'server_error'], 500);
}

// Build cart snapshot to return
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

    // optional analytics/logging
    try {
        if (class_exists('Logger')) {
            Logger::info('cart_add.success', $cartId, [
                'user_id' => $user['id'] ?? null,
                'added_book_id' => $book['id'],
                'qty' => $qty,
                'cart_summary' => $cartSummary,
            ]);
        }
    } catch (\Throwable $_) {}
    // po úspěšném updatu košíka
    $newCsrf = null;
    try {
        if (class_exists('CSRF')) {
            $newCsrf = CSRF::token();
        }
    } catch (\Throwable $_) { /* ignore */ }

    $payload = ['ok' => true, 'cart' => $cartSummary];
    if ($newCsrf) $payload['csrf_token'] = $newCsrf;
    respondJson($payload);
} catch (\Throwable $e) {
    try { if (class_exists('Logger')) Logger::systemError($e, null, ['phase' => 'cart_add.summary']); } catch (\Throwable $_) {}
    respondJson(['ok' => false, 'error' => 'server_error'], 500);
}
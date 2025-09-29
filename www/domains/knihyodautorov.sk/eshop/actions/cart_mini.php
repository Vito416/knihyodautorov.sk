<?php
declare(strict_types=1);

/**
 * cart_get.php
 *
 * Endpoint: GET /eshop/cart_mini
 * Requires: Database instance available as $db (injected by index.php),
 *           optional $user (injected by index.php).
 *
 * Behavior:
 * - Najde aktivní cart podle session (nebo uživatele).
 * - Vrátí JSON souhrn košíku.
 */

// pouze GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

function respondJson(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/** @var Database $db */
if (!isset($db) || !($db instanceof Database)) {
    respondJson(['ok' => false, 'error' => 'server_error'], 500);
}

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$sessionCartId = $_SESSION['cart_id'] ?? null;
$userId = $user['id'] ?? null;
$cartId = null;

try {
    if (!empty($sessionCartId)) {
        $exists = $db->fetch('SELECT id FROM carts WHERE id = :id LIMIT 1', ['id' => $sessionCartId]);
        if ($exists) {
            $cartId = $sessionCartId;
        } else {
            unset($_SESSION['cart_id']);
        }
    }

    // fallback: user má aktivní cart
    if ($cartId === null && $userId !== null) {
        $row = $db->fetch('SELECT id FROM carts WHERE user_id = :uid ORDER BY updated_at DESC LIMIT 1', ['uid' => $userId]);
        if ($row && !empty($row['id'])) {
            $cartId = $row['id'];
            $_SESSION['cart_id'] = $cartId;
        }
    }
} catch (\Throwable $e) {
    respondJson(['ok' => false, 'error' => 'server_error'], 500);
}

if ($cartId === null) {
    // žádný košík zatím není
    respondJson([
        'ok' => true,
        'items' => [],
        'total_count' => 0,
        'subtotal' => '0.00',
        'currency' => 'EUR'
    ]);
}

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

    respondJson([
        'ok' => true,
        'items' => $items,
        'total_count' => $itemsTotalQty,
        'subtotal' => number_format($subtotal, 2, '.', ''),
        'currency' => $cartRows[0]['currency'] ?? 'EUR'
    ]);

} catch (\Throwable $e) {
    respondJson(['ok' => false, 'error' => 'server_error'], 500);
}
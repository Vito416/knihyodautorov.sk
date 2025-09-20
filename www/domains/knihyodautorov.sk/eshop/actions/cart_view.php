<?php
declare(strict_types=1);

/**
 * actions/cart_view.php
 *
 * - GET endpoint: načíta obsah košíka.
 * - Ak `?format=html` alebo Accept obsahuje "text/html", vygeneruje HTML cez Templates::render('pages/cart.php').
 * - Inak vráti JSON { success, items: [...], subtotal, currency, cart_id, count }
 *
 * Predpoklady:
 *  - require_once __DIR__ . '/../../bootstrap.php' (alebo uprav podľa projektu)
 *  - Database::getInstance()->getPdo()
 *  - Templates::render() (ak chcete HTML output)
 *  - carts + cart_items + books tabulky podľa poskytnutej schémy
 */

try {
    // bootstrap
    require_once __DIR__ . '/../../bootstrap.php';

    // získať PDO
    if (class_exists('Database') && method_exists('Database', 'getInstance')) {
        $pdo = Database::getInstance()->getPdo();
    } elseif (isset($pdo) && $pdo instanceof PDO) {
        // použité lokálne
    } else {
        throw new RuntimeException('Databáza nie je inicializovaná.');
    }

    // detekcia požiadavky na HTML vs JSON
    $wantHtml = false;
    if (isset($_GET['format']) && strtolower((string)$_GET['format']) === 'html') {
        $wantHtml = true;
    } else {
        // check Accept header
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        if (strpos($accept, 'text/html') !== false && strpos($accept, 'application/json') === false) {
            $wantHtml = true;
        }
    }

    // zisti user ID ak prihlásený
    $userId = null;
    if (class_exists('Auth') && method_exists('Auth','currentUserId')) {
        $userId = Auth::currentUserId();
    } elseif (class_exists('SessionManager') && method_exists('SessionManager','getUserId')) {
        $userId = SessionManager::getUserId();
    }
    if ($userId !== null) {
        $userId = (int)$userId;
        if ($userId <= 0) $userId = null;
    }

    // zisti cart id: preferuj user cart, fallback cookie
    $cartId = null;
    if ($userId !== null) {
        $stmt = $pdo->prepare('SELECT id FROM carts WHERE user_id = :uid LIMIT 1');
        $stmt->execute([':uid' => $userId]);
        $cartId = $stmt->fetchColumn() ?: null;
    }
    if (!$cartId) {
        $cookie = $_COOKIE['eshop_cart'] ?? '';
        if ($cookie && preg_match('/^[a-f0-9\-]{36}$/i', $cookie)) {
            // over DB
            $stmt = $pdo->prepare('SELECT id FROM carts WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $cookie]);
            $found = $stmt->fetchColumn();
            if ($found) $cartId = $cookie;
        }
    }

    if (!$cartId) {
        // prázdny košík - render/JSON empty
        $result = [
            'success' => true,
            'cart_id' => null,
            'items' => [],
            'count' => 0,
            'subtotal' => '0.00',
            'currency' => null,
        ];
        if ($wantHtml && class_exists('Templates')) {
            echo Templates::render('pages/cart.php', ['cart' => $result, 'csrf' => (class_exists('CSRF') && method_exists('CSRF','hiddenInput')?CSRF::hiddenInput():'' )]);
            exit;
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result);
        exit;
    }

    // Načítaj položky zo DB: join books a prípadne book_assets (cover) - minimalne books
    $sql = "
        SELECT ci.book_id, ci.quantity, ci.price_snapshot, ci.currency,
               b.title, b.slug, b.is_available, b.stock_quantity, b.description
        FROM cart_items ci
        JOIN books b ON b.id = ci.book_id
        WHERE ci.cart_id = :cart_id
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':cart_id' => $cartId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $items = [];
    $subtotal = 0.0;
    $currency = null;
    foreach ($rows as $r) {
        $price = (float)$r['price_snapshot'];
        $qty = (int)$r['quantity'];
        $lineTotal = $price * $qty;
        $subtotal += $lineTotal;
        $currency = $currency ?? $r['currency'];

        $items[] = [
            'book_id' => (int)$r['book_id'],
            'title' => (string)$r['title'],
            'slug' => (string)$r['slug'],
            'description' => (string)$r['description'],
            'quantity' => $qty,
            'price' => number_format($price, 2, '.', ''),
            'line_total' => number_format($lineTotal, 2, '.', ''),
            'currency' => $r['currency'],
            'is_available' => (bool)$r['is_available'],
            'stock_quantity' => (int)$r['stock_quantity'],
        ];
    }

    $count = 0;
    foreach ($items as $it) $count += (int)$it['quantity'];

    $result = [
        'success' => true,
        'cart_id' => $cartId,
        'items' => $items,
        'count' => $count,
        'subtotal' => number_format($subtotal, 2, '.', ''),
        'currency' => $currency,
    ];

    if ($wantHtml && class_exists('Templates')) {
        // pripravíme dátovú štruktúru pre render
        echo Templates::render('pages/cart.php', ['cart' => $result, 'csrf' => (class_exists('CSRF') && method_exists('CSRF','hiddenInput')?CSRF::hiddenInput():'')]);
        exit;
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result);
    exit;

} catch (\Throwable $e) {
    if (class_exists('Logger') && method_exists('Logger','systemError')) {
        Logger::systemError($e);
    }
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Chyba pri načítaní košíka.']);
    exit;
}

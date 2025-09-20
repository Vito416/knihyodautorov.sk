<?php
declare(strict_types=1);

/**
 * actions/cart_add.php
 *
 * Pridanie položky do košíka (DB-backed).
 *
 * Predpoklady bootstrapu:
 *  - require_once __DIR__ . '/../../bootstrap.php' (alebo uprav podľa svojej štruktúry)
 *  - Database::getInstance()->getPdo() alebo $pdo dostupné
 *  - voliteľne CSRF::validate() alebo CSRF::check() pre validáciu CSRF tokenu
 *  - tabuľky: carts (id CHAR(36), user_id), cart_items (cart_id, book_id, quantity, price_snapshot, currency)
 *
 * Vstup (POST):
 *  - product_id (int) REQUIRED
 *  - quantity (int) optional, default 1
 *  - csrf (string) optional - kontrola ak CSRF helper existuje
 *
 * Vracia JSON: { success: bool, message: string, cart_id?: string }
 */

header('Content-Type: application/json; charset=utf-8');
try {
    // --- bootstrap ---
    // uprav cestu ak potrebné
    require_once __DIR__ . '/../../bootstrap.php';

    // získať PDO
    if (class_exists('Database') && method_exists('Database', 'getInstance')) {
        $pdo = Database::getInstance()->getPdo();
    } elseif (isset($pdo) && $pdo instanceof PDO) {
        // ak už v scope
        // nop
    } else {
        throw new RuntimeException('Databáza nie je inicializovaná (Database::getInstance()).');
    }

    // --- jednoduchá CSRF kontrola (voliteľne) ---
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Nepovolená metóda.']);
        exit;
    }

    // načítaj vstupy
    $productId = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
    $quantity = isset($_POST['quantity']) ? (int)$_POST['quantity'] : 1;
    if ($quantity < 1) $quantity = 1;
    if ($productId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Neplatné ID produktu.']);
        exit;
    }

    // CSRF: ak existuje tvoje CSRF riešenie, použij ho
    if (class_exists('CSRF') && method_exists('CSRF', 'validate')) {
        try {
            CSRF::validate(); // nechá vyhodiť výnimku pri chybe
        } catch (\Throwable $e) {
            echo json_encode(['success' => false, 'message' => 'CSRF token neplatný.']);
            exit;
        }
    } elseif (class_exists('CSRF') && method_exists('CSRF', 'check')) {
        if (!CSRF::check($_POST['csrf'] ?? null)) {
            echo json_encode(['success' => false, 'message' => 'CSRF token neplatný.']);
            exit;
        }
    } // inak nevyžadujeme CSRF (ale odporúčame ho mať)

    // optional: jednoduché rate limit / bruteforce ochrana (ľahká ochrana)
    // (tu ju nepovinne pridávam len ako comment; implementuj podľa infraštruktúry)

    // --- overenie produktu a cena ---
    $stmt = $pdo->prepare('SELECT id, title, price, currency, stock_quantity, is_available FROM books WHERE id = :id AND is_active = 1 LIMIT 1');
    $stmt->execute([':id' => $productId]);
    $book = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$book) {
        echo json_encode(['success' => false, 'message' => 'Produkt neexistuje alebo je neaktívny.']);
        exit;
    }
    if (!(int)$book['is_available']) {
        echo json_encode(['success' => false, 'message' => 'Produkt momentálne nie je dostupný.']);
        exit;
    }
    $stock = (int)$book['stock_quantity'];
    if ($stock > 0 && $quantity > $stock) {
        // ak skladové množstvo >0 a požadované množstvo je väčšie
        echo json_encode(['success' => false, 'message' => 'Nedostatok na sklade. Dostupné: ' . $stock]);
        exit;
    }

    // --- zisti current user (ak existuje) ---
    $userId = null;
    if (class_exists('Auth') && method_exists('Auth', 'currentUserId')) {
        $userId = Auth::currentUserId(); // môže vrátiť null
    } elseif (class_exists('SessionManager') && method_exists('SessionManager', 'getUserId')) {
        $userId = SessionManager::getUserId();
    } elseif (function_exists('get_current_user_id')) {
        $userId = get_current_user_id();
    }
    if ($userId !== null) {
        $userId = (int)$userId;
        if ($userId <= 0) $userId = null;
    }

    // --- zisti alebo vytvor cart_id ---
    // preferujeme cart via user_id ak prihlásený, inak cookie-based cart id (CHAR(36))
    $cartId = null;

    if ($userId !== null) {
        // hľadaj existujúci active cart pre usera (najnovší)
        $stmt = $pdo->prepare('SELECT id FROM carts WHERE user_id = :uid LIMIT 1');
        $stmt->execute([':uid' => $userId]);
        $cartId = $stmt->fetchColumn();
    }

    if (!$cartId) {
        // skús cookie
        $existingCartCookie = $_COOKIE['eshop_cart'] ?? '';
        if ($existingCartCookie && preg_match('/^[a-f0-9\-]{36}$/i', $existingCartCookie)) {
            // overit v DB
            $stmt = $pdo->prepare('SELECT id FROM carts WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $existingCartCookie]);
            $found = $stmt->fetchColumn();
            if ($found) $cartId = $existingCartCookie;
        }
    }

    // ak ešte nemáme cartId -> vytvoríme nový
    if (!$cartId) {
        // vygenerujeme UUID v4 (bez externých dependencií)
        $cartId = bin2hex(random_bytes(16));
        // pre lepšiu čitateľnosť môžeme vložiť pomlčky (36 chars) alebo ponechať 32 hex
        // schéma má CHAR(36), preto pridáme pomlčky do UUIDv4
        $cartId = substr($cartId,0,8) . '-' . substr($cartId,8,4) . '-' . substr($cartId,12,4) . '-' . substr($cartId,16,4) . '-' . substr($cartId,20,12);

        // vlož do DB
        $ins = $pdo->prepare('INSERT INTO carts (id, user_id, created_at, updated_at) VALUES (:id, :uid, NOW(), NOW())');
        $ins->execute([':id' => $cartId, ':uid' => $userId]);
        // nastav cookie pre guest (30 dní)
        if ($userId === null) {
            $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? 0) == 443;
            // setcookie s modernými flagmi (PHP 7.3+ podporuje array options)
            $cookieOptions = [
                'expires' => time() + 60*60*24*30,
                'path' => '/eshop',
                'domain' => $_SERVER['HTTP_HOST'] ?? '',
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ];
            // bezpečný fallback
            if (PHP_VERSION_ID >= 70300) {
                setcookie('eshop_cart', $cartId, $cookieOptions);
            } else {
                // fallback pre staršie PHP (nie ideálne, ale pracujúce)
                setcookie('eshop_cart', $cartId, time() + 60*60*24*30, '/eshop', $cookieOptions['domain'] ?: '', $cookieOptions['secure'], $cookieOptions['httponly']);
            }
        }
    }

    // --- vlož alebo aktualizuj položku v cart_items ---
    $pdo->beginTransaction();
    try {
        // najprv skontrolujeme či už existuje položka pre daný cart/product
        $sel = $pdo->prepare('SELECT quantity FROM cart_items WHERE cart_id = :cart_id AND book_id = :book_id LIMIT 1');
        $sel->execute([':cart_id' => $cartId, ':book_id' => $productId]);
        $existingQty = $sel->fetchColumn();

        // price snapshot a currency z books
        $priceSnapshot = (float)$book['price'];
        $currency = (string)$book['currency'];

        if ($existingQty !== false && $existingQty !== null) {
            $newQty = (int)$existingQty + $quantity;
            // ak stock je >0, obmedz množstvo
            if ($stock > 0 && $newQty > $stock) {
                $newQty = $stock;
            }
            $upd = $pdo->prepare('UPDATE cart_items SET quantity = :qty, price_snapshot = :price, currency = :cur WHERE cart_id = :cart_id AND book_id = :book_id');
            $upd->execute([
                ':qty' => $newQty,
                ':price' => $priceSnapshot,
                ':cur' => $currency,
                ':cart_id' => $cartId,
                ':book_id' => $productId,
            ]);
        } else {
            // vlož novú položku
            $ins = $pdo->prepare('INSERT INTO cart_items (cart_id, book_id, quantity, price_snapshot, currency) VALUES (:cart_id, :book_id, :qty, :price, :cur)');
            $ins->execute([
                ':cart_id' => $cartId,
                ':book_id' => $productId,
                ':qty' => $quantity,
                ':price' => $priceSnapshot,
                ':cur' => $currency,
            ]);
        }

        // aktualizovať updated_at v carts
        $u = $pdo->prepare('UPDATE carts SET updated_at = NOW(), user_id = COALESCE(user_id, :uid) WHERE id = :id');
        $u->execute([':uid' => $userId, ':id' => $cartId]);

        $pdo->commit();

        echo json_encode(['success' => true, 'message' => 'Produkt pridaný do košíka.', 'cart_id' => $cartId]);
        exit;
    } catch (\Throwable $e) {
        $pdo->rollBack();
        // logni chybu ak Logger existuje
        if (class_exists('Logger') && method_exists('Logger','systemError')) {
            Logger::systemError($e);
        }
        echo json_encode(['success' => false, 'message' => 'Chyba pri ukladaní položky do košíka.']);
        exit;
    }

} catch (\Throwable $outer) {
    // globálna chyba
    if (class_exists('Logger') && method_exists('Logger','systemError')) {
        Logger::systemError($outer);
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error.']);
    exit;
}
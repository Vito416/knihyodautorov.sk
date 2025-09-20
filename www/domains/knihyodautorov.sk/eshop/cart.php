<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';

/**
 * /eshop/cart.php
 *
 * Podporované akce (POST):
 *  - action=add    (book_id, qty)
 *  - action=update (book_id, qty) - sets qty or removes if qty<=0
 *  - action=remove (book_id)
 *  - action=clear  (clears session or user's cart)
 *
 * GET: zobrazí obsah košíku
 *
 * Košík funguje dvojsečně:
 *  - pokud je uživatel nepřihlášen -> používáme $_SESSION['cart'] = [bookId => qty]
 *  - pokud je uživatel přihlášen -> persistence v DB (carts / cart_items)
 *    - při přihlášení se session košík sloučí s DB košíkem (merge)
 */

// ---------- Helpers: DB wrapper / PDO detection ----------
$dbWrapper = null;
$pdo = null;
try {
    if (class_exists('Database') && method_exists('Database', 'getInstance')) {
        $dbWrapper = Database::getInstance();
    } elseif (isset($pdo) && $pdo instanceof \PDO) {
        $pdo = $pdo;
    } elseif (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof \PDO) {
        $pdo = $GLOBALS['pdo'];
    } else {
        throw new \RuntimeException('Database connection not available.');
    }
} catch (\Throwable $e) {
    if (class_exists('Logger')) { try { Logger::systemError($e); } catch (\Throwable $_) {} }
    http_response_code(500);
    echo Templates::render('pages/error.php', ['message' => 'Internal server error (DB).']);
    exit;
}

$exec = function(string $sql, array $params = []) use ($dbWrapper, $pdo) {
    if ($dbWrapper !== null && method_exists($dbWrapper, 'execute')) {
        return $dbWrapper->execute($sql, $params);
    }
    $stmt = $pdo->prepare($sql);
    if ($stmt === false) throw new \RuntimeException('PDO prepare failed');
    foreach ($params as $k => $v) {
        $name = (strpos((string)$k, ':') === 0) ? $k : ':' . $k;
        if (is_int($v)) $stmt->bindValue($name, $v, \PDO::PARAM_INT);
        elseif (is_bool($v)) $stmt->bindValue($name, $v ? 1 : 0, \PDO::PARAM_INT);
        elseif ($v === null) $stmt->bindValue($name, null, \PDO::PARAM_NULL);
        else $stmt->bindValue($name, (string)$v, \PDO::PARAM_STR);
    }
    $stmt->execute();
    return $stmt;
};

$fetchOne = function(string $sql, array $params = []) use ($dbWrapper, $pdo) : ?array {
    if ($dbWrapper !== null && method_exists($dbWrapper, 'fetch')) {
        $r = $dbWrapper->fetch($sql, $params);
        return $r === false ? null : $r;
    }
    $stmt = $pdo->prepare($sql);
    if ($stmt === false) throw new \RuntimeException('PDO prepare failed');
    foreach ($params as $k => $v) {
        $name = (strpos((string)$k, ':') === 0) ? $k : ':' . $k;
        if (is_int($v)) $stmt->bindValue($name, $v, \PDO::PARAM_INT);
        elseif (is_bool($v)) $stmt->bindValue($name, $v ? 1 : 0, \PDO::PARAM_INT);
        elseif ($v === null) $stmt->bindValue($name, null, \PDO::PARAM_NULL);
        else $stmt->bindValue($name, (string)$v, \PDO::PARAM_STR);
    }
    $stmt->execute();
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
};

$fetchAll = function(string $sql, array $params = []) use ($dbWrapper, $pdo) : array {
    if ($dbWrapper !== null && method_exists($dbWrapper, 'fetchAll')) {
        return (array)$dbWrapper->fetchAll($sql, $params);
    }
    $stmt = $pdo->prepare($sql);
    if ($stmt === false) throw new \RuntimeException('PDO prepare failed');
    foreach ($params as $k => $v) {
        $name = (strpos((string)$k, ':') === 0) ? $k : ':' . $k;
        if (is_int($v)) $stmt->bindValue($name, $v, \PDO::PARAM_INT);
        elseif (is_bool($v)) $stmt->bindValue($name, $v ? 1 : 0, \PDO::PARAM_INT);
        elseif ($v === null) $stmt->bindValue($name, null, \PDO::PARAM_NULL);
        else $stmt->bindValue($name, (string)$v, \PDO::PARAM_STR);
    }
    $stmt->execute();
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    return $rows === false ? [] : $rows;
};

// ---------- Session & user ----------
try {
    if (class_exists('SessionManager') && method_exists('SessionManager', 'validateSession')) {
        // validateSession() interně volá session_start() a nastaví $_SESSION
        $currentUserId = SessionManager::validateSession($dbWrapper ?? $pdo);
    } else {
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        $currentUserId = $_SESSION['user_id'] ?? null;
    }
} catch (\Throwable $e) {
    if (class_exists('Logger')) { try { Logger::systemError($e); } catch (\Throwable $_) {} }
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $currentUserId = $_SESSION['user_id'] ?? null;
}

// ensure session cart exists
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) $_SESSION['cart'] = [];

/**
 * UUID generator v4 (string) -> used for carts.id CHAR(36)
 */
function uuid_v4(): string {
    $data = random_bytes(16);
    // set version to 0100
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    // set bits 6-7 to 10
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    $hex = bin2hex($data);
    return sprintf('%s-%s-%s-%s-%s',
        substr($hex, 0, 8),
        substr($hex, 8, 4),
        substr($hex, 12, 4),
        substr($hex, 16, 4),
        substr($hex, 20, 12)
    );
}

/**
 * Get or create DB cart for given user_id.
 * Returns cart_id (string uuid).
 */
function getOrCreateDbCart($userId, $exec, $fetchOne) : string {
    // look for existing non-revoked cart for user
    $row = $fetchOne('SELECT id FROM carts WHERE user_id = :user_id ORDER BY updated_at DESC LIMIT 1', ['user_id' => $userId]);
    if ($row && !empty($row['id'])) return (string)$row['id'];
    // create new cart
    $cartId = uuid_v4();
    $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    $sql = 'INSERT INTO carts (id, user_id, created_at, updated_at) VALUES (:id, :user_id, :created_at, :updated_at)';
    $exec($sql, ['id' => $cartId, 'user_id' => $userId, 'created_at' => $now, 'updated_at' => $now]);
    return $cartId;
}

/**
 * Merge session cart into DB cart for user. Uses price snapshot from books table.
 */
function mergeSessionCartIntoDb($sessionCart, $userId, $exec, $fetchOne, $fetchAll) {
    if (empty($sessionCart)) return;
    $cartId = getOrCreateDbCart($userId, $exec, $fetchOne);

    // For each book, upsert into cart_items: add quantities
    foreach ($sessionCart as $bookId => $qty) {
        $bookRow = $fetchOne('SELECT id, price, currency, is_available, stock_quantity FROM books WHERE id = :id AND is_active = 1 LIMIT 1', ['id' => $bookId]);
        if (!$bookRow) continue; // skip invalid
        $qty = max(1, (int)$qty);
        // if not available or out of stock, skip adding
        if (((int)$bookRow['is_available'] ?? 0) !== 1) continue;
        if ((int)$bookRow['stock_quantity'] < 1) continue;

        // check existing
        $exists = $fetchOne('SELECT quantity FROM cart_items WHERE cart_id = :cart_id AND book_id = :book_id', ['cart_id' => $cartId, 'book_id' => $bookId]);
        if ($exists) {
            $newQty = (int)$exists['quantity'] + $qty;
            $exec('UPDATE cart_items SET quantity = :qty, price_snapshot = :price WHERE cart_id = :cart_id AND book_id = :book_id', [
                'qty' => $newQty, 'price' => $bookRow['price'], 'cart_id' => $cartId, 'book_id' => $bookId
            ]);
        } else {
            $exec('INSERT INTO cart_items (cart_id, book_id, quantity, price_snapshot, currency) VALUES (:cart_id, :book_id, :qty, :price, :currency)', [
                'cart_id' => $cartId, 'book_id' => $bookId, 'qty' => $qty, 'price' => $bookRow['price'], 'currency' => $bookRow['currency']
            ]);
        }
    }

    // clear session cart
    $_SESSION['cart'] = [];
    // update cart updated_at
    $exec('UPDATE carts SET updated_at = NOW() WHERE id = :id', ['id' => $cartId]);
}

// If user just logged in and there is session cart, merge it.
if ($currentUserId !== null && !empty($_SESSION['cart'])) {
    try {
        // attempt to merge in transaction
        if ($dbWrapper !== null && method_exists($dbWrapper, 'beginTransaction')) {
            $dbWrapper->beginTransaction();
            mergeSessionCartIntoDb($_SESSION['cart'], $currentUserId, $exec, $fetchOne, $fetchAll);
            $dbWrapper->commit();
        } else {
            // PDO path
            $pdo->beginTransaction();
            mergeSessionCartIntoDb($_SESSION['cart'], $currentUserId, $exec, $fetchOne, $fetchAll);
            $pdo->commit();
        }
    } catch (\Throwable $e) {
        if ($dbWrapper !== null && method_exists($dbWrapper, 'rollback')) {
            try { $dbWrapper->rollback(); } catch (\Throwable $_) {}
        } else {
            try { $pdo->rollBack(); } catch (\Throwable $_) {}
        }
        if (class_exists('Logger')) { try { Logger::systemError($e, $currentUserId); } catch (\Throwable $_) {} }
    }
}

// ---------- handle POST actions ----------
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'POST') {
    // optional CSRF protection if available
    if (class_exists('CSRF') && method_exists('CSRF', 'validate')) {
        try {
            if (!CSRF::validate($_POST['csrf'] ?? null)) {
                // fail silently and redirect back
                if (class_exists('Logger')) { try { Logger::systemMessage('warning', 'csrf_failed', $currentUserId); } catch (\Throwable $_) {} }
                header('Location: ?route=cart'); exit;
            }
        } catch (\Throwable $_) {}
    }

    $action = $_POST['action'] ?? null;
    $bookId = isset($_POST['book_id']) ? (int)$_POST['book_id'] : null;
    $qty = isset($_POST['qty']) ? (int)$_POST['qty'] : 1;

    try {
        if ($action === 'add' && $bookId) {
            // verify book
            $book = $fetchOne('SELECT id, price, currency, is_active, is_available, stock_quantity FROM books WHERE id = :id LIMIT 1', ['id' => $bookId]);
            if (!$book || (int)$book['is_active'] !== 1) {
                // invalid book - ignore
                header('Location: ?route=cart'); exit;
            }
            if ((int)$book['is_available'] !== 1) {
                // cannot add unavailable
                header('Location: ?route=cart'); exit;
            }

            $qty = max(1, $qty);
            // if user logged in -> persist to DB
            if ($currentUserId !== null) {
                // ensure cart exists
                $cartId = getOrCreateDbCart($currentUserId, $exec, $fetchOne);
                // upsert item
                $exists = $fetchOne('SELECT quantity FROM cart_items WHERE cart_id = :cart_id AND book_id = :book_id', ['cart_id' => $cartId, 'book_id' => $bookId]);
                if ($exists) {
                    $newQty = (int)$exists['quantity'] + $qty;
                    $exec('UPDATE cart_items SET quantity = :qty, price_snapshot = :price WHERE cart_id = :cart_id AND book_id = :book_id', [
                        'qty' => $newQty, 'price' => $book['price'], 'cart_id' => $cartId, 'book_id' => $bookId
                    ]);
                } else {
                    $exec('INSERT INTO cart_items (cart_id, book_id, quantity, price_snapshot, currency) VALUES (:cart_id, :book_id, :qty, :price, :currency)', [
                        'cart_id' => $cartId, 'book_id' => $bookId, 'qty' => $qty, 'price' => $book['price'], 'currency' => $book['currency']
                    ]);
                }
                $exec('UPDATE carts SET updated_at = NOW() WHERE id = :id', ['id' => $cartId]);
            } else {
                // session cart
                $_SESSION['cart'][(int)$bookId] = (($_SESSION['cart'][(int)$bookId] ?? 0) + $qty);
            }

            if (class_exists('Logger')) { try { Logger::systemMessage('info', 'cart_add', $currentUserId, ['book' => $bookId, 'qty' => $qty]); } catch (\Throwable $_) {} }
            header('Location: ?route=cart'); exit;
        }

        if ($action === 'update' && $bookId) {
            $qty = max(0, $qty);
            if ($currentUserId !== null) {
                $cartId = getOrCreateDbCart($currentUserId, $exec, $fetchOne);
                if ($qty <= 0) {
                    $exec('DELETE FROM cart_items WHERE cart_id = :cart_id AND book_id = :book_id', ['cart_id' => $cartId, 'book_id' => $bookId]);
                } else {
                    // update to requested qty
                    $bookRow = $fetchOne('SELECT price FROM books WHERE id = :id', ['id' => $bookId]);
                    $price = $bookRow['price'] ?? 0;
                    $exec('INSERT INTO cart_items (cart_id, book_id, quantity, price_snapshot, currency)
                          VALUES (:cart_id, :book_id, :qty, :price, :currency)
                          ON DUPLICATE KEY UPDATE quantity = :qty_upd, price_snapshot = :price_upd',
                          ['cart_id' => $cartId, 'book_id' => $bookId, 'qty' => $qty, 'price' => $price, 'currency' => $bookRow['currency'] ?? 'EUR', 'qty_upd' => $qty, 'price_upd' => $price]);
                }
                $exec('UPDATE carts SET updated_at = NOW() WHERE id = :id', ['id' => $cartId]);
            } else {
                if ($qty <= 0) {
                    unset($_SESSION['cart'][(int)$bookId]);
                } else {
                    $_SESSION['cart'][(int)$bookId] = $qty;
                }
            }
            header('Location: ?route=cart'); exit;
        }

        if ($action === 'remove' && $bookId) {
            if ($currentUserId !== null) {
                $cartId = getOrCreateDbCart($currentUserId, $exec, $fetchOne);
                $exec('DELETE FROM cart_items WHERE cart_id = :cart_id AND book_id = :book_id', ['cart_id' => $cartId, 'book_id' => $bookId]);
                $exec('UPDATE carts SET updated_at = NOW() WHERE id = :id', ['id' => $cartId]);
            } else {
                unset($_SESSION['cart'][(int)$bookId]);
            }
            header('Location: ?route=cart'); exit;
        }

        if ($action === 'clear') {
            if ($currentUserId !== null) {
                $cartId = getOrCreateDbCart($currentUserId, $exec, $fetchOne);
                $exec('DELETE FROM cart_items WHERE cart_id = :cart_id', ['cart_id' => $cartId]);
                $exec('UPDATE carts SET updated_at = NOW() WHERE id = :id', ['id' => $cartId]);
            } else {
                $_SESSION['cart'] = [];
            }
            header('Location: ?route=cart'); exit;
        }
    } catch (\Throwable $e) {
        if (class_exists('Logger')) { try { Logger::systemError($e, $currentUserId ?? null); } catch (\Throwable $_) {} }
        // generic fallback
        header('Location: ?route=cart'); exit;
    }
}

// ---------- build view data ----------

// If user logged in -> load cart from DB, else from session
$items = [];
$total = 0.0;
if ($currentUserId !== null) {
    // load cart id
    $cartRow = $fetchOne('SELECT id FROM carts WHERE user_id = :user_id ORDER BY updated_at DESC LIMIT 1', ['user_id' => $currentUserId]);
    if ($cartRow && !empty($cartRow['id'])) {
        $cartId = $cartRow['id'];
        $rows = $fetchAll('SELECT ci.book_id, ci.quantity, ci.price_snapshot, ci.currency, b.title, b.slug, b.is_available, b.stock_quantity
                           FROM cart_items ci
                           JOIN books b ON b.id = ci.book_id
                           WHERE ci.cart_id = :cart_id', ['cart_id' => $cartId]);
        foreach ($rows as $r) {
            $line = (float)$r['price_snapshot'] * (int)$r['quantity'];
            $items[] = [
                'book' => ['id' => (int)$r['book_id'], 'title' => $r['title'], 'slug' => $r['slug'], 'is_available' => ((int)$r['is_available'] === 1), 'stock_quantity' => (int)$r['stock_quantity']],
                'qty' => (int)$r['quantity'],
                'line_total' => $line,
                'price_snapshot' => (float)$r['price_snapshot'],
                'currency' => $r['currency'],
            ];
            $total += $line;
        }
    } else {
        // no cart yet
        $items = [];
        $total = 0.0;
    }
} else {
    // session cart -> load book data
    $sessCart = $_SESSION['cart'] ?? [];
    if (!empty($sessCart)) {
        $ids = array_map('intval', array_keys($sessCart));
        // fetch book data
        $in = implode(',', array_fill(0, count($ids), '?'));
        $sql = 'SELECT id, title, slug, price, currency, is_available, stock_quantity FROM books WHERE id IN (' . $in . ') AND is_active = 1';
        // PDO prepared statement path
        if ($dbWrapper !== null && method_exists($dbWrapper, 'fetchAll')) {
            // wrapper path: fetchAll with numeric array -> convert keys
            $placeholders = [];
            $paramsMap = [];
            for ($i = 0; $i < count($ids); $i++) { $placeholders[] = ':id' . $i; $paramsMap['id' . $i] = $ids[$i]; }
            $sql2 = 'SELECT id, title, slug, price, currency, is_available, stock_quantity FROM books WHERE id IN (' . implode(',', array_keys($paramsMap)) . ') AND is_active = 1';
            $rows = $dbWrapper->fetchAll($sql2, $paramsMap);
        } else {
            $stmt = $pdo->prepare($sql);
            foreach ($ids as $i => $val) { $stmt->bindValue($i+1, $val, \PDO::PARAM_INT); }
            $stmt->execute();
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        }
        $byId = [];
        foreach ($rows as $r) $byId[(int)$r['id']] = $r;
        foreach ($sessCart as $bookId => $qty) {
            $b = $byId[(int)$bookId] ?? null;
            if (!$b) continue;
            $line = (float)$b['price'] * (int)$qty;
            $items[] = [
                'book' => ['id' => (int)$b['id'], 'title' => $b['title'], 'slug' => $b['slug'], 'is_available' => ((int)$b['is_available'] === 1), 'stock_quantity' => (int)$b['stock_quantity']],
                'qty' => (int)$qty,
                'line_total' => $line,
                'price_snapshot' => (float)$b['price'],
                'currency' => $b['currency'],
            ];
            $total += $line;
        }
    }
}

// render page
try {
    echo Templates::render('pages/cart.php', ['items' => $items, 'total' => $total]);
} catch (\Throwable $e) {
    if (class_exists('Logger')) { try { Logger::systemError($e, $currentUserId ?? null); } catch (\Throwable $_) {} }
    http_response_code(500);
    echo Templates::render('pages/error.php', ['message' => 'Unable to render cart']);
    exit;
}
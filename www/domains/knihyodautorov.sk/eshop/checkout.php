<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';

/**
 * /eshop/checkout.php
 *
 * - Zpracuje POST (place order) nebo GET (zobrazí checkout form)
 * - Vytvoří objednávku z košíku (session nebo DB)
 * - Vypočte subtotal, tax_total podle tabulky tax_rates (category ebook/physical)
 * - Vytvoří payment záznam a vrátí redirect URL ke gateway (GoPay/Square stubs)
 * - Pokud total == 0 => označí jako paid a vygeneruje download tokeny pro e-knihy
 *
 * Používá: Templates, Validator (když existuje), Logger, SessionManager, Database/PDO
 */

// ---------- DB detection ----------
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

// helpers: exec, fetchOne, fetchAll (works with Database wrapper or PDO)
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

// ---------- session & user ----------
try {
    if (class_exists('SessionManager') && method_exists('SessionManager', 'validateSession')) {
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

/* -------------------------
 * Helpers for checkout logic
 * ------------------------ */

/**
 * Determine tax rate for a book and country.
 * Strategy:
 *  - If there exists a book_assets asset of type 'pdf' -> category = 'ebook' else 'physical'
 *  - Query tax_rates for country_iso2 & category with valid_from <= today -> pick latest valid_from
 *  - Fallback: return 0.0
 */
function getTaxRateForBook($bookId, string $countryIso2, $fetchOne, $fetchAll): float {
    try {
        // check if pdf exists for this book (non-deleted)
        $r = $fetchOne('SELECT COUNT(*) AS cnt FROM book_assets WHERE book_id = :book_id AND asset_type = \'pdf\'', ['book_id' => $bookId]);
        $isEbook = ($r && (int)$r['cnt'] > 0);

        $category = $isEbook ? 'ebook' : 'physical';

        // find applicable tax rate by valid_from <= today ordered by valid_from desc
        $today = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');
        $sql = 'SELECT rate FROM tax_rates WHERE country_iso2 = :country AND category = :cat AND valid_from <= :today ORDER BY valid_from DESC LIMIT 1';
        $row = $fetchOne($sql, ['country' => $countryIso2, 'cat' => $category, 'today' => $today]);
        if ($row && isset($row['rate'])) {
            return (float)$row['rate'];
        }
    } catch (\Throwable $e) {
        if (class_exists('Logger')) { try { Logger::systemError($e); } catch (\Throwable $_) {} }
    }
    return 0.0;
}

/**
 * create download token (base64url)
 */
function genDownloadToken(int $len = 24): string {
    $bytes = random_bytes($len);
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

/**
 * create order_item_downloads entries for a paid order (ebook assets)
 */
function grantDownloadsForOrder(int $orderId, array $orderItems, $exec, $fetchOne, $fetchAll) {
    // For each order_item check for ebook assets and create tokens
    foreach ($orderItems as $oi) {
        $bookId = (int)$oi['book_id'];
        // find ebook assets for book
        $assets = $fetchAll('SELECT id, is_encrypted, download_filename, key_id FROM book_assets WHERE book_id = :book_id AND asset_type = \'pdf\'', ['book_id' => $bookId]);
        foreach ($assets as $a) {
            $token = genDownloadToken(24);
            $expiresAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+30 days')->format('Y-m-d H:i:s');
            // default max uses 3 (can be configured)
            $maxUses = 3;
            $exec('INSERT INTO order_item_downloads (order_id, book_id, asset_id, download_token, encryption_key_version, token_key_version, max_uses, used, expires_at) VALUES
                  (:order_id, :book_id, :asset_id, :token, :enc_ver, :tok_ver, :max_uses, 0, :expires_at)', [
                'order_id' => $orderId,
                'book_id' => $bookId,
                'asset_id' => $a['id'],
                'token' => $token,
                'enc_ver' => $a['key_id'] ?? null,
                'tok_ver' => null,
                'max_uses' => $maxUses,
                'expires_at' => $expiresAt,
            ]);
        }
    }
}

/**
 * Build payment redirect URL for a gateway (very small adapter).
 * In production you should use official SDKs and signed requests.
 * Returns ['ok'=>true,'redirect_url'=>string,'transaction_id'=>string] or ['ok'=>false,'error'=>'...']
 */
function initiatePaymentGateway(string $gateway, array $meta, array $config): array {
    $gateway = strtolower($gateway);
    $txId = bin2hex(random_bytes(8));
    if ($gateway === 'gopay') {
        // try to use config['payments']['gopay']['checkout_url'] or default stub
        $base = $config['payments']['gopay']['checkout_url'] ?? ($config['app_url'] ?? '') . '/gopay/checkout';
        // build query (in real: sign, provide callback URLs)
        $query = http_build_query([
            'tx' => $txId,
            'amount' => $meta['amount'],
            'currency' => $meta['currency'],
            'order_id' => $meta['order_id'],
            'return_url' => $meta['return_url'] ?? ($config['app_url'] ?? '') . '/eshop/?route=gopay_callback',
            'notify_url' => $meta['notify_url'] ?? ($config['app_url'] ?? '') . '/eshop/?route=gopay_callback',
        ]);
        return ['ok' => true, 'redirect_url' => rtrim($base, '/') . '?' . $query, 'transaction_id' => $txId];
    }
    if ($gateway === 'square' || $gateway === 'pay_by_square') {
        // Pay-by-square is usually a QR for bank transfer. We'll return a stub link or directly show QR later.
        $base = $config['payments']['square']['checkout_url'] ?? ($config['app_url'] ?? '') . '/square/checkout';
        $query = http_build_query([
            'tx' => $txId,
            'amount' => $meta['amount'],
            'currency' => $meta['currency'],
            'order_id' => $meta['order_id'],
        ]);
        return ['ok' => true, 'redirect_url' => rtrim($base, '/') . '?' . $query, 'transaction_id' => $txId];
    }
    return ['ok' => false, 'error' => 'unsupported_gateway'];
}

// ---------- handle POST: place order ----------
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'POST') {
    // CSRF check if available
    if (class_exists('CSRF') && method_exists('CSRF', 'validate')) {
        try {
            if (!CSRF::validate($_POST['csrf'] ?? null)) {
                if (class_exists('Logger')) { try { Logger::systemMessage('warning', 'csrf_failed', $currentUserId ?? null); } catch (\Throwable $_) {} }
                header('Location: ?route=checkout'); exit;
            }
        } catch (\Throwable $_) {}
    }

    // collect billing info
    $bill_full_name = trim((string)($_POST['bill_full_name'] ?? ''));
    $bill_company = trim((string)($_POST['bill_company'] ?? ''));
    $bill_street = trim((string)($_POST['bill_street'] ?? ''));
    $bill_city = trim((string)($_POST['bill_city'] ?? ''));
    $bill_zip = trim((string)($_POST['bill_zip'] ?? ''));
    $bill_country = strtoupper(trim((string)($_POST['bill_country'] ?? '')));
    $bill_tax_id = trim((string)($_POST['bill_tax_id'] ?? ''));
    $bill_vat_id = trim((string)($_POST['bill_vat_id'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ($_SESSION['email'] ?? '')));
    $gateway = trim((string)($_POST['gateway'] ?? ($_POST['payment_method'] ?? 'gopay')));
    $gateway = $gateway === '' ? 'gopay' : $gateway;

    // minimal validation
    if ($bill_full_name === '' || $email === '' || $bill_country === '') {
        // required fields missing
        try { echo Templates::render('pages/checkout.php', ['error' => 'Vyplňte jméno, e-mail a zemi.', 'posted' => $_POST]); } catch (\Throwable $e) { echo 'Missing fields'; }
        exit;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        try { echo Templates::render('pages/checkout.php', ['error' => 'Neplatný e-mail.', 'posted' => $_POST]); } catch (\Throwable $e) { echo 'Invalid email'; }
        exit;
    }

    // collect cart items (same logic as cart.php)
    $cartItems = [];
    if ($currentUserId !== null) {
        $cartRow = $fetchOne('SELECT id FROM carts WHERE user_id = :user_id ORDER BY updated_at DESC LIMIT 1', ['user_id' => $currentUserId]);
        if ($cartRow && !empty($cartRow['id'])) {
            $cartId = $cartRow['id'];
            $rows = $fetchAll('SELECT ci.book_id, ci.quantity, ci.price_snapshot, ci.currency, b.title, b.slug, b.is_available, b.stock_quantity
                           FROM cart_items ci
                           JOIN books b ON b.id = ci.book_id
                           WHERE ci.cart_id = :cart_id', ['cart_id' => $cartId]);
            foreach ($rows as $r) {
                $cartItems[] = [
                    'book_id' => (int)$r['book_id'],
                    'qty' => (int)$r['quantity'],
                    'price_snapshot' => (float)$r['price_snapshot'],
                    'currency' => $r['currency'],
                    'title' => $r['title'],
                ];
            }
        }
    } else {
        $sessCart = $_SESSION['cart'] ?? [];
        if (!empty($sessCart)) {
            $ids = array_map('intval', array_keys($sessCart));
            if (!empty($ids)) {
                // fetch books
                $in = implode(',', array_fill(0, count($ids), '?'));
                $sql = 'SELECT id, title, slug, price, currency, is_available, stock_quantity FROM books WHERE id IN (' . $in . ') AND is_active = 1';
                $stmt = $pdo->prepare($sql);
                foreach ($ids as $i => $val) $stmt->bindValue($i+1, $val, \PDO::PARAM_INT);
                $stmt->execute();
                $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                $byId = [];
                foreach ($rows as $r) $byId[(int)$r['id']] = $r;
                foreach ($sessCart as $bookId => $qty) {
                    $b = $byId[(int)$bookId] ?? null;
                    if (!$b) continue;
                    $cartItems[] = [
                        'book_id' => (int)$b['id'],
                        'qty' => (int)$qty,
                        'price_snapshot' => (float)$b['price'],
                        'currency' => $b['currency'],
                        'title' => $b['title'],
                    ];
                }
            }
        }
    }

    if (empty($cartItems)) {
        try { echo Templates::render('pages/checkout.php', ['error' => 'Košík je prázdný.']); } catch (\Throwable $e) { echo 'Empty cart'; }
        exit;
    }

    // compute totals: subtotal, tax_total per item (use tax_rates)
    $subtotal = 0.0;
    $tax_total = 0.0;
    $currency = $cartItems[0]['currency'] ?? 'EUR';
    $orderItemsForInsert = [];

    foreach ($cartItems as $ci) {
        $line = $ci['price_snapshot'] * $ci['qty'];
        $subtotal += $line;
        // determine tax rate
        $rate = getTaxRateForBook((int)$ci['book_id'], $bill_country, $fetchOne, $fetchAll);
        $tax = $line * ($rate / 100.0);
        $tax_total += $tax;

        $orderItemsForInsert[] = [
            'book_id' => (int)$ci['book_id'],
            'title_snapshot' => $ci['title'],
            'unit_price' => (float)$ci['price_snapshot'],
            'quantity' => (int)$ci['qty'],
            'tax_rate' => $rate,
            'currency' => $ci['currency'],
        ];
    }

    $discount_total = 0.0; // coupon handling not implemented here
    $total = round($subtotal + $tax_total - $discount_total, 2);

    // persist order + order_items + payment inside transaction
    try {
        if ($dbWrapper !== null && method_exists($dbWrapper, 'beginTransaction')) {
            $dbWrapper->beginTransaction();
        } else {
            $pdo->beginTransaction();
        }

        // insert order
        $sqlOrder = 'INSERT INTO orders (user_id, status, bill_full_name, bill_company, bill_street, bill_city, bill_zip, bill_country_code, bill_tax_id, bill_vat_id, currency, subtotal, discount_total, tax_total, total, payment_method, created_at, updated_at)
                     VALUES (:user_id, :status, :bill_full_name, :bill_company, :bill_street, :bill_city, :bill_zip, :bill_country, :bill_tax_id, :bill_vat_id, :currency, :subtotal, :discount_total, :tax_total, :total, :payment_method, NOW(), NOW())';
        $stmt = $exec($sqlOrder, [
            'user_id' => $currentUserId,
            'status' => 'pending',
            'bill_full_name' => $bill_full_name,
            'bill_company' => $bill_company ?: null,
            'bill_street' => $bill_street ?: null,
            'bill_city' => $bill_city ?: null,
            'bill_zip' => $bill_zip ?: null,
            'bill_country' => $bill_country,
            'bill_tax_id' => $bill_tax_id ?: null,
            'bill_vat_id' => $bill_vat_id ?: null,
            'currency' => $currency,
            'subtotal' => $subtotal,
            'discount_total' => $discount_total,
            'tax_total' => $tax_total,
            'total' => $total,
            'payment_method' => $gateway,
        ]);
        // get last insert id
        $orderId = (int) ($dbWrapper !== null && method_exists($dbWrapper, 'lastInsertId') ? $dbWrapper->lastInsertId() : $pdo->lastInsertId());

        // insert order_items
        foreach ($orderItemsForInsert as $oi) {
            $exec('INSERT INTO order_items (order_id, book_id, title_snapshot, unit_price, quantity, tax_rate, currency) VALUES
                  (:order_id, :book_id, :title_snapshot, :unit_price, :quantity, :tax_rate, :currency)', [
                'order_id' => $orderId,
                'book_id' => $oi['book_id'],
                'title_snapshot' => $oi['title_snapshot'],
                'unit_price' => $oi['unit_price'],
                'quantity' => $oi['quantity'],
                'tax_rate' => $oi['tax_rate'],
                'currency' => $oi['currency'],
            ]);
        }

        // create payment record (status pending)
        $transactionId = bin2hex(random_bytes(8));
        $exec('INSERT INTO payments (order_id, gateway, transaction_id, status, amount, currency, details, created_at, updated_at)
              VALUES (:order_id, :gateway, :txid, :status, :amount, :currency, :details, NOW(), NOW())', [
            'order_id' => $orderId,
            'gateway' => $gateway,
            'txid' => $transactionId,
            'status' => 'pending',
            'amount' => $total,
            'currency' => $currency,
            'details' => json_encode(['initiated_at' => gmdate('c'), 'method' => $gateway]),
        ]);

        // commit for now before redirecting to gateway (we'll rely on gateway callback to mark paid)
        if ($dbWrapper !== null && method_exists($dbWrapper, 'commit')) {
            $dbWrapper->commit();
        } else {
            $pdo->commit();
        }

        // clear cart (session or DB)
        if ($currentUserId !== null) {
            // delete cart items for user cart
            try {
                $cartRow = $fetchOne('SELECT id FROM carts WHERE user_id = :user_id ORDER BY updated_at DESC LIMIT 1', ['user_id' => $currentUserId]);
                if ($cartRow && !empty($cartRow['id'])) {
                    $exec('DELETE FROM cart_items WHERE cart_id = :cart_id', ['cart_id' => $cartRow['id']]);
                    $exec('UPDATE carts SET updated_at = NOW() WHERE id = :id', ['id' => $cartRow['id']]);
                }
            } catch (\Throwable $_) {}
        } else {
            $_SESSION['cart'] = [];
        }

        // If total == 0 -> mark paid immediately and grant digital downloads if any
        if ($total <= 0.0) {
            try {
                if ($dbWrapper !== null && method_exists($dbWrapper, 'beginTransaction')) {
                    $dbWrapper->beginTransaction();
                } else {
                    $pdo->beginTransaction();
                }
                $exec('UPDATE payments SET status = :status, updated_at = NOW() WHERE transaction_id = :tx', ['status' => 'paid', 'tx' => $transactionId]);
                $exec('UPDATE orders SET status = :status, updated_at = NOW() WHERE id = :id', ['status' => 'paid', 'id' => $orderId]);

                // get order_items for grant function
                $ois = $fetchAll('SELECT book_id FROM order_items WHERE order_id = :order_id', ['order_id' => $orderId]);
                grantDownloadsForOrder($orderId, $ois, $exec, $fetchOne, $fetchAll);

                if ($dbWrapper !== null && method_exists($dbWrapper, 'commit')) {
                    $dbWrapper->commit();
                } else {
                    $pdo->commit();
                }

                // render success page
                try {
                    echo Templates::render('pages/checkout_success.php', ['orderId' => $orderId, 'message' => 'Platba byla provedena (0 EUR).']);
                } catch (\Throwable $tplE) {
                    echo 'Order paid (ID: ' . (int)$orderId . ')';
                }
                exit;
            } catch (\Throwable $e) {
                if ($dbWrapper !== null && method_exists($dbWrapper, 'rollback')) {
                    try { $dbWrapper->rollback(); } catch (\Throwable $_) {}
                } else {
                    try { $pdo->rollBack(); } catch (\Throwable $_) {}
                }
                if (class_exists('Logger')) { try { Logger::systemError($e, $currentUserId ?? null); } catch (\Throwable $_) {} }
                try { echo Templates::render('pages/error.php', ['message' => 'Chyba při dokončování objednávky.']); } catch (\Throwable $_) { echo 'Error'; }
                exit;
            }
        }

        // non-zero total: initiate gateway
        $cfg = self::$config ?? ($GLOBALS['config'] ?? []);
        // fallback to global $config if set by bootstrap
        if (empty($cfg) && isset($config) && is_array($config)) $cfg = $config;

        $meta = [
            'amount' => $total,
            'currency' => $currency,
            'order_id' => $orderId,
            'return_url' => ($cfg['app_url'] ?? '') . '/eshop/?route=gopay_callback',
            'notify_url' => ($cfg['app_url'] ?? '') . '/eshop/?route=gopay_callback',
        ];
        $gatewayRes = initiatePaymentGateway($gateway, $meta, $cfg);

        if (!$gatewayRes['ok']) {
            if (class_exists('Logger')) { try { Logger::systemMessage('error', 'payment_initiation_failed', $currentUserId ?? null, ['gateway' => $gateway, 'error' => $gatewayRes['error']]); } catch (\Throwable $_) {} }
            try { echo Templates::render('pages/error.php', ['message' => 'Chyba při inicializaci platby.']); } catch (\Throwable $_) { echo 'Payment init error'; }
            exit;
        }

        // Update payments transaction_id if adapter returned different tx
        try {
            $exec('UPDATE payments SET transaction_id = :tx, updated_at = NOW() WHERE order_id = :order_id', ['tx' => $gatewayRes['transaction_id'], 'order_id' => $orderId]);
        } catch (\Throwable $_) {}

        // Redirect user to gateway (Post-Redirect-Get)
        header('Location: ' . $gatewayRes['redirect_url']);
        exit;

    } catch (\Throwable $e) {
        // rollback
        if ($dbWrapper !== null && method_exists($dbWrapper, 'rollback')) {
            try { $dbWrapper->rollback(); } catch (\Throwable $_) {}
        } else {
            try { $pdo->rollBack(); } catch (\Throwable $_) {}
        }
        if (class_exists('Logger')) { try { Logger::systemError($e, $currentUserId ?? null); } catch (\Throwable $_) {} }
        try { echo Templates::render('pages/error.php', ['message' => 'Nezdařilo se vytvořit objednávku.']); } catch (\Throwable $_) { echo 'Order creation failed'; }
        exit;
    }
}

// ---------- GET: render checkout form ----------
// prefill contact info from user if present
$prefill = [];
if ($currentUserId !== null) {
    $p = $fetchOne('SELECT u.email_enc, u.email_key_version, up.full_name, up.street_enc, up.city_enc, up.zip_enc, up.country_code FROM pouzivatelia u LEFT JOIN user_profiles up ON up.user_id = u.id WHERE u.id = :id LIMIT 1', ['id' => $currentUserId]);
    if ($p) {
        // decrypt email if encrypted (best-effort) — if Crypto available decode, else skip (email in DB hashed/encrypted)
        $emailVal = null;
        if (!empty($p['email_enc']) && class_exists('Crypto')) {
            try {
                Crypto::initFromKeyManager($config['paths']['keys'] ?? null);
                $emailVal = Crypto::decrypt($p['email_enc']);
            } catch (\Throwable $_) { $emailVal = null; }
        }
        if ($emailVal === null && isset($p['email_enc']) && is_string($p['email_enc'])) {
            // fallback: not available
            $emailVal = null;
        }
        $prefill['email'] = $emailVal ?? '';
        $prefill['full_name'] = $p['full_name'] ?? '';
        $prefill['street'] = $p['street_enc'] ?? '';
        $prefill['city'] = $p['city_enc'] ?? '';
        $prefill['zip'] = $p['zip_enc'] ?? '';
        $prefill['country'] = $p['country_code'] ?? '';
    }
}

// render checkout page
try {
    echo Templates::render('pages/checkout.php', ['prefill' => $prefill]);
} catch (\Throwable $e) {
    if (class_exists('Logger')) { try { Logger::systemError($e, $currentUserId ?? null); } catch (\Throwable $_) {} }
    http_response_code(500);
    echo Templates::render('pages/error.php', ['message' => 'Unable to render checkout']);
    exit;
}
<?php
declare(strict_types=1);

/**
 * checkout.php - handler compatible with index.php
 *
 * POST: place order (redirect to gateway or return success template for zero-total)
 * GET:  return template 'pages/checkout.php' with prefill data
 *
 * Expects injected/shared variables from index.php (optionally):
 *  - $user (current user array) or $userId in bootstrap
 *  - $csrfToken
 *  - etc.
 *
 * Uses Database::getInstance() (or $db if injected), Templates, Logger, CSRF, Session.
 */

$perPageDefault = 20;

try {
    // prefer injected $db (index trustedShared contains 'db' => $database), else singleton
    $database = $db ?? Database::getInstance();
} catch (\Throwable $e) {
    if (class_exists('Logger')) { try { Logger::systemError($e); } catch (\Throwable $_) {} }
    return ['template' => 'pages/error.php', 'vars' => ['message' => 'Internal server error (DB).']];
}

// helper: tax rate resolution
$fnGetTaxRateForBook = function (int $bookId, string $countryIso2) use ($database) : float {
    try {
        $r = $database->fetchValue('SELECT COUNT(*) FROM book_assets WHERE book_id = :book_id AND asset_type = :type', ['book_id' => $bookId, 'type' => 'pdf'], 0);
        $isEbook = ((int)$r) > 0;
        $category = $isEbook ? 'ebook' : 'physical';
        $today = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');
        $row = $database->fetch('SELECT rate FROM tax_rates WHERE country_iso2 = :country AND category = :cat AND valid_from <= :today ORDER BY valid_from DESC LIMIT 1', [
            'country' => $countryIso2,
            'cat' => $category,
            'today' => $today,
        ]);
        if ($row && isset($row['rate'])) return (float)$row['rate'];
    } catch (\Throwable $e) {
        if (class_exists('Logger')) { try { Logger::systemError($e); } catch (\Throwable $_) {} }
    }
    return 0.0;
};

// helper: download token
$fnGenDownloadToken = function (int $len = 24) {
    $bytes = random_bytes($len);
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
};

// helper: grant downloads for order
$fnGrantDownloadsForOrder = function (int $orderId, array $orderItems) use ($database, $fnGenDownloadToken) : void {
    foreach ($orderItems as $oi) {
        $bookId = (int)$oi['book_id'];
        $assets = $database->fetchAll('SELECT id, is_encrypted, download_filename, key_id FROM book_assets WHERE book_id = :book_id AND asset_type = :type', [
            'book_id' => $bookId,
            'type' => 'pdf',
        ]);
        foreach ($assets as $a) {
            $token = $fnGenDownloadToken(24);
            $expiresAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+30 days')->format('Y-m-d H:i:s');
            $maxUses = 3;
            $database->execute(
                'INSERT INTO order_item_downloads (order_id, book_id, asset_id, download_token, encryption_key_version, token_key_version, max_uses, used, expires_at)
                 VALUES (:order_id, :book_id, :asset_id, :token, :enc_ver, :tok_ver, :max_uses, 0, :expires_at)',
                [
                    'order_id' => $orderId,
                    'book_id' => $bookId,
                    'asset_id' => $a['id'],
                    'token' => $token,
                    'enc_ver' => $a['key_id'] ?? null,
                    'tok_ver' => null,
                    'max_uses' => $maxUses,
                    'expires_at' => $expiresAt,
                ]
            );
        }
    }
};

// helper: initiate payment gateway (simple stub)
$fnInitiatePaymentGateway = function (string $gateway, array $meta, array $cfg = []) {
    $gateway = strtolower($gateway);
    $txId = bin2hex(random_bytes(8));
    if ($gateway === 'gopay') {
        $base = $cfg['payments']['gopay']['checkout_url'] ?? ($cfg['app_url'] ?? '') . '/gopay/checkout';
        $query = http_build_query([
            'tx' => $txId,
            'amount' => $meta['amount'],
            'currency' => $meta['currency'],
            'order_id' => $meta['order_id'],
            'return_url' => $meta['return_url'] ?? ($cfg['app_url'] ?? '') . '/eshop/?route=gopay_callback',
            'notify_url' => $meta['notify_url'] ?? ($cfg['app_url'] ?? '') . '/eshop/?route=gopay_callback',
        ]);
        return ['ok' => true, 'redirect_url' => rtrim($base, '/') . '?' . $query, 'transaction_id' => $txId];
    }
    if ($gateway === 'square' || $gateway === 'pay_by_square') {
        $base = $cfg['payments']['square']['checkout_url'] ?? ($cfg['app_url'] ?? '') . '/square/checkout';
        $query = http_build_query([
            'tx' => $txId,
            'amount' => $meta['amount'],
            'currency' => $meta['currency'],
            'order_id' => $meta['order_id'],
        ]);
        return ['ok' => true, 'redirect_url' => rtrim($base, '/') . '?' . $query, 'transaction_id' => $txId];
    }
    return ['ok' => false, 'error' => 'unsupported_gateway'];
};

// --- session & current user (best-effort) ---
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$currentUserId = $user['id'] ?? ($_SESSION['user_id'] ?? null);

// ensure session cart exists
if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) $_SESSION['cart'] = [];

/* ---------- handle POST: place order ---------- */
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'POST') {
    // CSRF
    if (class_exists('CSRF') && method_exists('CSRF', 'validate')) {
        try {
            if (!CSRF::validate($_POST['csrf'] ?? null)) {
                if (class_exists('Logger')) { try { Logger::systemMessage('warning', 'csrf_failed', $currentUserId ?? null); } catch (\Throwable $_) {} }
                // redirect back to checkout
                header('Location: ?route=checkout', true, 302);
                return ['content' => ''];
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
        return ['template' => 'pages/checkout.php', 'vars' => ['error' => 'Vyplňte jméno, e-mail a zemi.', 'posted' => $_POST]];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['template' => 'pages/checkout.php', 'vars' => ['error' => 'Neplatný e-mail.', 'posted' => $_POST]];
    }

    // collect cart items
    $cartItems = [];

    try {
        if ($currentUserId !== null) {
            $cartRow = $database->fetch('SELECT id FROM carts WHERE user_id = :user_id ORDER BY updated_at DESC LIMIT 1', ['user_id' => $currentUserId]);
            if ($cartRow && !empty($cartRow['id'])) {
                $cartId = (int)$cartRow['id'];
                $rows = $database->fetchAll(
                    'SELECT ci.book_id, ci.quantity, ci.price_snapshot, ci.currency, b.title, b.slug, b.is_available, b.stock_quantity
                     FROM cart_items ci
                     JOIN books b ON b.id = ci.book_id
                     WHERE ci.cart_id = :cart_id',
                    ['cart_id' => $cartId]
                );
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
                    $placeholders = implode(',', array_fill(0, count($ids), '?'));
                    $rows = $database->fetchAll('SELECT id, title, slug, price, currency, is_available, stock_quantity FROM books WHERE id IN (' . $placeholders . ') AND is_active = 1', $ids);
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
    } catch (\Throwable $e) {
        if (class_exists('Logger')) { try { Logger::systemError($e, $currentUserId ?? null); } catch (\Throwable $_) {} }
        return ['template' => 'pages/error.php', 'vars' => ['message' => 'Chyba při načítání košíku.']];
    }

    if (empty($cartItems)) {
        return ['template' => 'pages/checkout.php', 'vars' => ['error' => 'Košík je prázdný.']];
    }

    // compute totals
    $subtotal = 0.0;
    $tax_total = 0.0;
    $currency = $cartItems[0]['currency'] ?? 'EUR';
    $orderItemsForInsert = [];

    foreach ($cartItems as $ci) {
        $line = $ci['price_snapshot'] * $ci['qty'];
        $subtotal += $line;
        $rate = $fnGetTaxRateForBook((int)$ci['book_id'], $bill_country);
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

    $discount_total = 0.0;
    $total = round($subtotal + $tax_total - $discount_total, 2);

    // persist order + order_items + payment inside transaction
    try {
        $database->beginTransaction();

        $sqlOrder = 'INSERT INTO orders (user_id, status, bill_full_name, bill_company, bill_street, bill_city, bill_zip, bill_country_code, bill_tax_id, bill_vat_id, email, currency, subtotal, discount_total, tax_total, total, payment_method, created_at, updated_at)
                     VALUES (:user_id, :status, :bill_full_name, :bill_company, :bill_street, :bill_city, :bill_zip, :bill_country, :bill_tax_id, :bill_vat_id, :email, :currency, :subtotal, :discount_total, :tax_total, :total, :payment_method, NOW(), NOW())';
        $database->execute($sqlOrder, [
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
            'email' => $email,
            'currency' => $currency,
            'subtotal' => $subtotal,
            'discount_total' => $discount_total,
            'tax_total' => $tax_total,
            'total' => $total,
            'payment_method' => $gateway,
        ]);

        $orderId = (int)$database->lastInsertId();

        foreach ($orderItemsForInsert as $oi) {
            $database->execute(
                'INSERT INTO order_items (order_id, book_id, title_snapshot, unit_price, quantity, tax_rate, currency) VALUES
                 (:order_id, :book_id, :title_snapshot, :unit_price, :quantity, :tax_rate, :currency)',
                [
                    'order_id' => $orderId,
                    'book_id' => $oi['book_id'],
                    'title_snapshot' => $oi['title_snapshot'],
                    'unit_price' => $oi['unit_price'],
                    'quantity' => $oi['quantity'],
                    'tax_rate' => $oi['tax_rate'],
                    'currency' => $oi['currency'],
                ]
            );
        }

        $transactionId = bin2hex(random_bytes(8));
        $database->execute('INSERT INTO payments (order_id, gateway, transaction_id, status, amount, currency, details, created_at, updated_at)
              VALUES (:order_id, :gateway, :txid, :status, :amount, :currency, :details, NOW(), NOW())', [
            'order_id' => $orderId,
            'gateway' => $gateway,
            'txid' => $transactionId,
            'status' => 'pending',
            'amount' => $total,
            'currency' => $currency,
            'details' => json_encode(['initiated_at' => gmdate('c'), 'method' => $gateway]),
        ]);

        $database->commit();

        // clear cart
        try {
            if ($currentUserId !== null) {
                $cartRow = $database->fetch('SELECT id FROM carts WHERE user_id = :user_id ORDER BY updated_at DESC LIMIT 1', ['user_id' => $currentUserId]);
                if ($cartRow && !empty($cartRow['id'])) {
                    $database->execute('DELETE FROM cart_items WHERE cart_id = :cart_id', ['cart_id' => $cartRow['id']]);
                    $database->execute('UPDATE carts SET updated_at = NOW() WHERE id = :id', ['id' => $cartRow['id']]);
                }
            } else {
                $_SESSION['cart'] = [];
            }
        } catch (\Throwable $_) {}

        // handle zero-total immediate paid case
        if ($total <= 0.0) {
            try {
                $database->beginTransaction();
                $database->execute('UPDATE payments SET status = :status, updated_at = NOW() WHERE transaction_id = :tx', ['status' => 'paid', 'tx' => $transactionId]);
                $database->execute('UPDATE orders SET status = :status, updated_at = NOW() WHERE id = :id', ['status' => 'paid', 'id' => $orderId]);

                $ois = $database->fetchAll('SELECT book_id FROM order_items WHERE order_id = :order_id', ['order_id' => $orderId]);
                $fnGrantDownloadsForOrder($orderId, $ois);

                $database->commit();

                return ['template' => 'pages/checkout_success.php', 'vars' => ['orderId' => $orderId, 'message' => 'Platba byla provedena (0 EUR).']];
            } catch (\Throwable $e) {
                try { $database->rollback(); } catch (\Throwable $_) {}
                if (class_exists('Logger')) { try { Logger::systemError($e, $currentUserId ?? null); } catch (\Throwable $_) {} }
                return ['template' => 'pages/error.php', 'vars' => ['message' => 'Chyba při dokončování objednávky.']];
            }
        }

        // non-zero total: initiate gateway
        $cfg = $GLOBALS['config'] ?? [];
        $meta = [
            'amount' => $total,
            'currency' => $currency,
            'order_id' => $orderId,
            'return_url' => ($cfg['app_url'] ?? '') . '/eshop/?route=gopay_callback',
            'notify_url' => ($cfg['app_url'] ?? '') . '/eshop/?route=gopay_callback',
        ];
        $gatewayRes = $fnInitiatePaymentGateway($gateway, $meta, $cfg);

        if (!$gatewayRes['ok']) {
            if (class_exists('Logger')) { try { Logger::systemMessage('error', 'payment_initiation_failed', $currentUserId ?? null, ['gateway' => $gateway, 'error' => $gatewayRes['error']]); } catch (\Throwable $_) {} }
            return ['template' => 'pages/error.php', 'vars' => ['message' => 'Chyba při inicializaci platby.']];
        }

        // update payments transaction id
        try {
            $database->execute('UPDATE payments SET transaction_id = :tx, updated_at = NOW() WHERE order_id = :order_id', ['tx' => $gatewayRes['transaction_id'], 'order_id' => $orderId]);
        } catch (\Throwable $_) {}

        // redirect to gateway
        header('Location: ' . $gatewayRes['redirect_url'], true, 302);
        return ['content' => ''];
    } catch (\Throwable $e) {
        try { $database->rollback(); } catch (\Throwable $_) {}
        if (class_exists('Logger')) { try { Logger::systemError($e, $currentUserId ?? null); } catch (\Throwable $_) {} }
        return ['template' => 'pages/error.php', 'vars' => ['message' => 'Nezdařilo se vytvořit objednávku.']];
    }
}

/* ---------- GET: render checkout form (return to index.php) ---------- */
$prefill = [];
if ($currentUserId !== null) {
    try {
        $p = $database->fetch('SELECT u.email_enc, u.email_key_version, up.full_name, up.street_enc, up.city_enc, up.zip_enc, up.country_code FROM pouzivatelia u LEFT JOIN user_profiles up ON up.user_id = u.id WHERE u.id = :id LIMIT 1', ['id' => $currentUserId]);
        if ($p) {
            $emailVal = null;
            if (!empty($p['email_enc']) && class_exists('Crypto')) {
                try {
                    // best-effort decrypt — may throw
                    Crypto::initFromKeyManager($GLOBALS['config']['paths']['keys'] ?? null);
                    $emailVal = Crypto::decrypt($p['email_enc']);
                } catch (\Throwable $_) { $emailVal = null; }
            }
            $prefill['email'] = $emailVal ?? '';
            $prefill['full_name'] = $p['full_name'] ?? '';
            // Note: if profile fields are encrypted, ideally decrypt here; otherwise they may be stored plain
            $prefill['street'] = $p['street_enc'] ?? '';
            $prefill['city'] = $p['city_enc'] ?? '';
            $prefill['zip'] = $p['zip_enc'] ?? '';
            $prefill['country'] = $p['country_code'] ?? '';
        }
    } catch (\Throwable $e) {
        if (class_exists('Logger')) { try { Logger::warn('Failed to prefill checkout', $currentUserId, ['exception' => (string)$e]); } catch (\Throwable $_) {} }
    }
}

return ['template' => 'pages/checkout.php', 'vars' => ['prefill' => $prefill]];
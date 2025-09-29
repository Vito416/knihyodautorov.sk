<?php
declare(strict_types=1);

/**
 * /eshop/order_submit.php
 *
 * POST endpoint — přijímá JSON (nebo form fallback).
 * Pokud vše OK: vrátí { ok: true, redirect_url, order_id, payment_id }
 * Pokud ne: vrátí { ok: false, error, message }  (HTTP 200, frontend bude flashovat)
 *
 * Payload:
 * {
 *   "cart": [{ "book_id": 123, "qty": 1 }, ...],
 *   "bill_full_name": "...",
 *   "email": "...",
 *   "bill_street": "...",
 *   "bill_city": "...",
 *   "bill_zip": "...",
 *   "bill_country": "...",
 *   "idempotency_key": "optional",
 *   "csrf": "optional"
 * }
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed', 'message' => 'Pouze POST']);
    exit;
}

/* ---------- helpers ---------- */
function respondJson(array $data): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function toInt(mixed $v, int $default = 0): int {
    if (is_int($v)) return $v;
    if (is_string($v) && preg_match('/^-?\d+$/', $v)) return (int)$v;
    return $default;
}

function validateCsrfToken(?string $csrfTokenShared, array $parsedInput = []): bool {
    $provided = null;
    if (!empty($_SERVER['HTTP_X_CSRF_TOKEN'])) $provided = (string) $_SERVER['HTTP_X_CSRF_TOKEN'];
    elseif (!empty($_SERVER['HTTP_X_CSRF'])) $provided = (string) $_SERVER['HTTP_X_CSRF'];
    elseif (isset($_POST['csrf'])) $provided = (string) $_POST['csrf'];
    elseif (isset($parsedInput['csrf'])) $provided = (string) $parsedInput['csrf'];

    if ($provided === null || $provided === '') return false;

    if (class_exists('CSRF') && method_exists('CSRF', 'validate')) {
        try { return (bool) CSRF::validate($provided); } catch (\Throwable $e) { return false; }
    }

    if ($csrfTokenShared !== null && $csrfTokenShared !== '') {
        return hash_equals((string)$csrfTokenShared, (string)$provided);
    }

    // žádný validator -> nevalidní
    return false;
}

/* ---------- dependencies ---------- */
/** @var Database $db */
if (!isset($db) || !($db instanceof Database)) {
    // server error, ale vrátíme ok:false pro frontend handling
    respondJson(['ok' => false, 'error' => 'server_error', 'message' => 'Databáze není dostupná']);
}
if (!isset($gopayAdapter) || !is_object($gopayAdapter)) {
    respondJson(['ok' => false, 'error' => 'server_error', 'message' => 'Platební adaptér není dostupný']);
}

/* ---------- parse input ---------- */
$input = [];
$contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
if (stripos((string)$contentType, 'application/json') !== false) {
    $raw = file_get_contents('php://input');
    $decoded = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        respondJson(['ok' => false, 'error' => 'invalid_json', 'message' => 'Neplatné JSON tělo']);
    }
    $input = $decoded;
} else {
    $input = $_POST;
}

/* ---------- optional CSRF (if available) ---------- */
try {
    $csrfShared = $csrfToken ?? null;
    if (!validateCsrfToken($csrfShared, $input)) {
        // vracíme ok:false — frontend to ošetří jako flash
        respondJson(['ok' => false, 'error' => 'csrf_invalid', 'message' => 'Neplatný CSRF token']);
    }
} catch (\Throwable $_) {
    respondJson(['ok' => false, 'error' => 'csrf_error', 'message' => 'Chyba CSRF kontroly']);
}

/* ---------- extract payload ---------- */
$cartInput = $input['cart'] ?? null;
if (!is_array($cartInput) || empty($cartInput)) {
    respondJson(['ok' => false, 'error' => 'invalid_payload', 'message' => 'Košík je prázdný nebo nevalidní']);
}

$bill_full_name = trim((string)($input['bill_full_name'] ?? ''));
$email = trim((string)($input['email'] ?? ''));
$bill_street = trim((string)($input['bill_street'] ?? ''));
$bill_city = trim((string)($input['bill_city'] ?? ''));
$bill_zip = trim((string)($input['bill_zip'] ?? ''));
$bill_country = trim((string)($input['bill_country'] ?? ''));

if ($bill_full_name === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respondJson(['ok' => false, 'error' => 'invalid_billing', 'message' => 'Neplatné fakturační údaje']);
}

$clientIdempotencyKey = !empty($input['idempotency_key']) ? (string)$input['idempotency_key'] : null;

/* ---------- normalize cart, collect book IDs ---------- */
$normalized = [];
$bookIds = [];
foreach ($cartInput as $i => $line) {
    $bookId = toInt($line['book_id'] ?? $line['id'] ?? 0, 0);
    $qty = max(1, toInt($line['qty'] ?? $line['quantity'] ?? 1, 1));
    if ($bookId <= 0) {
        respondJson(['ok' => false, 'error' => 'invalid_payload', 'message' => "Neplatné book_id na indexu $i"]);
    }
    $normalized[] = ['book_id' => $bookId, 'qty' => $qty];
    $bookIds[] = $bookId;
}
$bookIds = array_values(array_unique($bookIds));

/* ---------- fetch books in one query ---------- */
try {
    $placeholders = implode(',', array_fill(0, count($bookIds), '?'));
    $sql = "SELECT id, price, currency, is_available, stock_quantity, title FROM books WHERE id IN ($placeholders)";
    $booksRows = $db->fetchAll($sql, $bookIds);
    $booksById = [];
    foreach ($booksRows as $r) $booksById[(int)$r['id']] = $r;
} catch (\Throwable $e) {
    if (class_exists('Logger')) try { Logger::systemError($e, null, ['phase'=>'order_submit.fetch_books']); } catch (\Throwable $_) {}
    respondJson(['ok' => false, 'error' => 'server_error', 'message' => 'Chyba při dotazu na knihy']);
}

/* ---------- validate availability & compute total ---------- */
$total = 0.0;
$currency = 'EUR';
foreach ($normalized as $line) {
    $bid = $line['book_id'];
    $qty = $line['qty'];
    if (!isset($booksById[$bid])) {
        respondJson(['ok' => false, 'error' => 'invalid_book', 'book_id' => $bid, 'message' => 'Kniha neexistuje']);
    }
    $b = $booksById[$bid];

    if (isset($b['is_available']) && !$b['is_available']) {
        respondJson(['ok' => false, 'error' => 'out_of_stock', 'book_id' => $bid, 'message' => 'Není dostupné']);
    }
    if (isset($b['stock_quantity']) && is_numeric($b['stock_quantity'])) {
        $available = (int)$b['stock_quantity'];
        if ($available < $qty) {
            respondJson(['ok' => false, 'error' => 'insufficient_stock', 'book_id' => $bid, 'available' => $available, 'message' => 'Nedostatek zásob']);
        }
    }

    $unit = number_format((float)($b['price'] ?? 0.0), 2, '.', '');
    $linePrice = (float)$unit * $qty;
    $total += $linePrice;
    $currency = $b['currency'] ?? $currency;
}
$total = round($total, 2);

/* ---------- idempotency quick-check (client-sent key) ---------- */
$idempotencyKey = $clientIdempotencyKey ?? bin2hex(random_bytes(16));
$idempHash = hash('sha256', (string)$idempotencyKey);

if ($clientIdempotencyKey !== null) {
    try {
        $row = $db->fetch('SELECT response FROM idempotency_keys WHERE key_hash = :k LIMIT 1', [':k' => $idempHash]);
        if (!empty($row['response'])) {
            $cached = json_decode($row['response'], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                // Pokud dorazila cached odpověď, vrať ji (frontend zachytí redirect_url a přesměruje)
                $out = array_merge(['ok' => true, 'idempotency_cached' => true], $cached);
                respondJson($out);
            }
        }
    } catch (\Throwable $e) {
        if (class_exists('Logger')) try { Logger::warn('order_submit: idempotency lookup failed', null, ['exception' => (string)$e]); } catch (\Throwable $_) {}
    }
}

/* ---------- create order + items + reservations (transaction) ---------- */
$orderId = null;
$orderUuid = bin2hex(random_bytes(8)); // používame pri logovaní, ľahké dohľadať
$reservationTtlSec = 900; // 15 min rezervácia

try {
    $db->transaction(function(Database $d) use ($normalized, $total, $currency, $bill_full_name, $email, $bill_street, $bill_city, $bill_zip, $bill_country, &$orderId, $orderUuid, $reservationTtlSec) {
        // uložíme objednávku
        $customerBlob = json_encode([
            'full_name' => $bill_full_name,
            'email' => $email,
            'street' => $bill_street,
            'city' => $bill_city,
            'zip' => $bill_zip,
            'country' => $bill_country
        ]);

        $d->prepareAndRun(
            'INSERT INTO orders (uuid, total, currency, status, bill_full_name, encrypted_customer_blob, created_at) VALUES (:uuid,:total,:cur,:st,:name,:blob,NOW())',
            [
                ':uuid' => $orderUuid,
                ':total' => number_format($total, 2, '.', ''),
                ':cur' => $currency,
                ':st' => 'pending',
                ':name' => $bill_full_name,
                ':blob' => $customerBlob
            ]
        );
        $orderId = (int)$d->lastInsertId();

        // vložíme položky objednávky a rezervácie skladu v súlade so schémou
        foreach ($normalized as $line) {
            $bookId = (int)$line['book_id'];
            $qty = max(1, (int)$line['qty']);

            // načítame referencné údaje o knihe (title, price, currency)
            $bookRow = $d->fetch('SELECT title, price, currency FROM books WHERE id = :id LIMIT 1', [':id' => $bookId]);

            $titleSnapshot = $bookRow['title'] ?? '';
            $unitPrice = $bookRow ? number_format((float)($bookRow['price'] ?? 0.0), 2, '.', '') : '0.00';
            $lineCurrency = $bookRow['currency'] ?? $currency;

            // tax_rate zatiaľ 0.00 (prípadne doplniť logiku podľa krajiny / typu produktu)
            $taxRate = '0.00';

            // INSERT do order_items podľa schémy (title_snapshot, unit_price, quantity, tax_rate, currency)
            $d->prepareAndRun(
                'INSERT INTO order_items (order_id, book_id, title_snapshot, unit_price, quantity, tax_rate, currency) VALUES (:oid, :bid, :title, :unit_price, :qty, :tax, :cur)',
                [
                    ':oid' => $orderId,
                    ':bid' => $bookId,
                    ':title' => $titleSnapshot,
                    ':unit_price' => $unitPrice,
                    ':qty' => $qty,
                    ':tax' => $taxRate,
                    ':cur' => $lineCurrency,
                ]
            );

            // Vložíme inventory_reservations: schéma vyžaduje reserved_until
            $reservedUntil = (new \DateTimeImmutable('+' . $reservationTtlSec . ' seconds'))->format('Y-m-d H:i:s');

            $d->prepareAndRun(
                'INSERT INTO inventory_reservations (order_id, book_id, qty, reserved_until, status, created_at) VALUES (:oid, :bid, :q, :reserved_until, :st, NOW())',
                [
                    ':oid' => $orderId,
                    ':bid' => $bookId,
                    ':q' => $qty,
                    ':reserved_until' => $reservedUntil,
                    ':st' => 'pending'
                ]
            );
        }
    });
} catch (\Throwable $e) {
    // vygenerujeme krátke token-rozlíšenie pre log (bez logovania celej chyby frontendu)
    $errorToken = substr(bin2hex(random_bytes(6)), 0, 12);
    if (class_exists('Logger')) {
        try {
            Logger::systemError($e, null, null, [
                'phase' => 'order_submit.create_order',
                'order_uuid' => $orderUuid,
                'error_token' => $errorToken
            ]);
        } catch (\Throwable $_) {
            // never bubble logging failure
        }
    }

    // v debug móde môžeme pridať informáciu (neodporúča sa v produkcii)
    $debugMsg = '';
    if (!empty($_ENV['DEBUG'])) {
        $debugMsg = ' Debug: ' . $e->getMessage();
    }

    respondJson([
        'ok' => false,
        'error' => 'order_create_failed',
        'message' => 'Nepodařilo se vytvořit objednávku',
        'error_token' => $errorToken,
        'debug' => $debugMsg
    ]);
}

/* ---------- persist idempotency placeholder (best-effort) ---------- */
try {
    $placeholder = json_encode(['order_id' => $orderId, 'payment_id' => null, 'redirect_url' => null]);
    $db->prepareAndRun('INSERT INTO idempotency_keys (key_hash, user_id, request_hash, response, ttl_seconds, created_at) VALUES (:k, :uid, NULL, :r, 86400, NOW())', [
        ':k' => $idempHash, ':uid' => $user['id'] ?? null, ':r' => $placeholder
    ]);
} catch (\Throwable $_) {
    // ignoruj duplicity / chybějící tabulku
}

/* ---------- call GoPay adapter ---------- */
try {
    $gopayRes = $gopayAdapter->createPaymentFromOrder($orderId, $idempotencyKey);
} catch (\Throwable $e) {
    if (class_exists('Logger')) try { Logger::systemError($e, null, ['phase'=>'gopay.createPayment', 'order_id'=>$orderId]); } catch (\Throwable $_) {}
    respondJson(['ok' => false, 'error' => 'payment_init_failed', 'message' => 'Chyba při inicializaci platby']);
}

/* ---------- ensure redirect_url exists ---------- */
$redirect = $gopayRes['redirect_url'] ?? null;
$paymentId = $gopayRes['payment_id'] ?? null;

/* If GoPay didn't return redirect URL -> ok:false so checkout.js will flash */
if (empty($redirect)) {
    // update idempotency with whatever response we have
    try {
        $toStore = json_encode(['order_id' => $orderId, 'payment_id' => $paymentId, 'redirect_url' => $redirect, 'gopay' => $gopayRes['gopay'] ?? null]);
        $db->execute('UPDATE idempotency_keys SET response = :r WHERE key_hash = :k', [':r' => $toStore, ':k' => $idempHash]);
    } catch (\Throwable $_) {}
    respondJson(['ok' => false, 'error' => 'no_redirect', 'message' => 'Platební brána nevrátila přesměrovací URL']);
}

/* ---------- persist real idempotency response (best-effort) ---------- */
try {
    $toStore = json_encode(['order_id' => $orderId, 'payment_id' => $paymentId, 'redirect_url' => $redirect, 'gopay' => $gopayRes['gopay'] ?? null]);
    $db->execute('UPDATE idempotency_keys SET response = :r WHERE key_hash = :k', [':r' => $toStore, ':k' => $idempHash]);
} catch (\Throwable $_) {
    // non-fatal
}

/* ---------- set last_order_id in session if session active (UX) ---------- */
if (session_status() === PHP_SESSION_ACTIVE) {
    $_SESSION['last_order_id'] = $orderId;
}

/* ---------- final OK response (frontend will redirect) ---------- */
respondJson([
    'ok' => true,
    'order_id' => $orderId,
    'payment_id' => $paymentId,
    'redirect_url' => $redirect
]);
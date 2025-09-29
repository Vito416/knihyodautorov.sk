<?php
declare(strict_types=1);
// gopay_web_test.php — web-friendly quick test for GoPayAdapter
// Usage: open in browser: https://yourhost/tools/tests/gopay_web_test.php

// ======= ADJUST THIS PATH to your bootstrap that sets up $database/$db and $gopayAdapter =======
$BOOTSTRAP = __DIR__ . '/../eshop/inc/bootstrap.php'; // <- uprav dle projektu
// =============================================================================

header('Content-Type: application/json; charset=utf-8');

if (!file_exists($BOOTSTRAP)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'bootstrap_missing', 'message' => "Bootstrap not found at $BOOTSTRAP"]);
    exit;
}

require_once $BOOTSTRAP;

// Expecting $database (wrapper) or $db (PDO) and $gopayAdapter
if ((!isset($database) && !isset($db)) || !isset($gopayAdapter)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'env_missing', 'message' => 'Bootstrap did not expose $database or $db and $gopayAdapter']);
    exit;
}

// helpers to work with either Database wrapper or PDO
function beginTransactionHandle(&$h) {
    if ($h instanceof PDO) return $h->beginTransaction();
    if (is_object($h) && method_exists($h, 'getPdo')) {
        return $h->getPdo()->beginTransaction();
    }
    if (is_object($h) && method_exists($h, 'transaction')) {
        // wrapper.transaction(callback) exists but we want manual control here:
        if (method_exists($h, 'getPdo')) return $h->getPdo()->beginTransaction();
    }
    throw new RuntimeException('Unsupported DB handle for beginTransaction');
}
function commitHandle(&$h) {
    if ($h instanceof PDO) return $h->commit();
    if (is_object($h) && method_exists($h, 'getPdo')) return $h->getPdo()->commit();
    throw new RuntimeException('Unsupported DB handle for commit');
}
function rollBackHandle(&$h) {
    if ($h instanceof PDO) return $h->rollBack();
    if (is_object($h) && method_exists($h, 'getPdo')) return $h->getPdo()->rollBack();
    throw new RuntimeException('Unsupported DB handle for rollBack');
}
function lastInsertIdHandle(&$h) {
    if ($h instanceof PDO) return (int)$h->lastInsertId();
    if (is_object($h) && method_exists($h, 'lastInsertId')) return (int)$h->lastInsertId();
    if (is_object($h) && method_exists($h, 'getPdo')) return (int)$h->getPdo()->lastInsertId();
    throw new RuntimeException('Unsupported DB handle for lastInsertId');
}
function runStmtHandle(&$h, string $sql, array $params = []) {
    // Named params expected in $params (associative)
    if ($h instanceof PDO) {
        $stmt = $h->prepare($sql);
        if ($stmt === false) throw new RuntimeException('Failed to prepare statement');
        $ok = $stmt->execute($params);
        if ($ok === false) {
            $err = $stmt->errorInfo();
            throw new RuntimeException('PDO execute failed: ' . ($err[2] ?? json_encode($err)));
        }
        return $stmt;
    }
    if (is_object($h) && method_exists($h, 'prepareAndRun')) {
        // assume wrapper handles named params
        $h->prepareAndRun($sql, $params);
        return true;
    }
    // fallback: try executing directly
    if (is_object($h) && method_exists($h, 'execute')) {
        $h->execute($sql);
        return true;
    }
    throw new RuntimeException('Unsupported DB handle for execute');
}

// pick db handle
$dbHandle = $database ?? $db;

$created = [
    'author_id' => null,
    'category_id' => null,
    'book_id' => null,
    'order_id' => null,
    'order_item_id' => null,
    'reservation_id' => null,
];

try {
    beginTransactionHandle($dbHandle);

    // 0) create minimal author
    $authorName = 'TEST AUTHOR ' . bin2hex(random_bytes(2));
    $sql = 'INSERT INTO authors (meno, slug, created_at) VALUES (:meno, :slug, NOW())';
    $slug = 'test-author-' . bin2hex(random_bytes(3));
    runStmtHandle($dbHandle, $sql, [':meno' => $authorName, ':slug' => $slug]);
    $created['author_id'] = lastInsertIdHandle($dbHandle);

    // 1) create minimal category
    $catName = 'TEST CAT ' . bin2hex(random_bytes(2));
    $catSlug = 'test-cat-' . bin2hex(random_bytes(3));
    $sql = 'INSERT INTO categories (nazov, slug, created_at) VALUES (:nazov, :slug, NOW())';
    runStmtHandle($dbHandle, $sql, [':nazov' => $catName, ':slug' => $catSlug]);
    $created['category_id'] = lastInsertIdHandle($dbHandle);

    // 2) insert a test book (use author_id and category_id to satisfy FKs)
    $bookPrice = '9.90';
    $bookCurrency = 'EUR';
    $bookTitle = 'TEST BOOK ' . bin2hex(random_bytes(3));
    $sql = 'INSERT INTO books (title, slug, price, currency, author_id, main_category_id, is_active, is_available, stock_quantity, created_at) 
            VALUES (:title, :slug, :price, :cur, :author_id, :cat_id, 1, 1, 100, NOW())';
    $bookSlug = 'test-book-' . bin2hex(random_bytes(4));
    runStmtHandle($dbHandle, $sql, [':title' => $bookTitle, ':slug' => $bookSlug, ':price' => $bookPrice, ':cur' => $bookCurrency, ':author_id' => $created['author_id'], ':cat_id' => $created['category_id']]);
    $created['book_id'] = lastInsertIdHandle($dbHandle);

    // 3) insert an order
    $orderUuid = bin2hex(random_bytes(8));
    $total = $bookPrice;
    $customerBlob = json_encode(['full_name' => 'Test User', 'email' => 'test@example.com']);
    $sql = 'INSERT INTO orders (uuid, total, currency, status, bill_full_name, encrypted_customer_blob, created_at) VALUES (:uuid, :total, :cur, :st, :name, :blob, NOW())';
    runStmtHandle($dbHandle, $sql, [':uuid' => $orderUuid, ':total' => $total, ':cur' => $bookCurrency, ':st' => 'pending', ':name' => 'Test User', ':blob' => $customerBlob]);
    $created['order_id'] = lastInsertIdHandle($dbHandle);

    // 4) insert order item
    $sql = 'INSERT INTO order_items (order_id, book_id, title_snapshot, unit_price, quantity, tax_rate, currency) VALUES (:oid, :bid, :title, :unit_price, :qty, :tax, :cur)';
    runStmtHandle($dbHandle, $sql, [':oid' => $created['order_id'], ':bid' => $created['book_id'], ':title' => $bookTitle, ':unit_price' => number_format((float)$bookPrice, 2, '.', ''), ':qty' => 1, ':tax' => '0.00', ':cur' => $bookCurrency]);
    $created['order_item_id'] = lastInsertIdHandle($dbHandle);

    // 5) insert inventory_reservation
    $reservedUntil = (new DateTimeImmutable('+15 minutes'))->format('Y-m-d H:i:s');
    $sql = 'INSERT INTO inventory_reservations (order_id, book_id, qty, reserved_until, status, created_at) VALUES (:oid, :bid, :q, :reserved_until, :st, NOW())';
    runStmtHandle($dbHandle, $sql, [':oid' => $created['order_id'], ':bid' => $created['book_id'], ':q' => 1, ':reserved_until' => $reservedUntil, ':st' => 'pending']);
    $created['reservation_id'] = lastInsertIdHandle($dbHandle);

    commitHandle($dbHandle);

} catch (Throwable $e) {
    // try rollback
    try { rollBackHandle($dbHandle); } catch (Throwable $_) {}
    // If PDO exception, expose errorInfo to help debugging (but be careful on public servers)
    $errInfo = null;
    if ($e instanceof PDOException && isset($e->errorInfo)) $errInfo = $e->errorInfo;
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'create_failed', 'message' => $e->getMessage(), 'driver_error' => $errInfo], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// Now call the adapter
try {
    $idempotencyKey = 'test-' . bin2hex(random_bytes(8));
    $res = $gopayAdapter->createPaymentFromOrder((int)$created['order_id'], $idempotencyKey);
} catch (Throwable $e) {
    // Attempt cleanup, then return error
    try {
        // cleanup attempts (best-effort)
        runStmtHandle($dbHandle, 'DELETE FROM payments WHERE order_id = :oid', [':oid' => $created['order_id']]);
        runStmtHandle($dbHandle, 'DELETE FROM inventory_reservations WHERE order_id = :oid', [':oid' => $created['order_id']]);
        runStmtHandle($dbHandle, 'DELETE FROM order_items WHERE order_id = :oid', [':oid' => $created['order_id']]);
        runStmtHandle($dbHandle, 'DELETE FROM orders WHERE id = :id', [':id' => $created['order_id']]);
        runStmtHandle($dbHandle, 'DELETE FROM books WHERE id = :id', [':id' => $created['book_id']]);
        runStmtHandle($dbHandle, 'DELETE FROM authors WHERE id = :id', [':id' => $created['author_id']]);
        runStmtHandle($dbHandle, 'DELETE FROM categories WHERE id = :id', [':id' => $created['category_id']]);
    } catch (Throwable $_) {}
    http_response_code(500);
    $errInfo = $e instanceof PDOException && isset($e->errorInfo) ? $e->errorInfo : null;
    echo json_encode(['ok' => false, 'error' => 'adapter_error', 'message' => $e->getMessage(), 'driver_error' => $errInfo], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// cleanup (optionally remove payments too)
$cleanup_delete_everything = true;
if ($cleanup_delete_everything) {
    try {
        runStmtHandle($dbHandle, 'DELETE FROM payments WHERE order_id = :oid', [':oid' => $created['order_id']]);
        runStmtHandle($dbHandle, 'DELETE FROM idempotency_keys WHERE key_hash = :k', [':k' => hash('sha256', $idempotencyKey)]);
        runStmtHandle($dbHandle, 'DELETE FROM inventory_reservations WHERE order_id = :oid', [':oid' => $created['order_id']]);
        runStmtHandle($dbHandle, 'DELETE FROM order_items WHERE order_id = :oid', [':oid' => $created['order_id']]);
        runStmtHandle($dbHandle, 'DELETE FROM orders WHERE id = :id', [':id' => $created['order_id']]);
        runStmtHandle($dbHandle, 'DELETE FROM books WHERE id = :id', [':id' => $created['book_id']]);
        runStmtHandle($dbHandle, 'DELETE FROM authors WHERE id = :id', [':id' => $created['author_id']]);
        runStmtHandle($dbHandle, 'DELETE FROM categories WHERE id = :id', [':id' => $created['category_id']]);
    } catch (Throwable $_) {
        // best-effort
    }
}

// Output adapter response + created IDs
echo json_encode([
    'ok' => true,
    'note' => 'This is a temporary web test. Delete the file after use.',
    'created' => $created,
    'idempotency_key' => $idempotencyKey,
    'gopay_response' => $res
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
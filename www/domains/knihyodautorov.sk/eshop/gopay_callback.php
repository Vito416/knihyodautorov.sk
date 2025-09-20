<?php
declare(strict_types=1);

/**
 * /eshop/gopay_callback.php
 *
 * Zpracování notifikací od platební brány (GoPay stub / podobné).
 * - Očekává POST nebo GET (gateway závisí) s minimem: tx (transaction id), order_id, status, signature
 * - Ověří podpis pokud je v $config['payments']['gopay']['secret']
 * - Vyhledá payment z payments.transaction_id nebo podle order_id
 * - Provádí idempotentní aktualizace payments.status a orders.status
 * - Při úspěšné platbě (paid) vytvoří download tokeny pro ebooky (order_item_downloads)
 * - Vrací HTTP 200 a jednoduchý text (gateway-friendly)
 */

require_once __DIR__ . '/inc/bootstrap.php';

// DB getter
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
    echo 'ERROR';
    exit;
}

// helpers for DB operations (compatible with Database wrapper or PDO)
$fetchOne = function(string $sql, array $params = []) use ($dbWrapper, $pdo): ?array {
    if ($dbWrapper !== null && method_exists($dbWrapper, 'fetch')) {
        try { $r = $dbWrapper->fetch($sql, $params); return $r === false ? null : $r; } catch (\Throwable $e) { if (class_exists('Logger')) { try { Logger::systemError($e); } catch (\Throwable $_) {} } return null; }
    }
    $stmt = $pdo->prepare($sql);
    if ($stmt === false) { if (class_exists('Logger')) { try { Logger::systemMessage('error','PDO prepare failed', null, ['sql'=>$sql]); } catch (\Throwable $_) {} } return null; }
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

$fetchAll = function(string $sql, array $params = []) use ($dbWrapper, $pdo): array {
    if ($dbWrapper !== null && method_exists($dbWrapper, 'fetchAll')) {
        try { return (array)$dbWrapper->fetchAll($sql, $params); } catch (\Throwable $e) { if (class_exists('Logger')) { try { Logger::systemError($e); } catch (\Throwable $_) {} } return []; }
    }
    $stmt = $pdo->prepare($sql);
    if ($stmt === false) { if (class_exists('Logger')) { try { Logger::systemMessage('error','PDO prepare failed', null, ['sql'=>$sql]); } catch (\Throwable $_) {} } return []; }
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

// read incoming payload (support form-encoded POST or GET)
$tx = $_POST['tx'] ?? $_GET['tx'] ?? null;
$orderId = $_POST['order_id'] ?? $_GET['order_id'] ?? null;
$status = $_POST['status'] ?? $_GET['status'] ?? null;
$signature = $_POST['signature'] ?? $_GET['signature'] ?? null;

// normalize
$tx = $tx !== null ? (string)$tx : null;
$orderId = $orderId !== null ? (int)$orderId : null;
$status = $status !== null ? strtolower(trim((string)$status)) : null;
$signature = $signature !== null ? (string)$signature : null;

// minimal validation
if (empty($tx) && empty($orderId)) {
    if (class_exists('Logger')) { try { Logger::systemMessage('warning', 'gopay_callback_missing_identifiers', null, ['tx' => $tx, 'order_id' => $orderId]); } catch (\Throwable $_) {} }
    http_response_code(400);
    echo 'MISSING';
    exit;
}

// config-based signature verification (best-effort)
$cfg = $GLOBALS['config'] ?? [];
$gopayCfg = $cfg['payments']['gopay'] ?? ($cfg['payments']['gopay'] ?? []);
$secret = $gopayCfg['secret'] ?? null;
$verified = false;

try {
    if (!empty($secret) && !empty($signature)) {
        // canonical payload used for signature: tx|order_id|status (best-effort; tune to real gateway)
        $payloadToSign = ($tx ?? '') . '|' . ($orderId !== null ? (string)$orderId : '') . '|' . ($status ?? '');
        $calc = hash_hmac('sha256', $payloadToSign, $secret);
        if (hash_equals($calc, $signature)) {
            $verified = true;
        }
    } else {
        // no secret configured -> we cannot verify signatures; log and accept but treat as lower trust
        if (class_exists('Logger')) { try { Logger::systemMessage('warning', 'gopay_callback_no_secret', null, ['tx'=>$tx,'order_id'=>$orderId]); } catch (\Throwable $_) {} }
        $verified = false;
    }
} catch (\Throwable $e) {
    if (class_exists('Logger')) { try { Logger::systemError($e); } catch (\Throwable $_) {} }
    $verified = false;
}

// find payment record: prefer transaction_id, else order_id
$payment = null;
if (!empty($tx)) {
    $payment = $fetchOne('SELECT * FROM payments WHERE transaction_id = :tx LIMIT 1', ['tx' => $tx]);
}
if ($payment === null && $orderId !== null) {
    $payment = $fetchOne('SELECT * FROM payments WHERE order_id = :order_id ORDER BY created_at DESC LIMIT 1', ['order_id' => $orderId]);
}

if ($payment === null) {
    if (class_exists('Logger')) { try { Logger::systemMessage('warning', 'gopay_callback_payment_not_found', null, ['tx'=>$tx,'order_id'=>$orderId]); } catch (\Throwable $_) {} }
    // respond success to avoid gateway retries (but log)
    http_response_code(200);
    echo 'OK';
    exit;
}

// basic idempotency: if payment already marked paid, and incoming status implies paid, reply OK
$currentStatus = strtolower($payment['status'] ?? 'pending');
$incomingPaid = in_array($status, ['paid','success','completed','ok'], true);
$incomingFailed = in_array($status, ['failed','cancelled','rejected','error'], true);

// If payment already paid, ensure order is paid as well and return OK
if ($currentStatus === 'paid') {
    // ensure order status
    try {
        $orderRow = $fetchOne('SELECT id, status FROM orders WHERE id = :id LIMIT 1', ['id' => $payment['order_id']]);
        if ($orderRow && $orderRow['status'] !== 'paid') {
            $exec('UPDATE orders SET status = :s, updated_at = NOW() WHERE id = :id', ['s' => 'paid', 'id' => $orderRow['id']]);
        }
    } catch (\Throwable $_) {}
    http_response_code(200);
    echo 'OK';
    exit;
}

// process update inside transaction
try {
    if ($dbWrapper !== null && method_exists($dbWrapper, 'beginTransaction')) {
        $dbWrapper->beginTransaction();
    } else {
        $pdo->beginTransaction();
    }

    if ($incomingPaid) {
        // mark payment paid, update order paid, grant downloads
        $exec('UPDATE payments SET status = :status, updated_at = NOW() WHERE id = :id', ['status' => 'paid', 'id' => $payment['id']]);
        $exec('UPDATE orders SET status = :status, updated_at = NOW() WHERE id = :id', ['status' => 'paid', 'id' => $payment['order_id']]);

        // grant downloads (create tokens) - idempotent insertion: use unique token so duplicates not created
        $orderItems = $fetchAll('SELECT book_id, quantity FROM order_items WHERE order_id = :order_id', ['order_id' => $payment['order_id']]);
        foreach ($orderItems as $oi) {
            $bookId = (int)$oi['book_id'];
            $assets = $fetchAll('SELECT id, is_encrypted, download_filename, key_id FROM book_assets WHERE book_id = :book_id AND asset_type = \'pdf\'', ['book_id' => $bookId]);
            foreach ($assets as $a) {
                // create a token; ensure not duplicated for same order+asset (we rely on no unique constraint: check existing)
                $exists = $fetchOne('SELECT id FROM order_item_downloads WHERE order_id = :order_id AND asset_id = :asset_id LIMIT 1', ['order_id' => $payment['order_id'], 'asset_id' => $a['id']]);
                if ($exists) continue;
                $token = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
                $expiresAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+30 days')->format('Y-m-d H:i:s');
                $maxUses = 3;
                $exec('INSERT INTO order_item_downloads (order_id, book_id, asset_id, download_token, encryption_key_version, token_key_version, max_uses, used, expires_at) VALUES
                      (:order_id, :book_id, :asset_id, :token, :enc_ver, :tok_ver, :max_uses, 0, :expires_at)', [
                    'order_id' => $payment['order_id'],
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

        // update payment details with gateway payload for audit
        $details = $payment['details'] ?? null;
        $incomingDetails = ['raw' => $_POST ?: $_GET, 'verified' => $verified];
        $newDetails = json_encode(['prev' => $details, 'incoming' => $incomingDetails]);
        $exec('UPDATE payments SET details = :details WHERE id = :id', ['details' => $newDetails, 'id' => $payment['id']]);

    } elseif ($incomingFailed) {
        $exec('UPDATE payments SET status = :status, updated_at = NOW() WHERE id = :id', ['status' => 'failed', 'id' => $payment['id']]);
        $exec('UPDATE orders SET status = :status, updated_at = NOW() WHERE id = :id', ['status' => 'failed', 'id' => $payment['order_id']]);
        // log failure details
        if (class_exists('Logger')) {
            try { Logger::systemMessage('warning', 'gopay_payment_failed', null, ['payment_id' => $payment['id'], 'tx' => $tx, 'status' => $status]); } catch (\Throwable $_) {}
        }
    } else {
        // unknown/other statuses - store as 'pending' and keep details
        $exec('UPDATE payments SET status = :status, updated_at = NOW() WHERE id = :id', ['status' => $status ?? 'pending', 'id' => $payment['id']]);
        if (class_exists('Logger')) {
            try { Logger::systemMessage('info', 'gopay_callback_unknown_status', null, ['payment_id' => $payment['id'], 'tx' => $tx, 'status' => $status, 'verified' => $verified]); } catch (\Throwable $_) {}
        }
    }

    if ($dbWrapper !== null && method_exists($dbWrapper, 'commit')) {
        $dbWrapper->commit();
    } else {
        $pdo->commit();
    }

} catch (\Throwable $e) {
    if ($dbWrapper !== null && method_exists($dbWrapper, 'rollback')) {
        try { $dbWrapper->rollback(); } catch (\Throwable $_) {}
    } else {
        try { $pdo->rollBack(); } catch (\Throwable $_) {}
    }
    if (class_exists('Logger')) { try { Logger::systemError($e, $payment['order_id'] ?? null); } catch (\Throwable $_) {} }
    http_response_code(500);
    echo 'ERROR';
    exit;
}

// respond ok (gateway usually expects 200)
http_response_code(200);
echo 'OK';
exit;
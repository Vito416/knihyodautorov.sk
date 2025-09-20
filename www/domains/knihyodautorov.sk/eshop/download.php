<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';

/**
 * download.php (upravené: FileVault integrácia, opravené logovanie)
 *
 * Bezpečný handler na stiahnutie assetu cez download token.
 * Použije FileVault pre dešifrovanie/streamovanie šifrovaných súborov.
 */

// -------- INIT DB (Database wrapper alebo PDO) ----------
$dbWrapper = null;
$pdo = null;
try {
    if (class_exists('Database') && method_exists('Database', 'getInstance')) {
        $dbInst = Database::getInstance();
        if ($dbInst instanceof \PDO) {
            $pdo = $dbInst;
        } else {
            $dbWrapper = $dbInst;
            if (method_exists($dbWrapper, 'getPdo')) {
                try { $maybe = $dbWrapper->getPdo(); if ($maybe instanceof \PDO) $pdo = $maybe; } catch (\Throwable $_) {}
            }
        }
    }

    if ($pdo === null && isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof \PDO) {
        $pdo = $GLOBALS['pdo'];
    }

    if ($dbWrapper === null && $pdo === null) {
        throw new \RuntimeException('Databázové pripojenie nie je dostupné.');
    }
} catch (\Throwable $e) {
    if (class_exists('Logger')) { try { Logger::systemError($e); } catch (\Throwable $_) {} }
    http_response_code(500);
    echo 'Internal error';
    exit;
}

// --------- DB helpers ----------
$exec = function(string $sql, array $params = []) use (&$dbWrapper, &$pdo) {
    if ($dbWrapper !== null && method_exists($dbWrapper, 'execute')) {
        return $dbWrapper->execute($sql, $params);
    }
    $stmt = $pdo->prepare($sql);
    if ($stmt === false) throw new \RuntimeException('PDO prepare failed');
    foreach ($params as $k => $v) {
        $name = (strpos((string)$k, ':') === 0) ? $k : ':' . $k;
        if ($v === null) $stmt->bindValue($name, null, \PDO::PARAM_NULL);
        elseif (is_int($v)) $stmt->bindValue($name, $v, \PDO::PARAM_INT);
        elseif (is_bool($v)) $stmt->bindValue($name, $v ? 1 : 0, \PDO::PARAM_INT);
        else $stmt->bindValue($name, (string)$v, \PDO::PARAM_STR);
    }
    $stmt->execute();
    return $stmt;
};

$fetchOne = function(string $sql, array $params = []) use (&$dbWrapper, &$pdo, $exec): ?array {
    if ($dbWrapper !== null && method_exists($dbWrapper, 'fetch')) {
        try { $r = $dbWrapper->fetch($sql, $params); return $r === false ? null : $r; } catch (\Throwable $e) { if (class_exists('Logger')) { try { Logger::systemError($e); } catch (\Throwable $_) {} } return null; }
    }
    $stmt = $exec($sql, $params);
    if ($stmt instanceof \PDOStatement) {
        $r = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $r === false ? null : $r;
    }
    return null;
};

// --------- read token ----------
$token = trim((string)($_GET['token'] ?? $_GET['t'] ?? ''));
if ($token === '' || strlen($token) > 512) {
    http_response_code(400);
    echo 'Missing or invalid token';
    exit;
}
if (!preg_match('/^[A-Za-z0-9_\-]+$/', $token)) {
    http_response_code(400);
    echo 'Invalid token';
    exit;
}

// --------- load download row (no increment yet) ----------
try {
    $download = $fetchOne('SELECT oid.*, o.status AS order_status
                           FROM order_item_downloads oid
                           LEFT JOIN orders o ON o.id = oid.order_id
                           WHERE oid.download_token = :token
                           LIMIT 1', ['token' => $token]);

    if (!$download) {
        http_response_code(404);
        echo 'Not found';
        exit;
    }

    // basic checks
    $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

    // expiry
    if (!empty($download['expires_at'])) {
        try {
            $exp = new \DateTimeImmutable($download['expires_at'], new \DateTimeZone('UTC'));
            if ($exp < $now) {
                http_response_code(403);
                echo 'Token expired';
                exit;
            }
        } catch (\Throwable $_) {
            http_response_code(403);
            echo 'Invalid token';
            exit;
        }
    }

    // order must be paid
    $orderStatus = strtolower((string)($download['order_status'] ?? ''));
    if ($orderStatus !== 'paid') {
        http_response_code(403);
        echo 'Order not paid';
        exit;
    }

    // IP check
    if (!empty($download['ip_hash'])) {
        try {
            if (class_exists('Logger') && method_exists('Logger', 'getHashedIp')) {
                $ipRaw = $_SERVER['REMOTE_ADDR'] ?? null;
                if ($ipRaw === null) {
                    http_response_code(403);
                    echo 'IP check failed';
                    exit;
                }
                $ipRes = Logger::getHashedIp($ipRaw);
                $curHash = $ipRes['hash'] ?? null;
                if (!is_string($curHash) || !is_string($download['ip_hash']) || !hash_equals($download['ip_hash'], $curHash)) {
                    http_response_code(403);
                    echo 'IP mismatch';
                    exit;
                }
            }
        } catch (\Throwable $e) {
            if (class_exists('Logger')) { try { Logger::systemError($e); } catch (\Throwable $_) {} }
            http_response_code(403);
            echo 'IP check failed';
            exit;
        }
    }

    // max_uses
    $maxUses = (int)($download['max_uses'] ?? 0);
    $used = (int)($download['used'] ?? 0);
    if ($maxUses > 0 && $used >= $maxUses) {
        http_response_code(403);
        echo 'Token exhausted';
        exit;
    }

    // ---------- atomický increment ----------
    // update only if order still paid and (max_uses = 0 or used < max_uses)
    $updateSql = 'UPDATE order_item_downloads oid
                  JOIN orders o ON o.id = oid.order_id
                  SET oid.used = oid.used + 1, oid.last_used_at = UTC_TIMESTAMP()
                  WHERE oid.download_token = :token
                    AND (oid.max_uses = 0 OR oid.used < oid.max_uses)
                    AND o.status = \'paid\'';
    $res = $exec($updateSql, ['token' => $token]);

    $affected = 0;
    if ($res instanceof \PDOStatement) {
        $affected = $res->rowCount();
    } elseif (is_int($res)) {
        $affected = $res;
    } elseif ($res === true) {
        $ref = $fetchOne('SELECT used, max_uses FROM order_item_downloads WHERE download_token = :token LIMIT 1', ['token' => $token]);
        $affected = ($ref ? 1 : 0);
    } else {
        $ref = $fetchOne('SELECT used, max_uses FROM order_item_downloads WHERE download_token = :token LIMIT 1', ['token' => $token]);
        $affected = ($ref ? 1 : 0);
    }

    if ($affected === 0) {
        http_response_code(403);
        echo 'Token cannot be used (maybe exhausted or order not paid)';
        exit;
    }

    // fetch up-to-date download row
    $download = $fetchOne('SELECT oid.*, o.status AS order_status FROM order_item_downloads oid LEFT JOIN orders o ON o.id = oid.order_id WHERE oid.download_token = :token LIMIT 1', ['token' => $token]);
    if (!$download) {
        http_response_code(500);
        echo 'Internal error';
        exit;
    }

    // fetch asset
    $assetId = (int)$download['asset_id'];
    $asset = $fetchOne('SELECT * FROM book_assets WHERE id = :id LIMIT 1', ['id' => $assetId]);
    if (!$asset) {
        // log with assetId context (assetId is defined even if record missing)
        if (class_exists('Logger')) { try { Logger::systemMessage('error', 'asset_missing_after_increment', null, ['asset_id' => $assetId, 'token' => $token]); } catch (\Throwable $_) {} }
        http_response_code(500);
        echo 'Asset missing';
        exit;
    }

} catch (\Throwable $e) {
    if (class_exists('Logger')) { try { Logger::systemError($e); } catch (\Throwable $_) {} }
    http_response_code(500);
    echo 'Internal error';
    exit;
}

// ---------- FileVault configure (best-effort) ----------
$cfg = $GLOBALS['config'] ?? [];
try {
    if (class_exists('FileVault')) {
        $fvOpts = [];
        if (!empty($cfg['paths']['keys'])) $fvOpts['keys_dir'] = $cfg['paths']['keys'];
        if (!empty($cfg['download']['storage_base_path'])) $fvOpts['storage_base'] = $cfg['download']['storage_base_path'];
        if (!empty($cfg['download']['audit_dir'])) $fvOpts['audit_dir'] = $cfg['download']['audit_dir'];
        if (!empty($pdo)) $fvOpts['audit_pdo'] = $pdo;
        // actor provider: prefer session user_id if available
        $fvOpts['actor_provider'] = function(): ?string {
            return $_SESSION['user_id'] ?? null;
        };
        FileVault::configure($fvOpts);
    }
} catch (\Throwable $_) {
    // non-fatal: FileVault configure failed -> will attempt to use it later only if available
    if (class_exists('Logger')) { try { Logger::systemMessage('warning', 'filevault_configure_failed', null, []); } catch (\Throwable $_) {} }
}

// ---------- prepare serving ----------
$storagePath = $asset['storage_path'] ?? null;
$filename = $asset['download_filename'] ?? $asset['filename'] ?? 'download.bin';
$mime = $asset['mime_type'] ?? 'application/octet-stream';
$isEncrypted = ((int)($asset['is_encrypted'] ?? 0) === 1);

// sanitize filename
$cleanName = preg_replace('/[\\r\\n"]+/', '_', (string)$filename);
$cleanName = basename($cleanName);
$filenameStar = rawurlencode($cleanName);

// optional storage base-guard
$basePath = $cfg['download']['storage_base_path'] ?? null;
if (!empty($basePath) && !empty($storagePath)) {
    $realBase = realpath($basePath);
    $realPath = realpath($storagePath);
    if ($realBase === false || $realPath === false || strpos($realPath, $realBase) !== 0) {
        http_response_code(404);
        echo 'Asset not available';
        exit;
    }
}

// if FileVault should be used: either asset flagged encrypted OR .meta present
$metaPath = is_string($storagePath) ? ($storagePath . '.meta') : null;
$useFileVault = false;
if ($isEncrypted) $useFileVault = true;
elseif (!empty($metaPath) && is_readable($metaPath)) $useFileVault = true;

// ---------- serve via FileVault if requested ----------
if ($useFileVault && class_exists('FileVault')) {
    // prefer FileVault::decryptAndStream which streams and audits
    $ok = FileVault::decryptAndStream($storagePath, $cleanName, $mime);
    if ($ok) {
        if (class_exists('Logger')) { try { Logger::systemMessage('info','download_filevault',null,['asset_id'=>$assetId,'path'=>$storagePath]); } catch (\Throwable $_) {} }
        exit;
    } else {
        if (class_exists('Logger')) { try { Logger::systemMessage('error','filevault_stream_failed',null,['asset_id'=>$assetId,'path'=>$storagePath]); } catch (\Throwable $_) {} }
        http_response_code(500);
        echo 'Decryption/stream failed';
        exit;
    }
}

// ---------- fallback: non-encrypted local file streaming / X-Sendfile ----------
if (!empty($storagePath) && is_string($storagePath) && file_exists($storagePath) && is_readable($storagePath)) {
    $filesize = filesize($storagePath);
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . addslashes($cleanName) . '"; filename*=UTF-8\'\'' . $filenameStar);
    header('Content-Length: ' . (string)$filesize);
    header('Accept-Ranges: none');

    // prefer X-Accel-Redirect (nginx)
    if (!empty($cfg['download']['x_accel_internal']) && !empty($cfg['download']['x_accel_prefix'])) {
        header('X-Accel-Redirect: ' . $cfg['download']['x_accel_prefix'] . $storagePath);
        if (class_exists('Logger')) { try { Logger::systemMessage('info','download_x_accel',null,['asset_id'=>$assetId,'path'=>$storagePath]); } catch (\Throwable $_) {} }
        exit;
    }

    if (!empty($cfg['download']['x_sendfile_header'])) {
        header($cfg['download']['x_sendfile_header'] . ': ' . $storagePath);
        if (class_exists('Logger')) { try { Logger::systemMessage('info','download_x_sendfile',null,['asset_id'=>$assetId,'path'=>$storagePath]); } catch (\Throwable $_) {} }
        exit;
    }

    // fallback PHP streaming (chunked)
    while (ob_get_level()) ob_end_clean();
    set_time_limit(0);
    $fp = @fopen($storagePath, 'rb');
    if ($fp === false) {
        if (class_exists('Logger')) { try { Logger::systemMessage('error','download_open_failed',null,['asset_id'=>$assetId,'path'=>$storagePath]); } catch (\Throwable $_) {} }
        http_response_code(500);
        echo 'Unable to open file';
        exit;
    }
    $threshold = 8 * 1024 * 1024;
    if ($filesize > 0 && $filesize <= $threshold) {
        fclose($fp);
        readfile($storagePath);
        exit;
    }
    $chunkSize = 8 * 1024 * 1024;
    while (!feof($fp)) {
        $buf = fread($fp, $chunkSize);
        if ($buf === false) break;
        echo $buf;
        flush();
    }
    fclose($fp);
    if (class_exists('Logger')) { try { Logger::systemMessage('info','download_streamed',null,['asset_id'=>$assetId,'path'=>$storagePath]); } catch (\Throwable $_) {} }
    exit;
}

// if we reach here: file not accessible
http_response_code(404);
echo 'Asset missing';
exit;
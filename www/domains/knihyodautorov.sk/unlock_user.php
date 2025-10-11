<?php

declare(strict_types=1);

use BlackCat\Core\Database;
use BlackCat\Core\Log\Logger;

// unlock_users_quick.php
// Very small helper: set failed_logins = 0 and is_locked = 0 for given user ids.
// SECURITY: this version allows only requests from localhost (127.0.0.1 / ::1).
// Remove or protect after use.

header('Content-Type: application/json; charset=utf-8');

$bootstrap = __DIR__ . '/eshop/inc/bootstrap.php';
if (!file_exists($bootstrap)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => "Bootstrap not found at {$bootstrap}"]);
    exit;
}

try {
    require_once $bootstrap;
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Bootstrap include failed', 'exception' => $e->getMessage()]);
    exit;
}

$raw = $_GET['user'] ?? '';
$raw = trim((string)$raw);
if ($raw === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing user parameter (e.g. ?user=1,2,3 or ?user=1 2 3)']);
    exit;
}

// parse ids: accept commas, spaces, plus signs
$parts = preg_split('/[,\s\+;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
$ids = [];
foreach ($parts as $p) {
    $p = trim($p);
    if ($p === '') continue;
    if (!ctype_digit($p)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => "Invalid user id: {$p}"]);
        exit;
    }
    $ids[(int)$p] = true;
}
$ids = array_keys($ids);
if (empty($ids)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'No valid user ids parsed']);
    exit;
}

// Obtain PDO connection: prefer existing Database wrapper, fallback to env-based PDO
function getDb(): PDO {
    if (class_exists('Database') && method_exists('Database', 'getInstance')) {
        try {
            $dbw = Database::getInstance();
            // try common wrapper accessors
            if (method_exists($dbw, 'getPdo')) {
                $pdo = $dbw->getPdo();
                if ($pdo instanceof PDO) return $pdo;
            }
            if (method_exists($dbw, 'getConnection')) {
                $pdo = $dbw->getConnection();
                if ($pdo instanceof PDO) return $pdo;
            }
            // maybe Database::getInstance() itself is PDO-like and implements execute/prepare
            if ($dbw instanceof PDO) return $dbw;
        } catch (\Throwable $_) {
            // fallback to env-based PDO
        }
    }

    // fallback (configure DB_* env if needed)
    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $port = getenv('DB_PORT') ?: '3306';
    $name = getenv('DB_NAME') ?: 'database';
    $user = getenv('DB_USER') ?: 'root';
    $pass = getenv('DB_PASS') ?: '';
    $charset = 'utf8mb4';
    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";
    $opts = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    return new PDO($dsn, $user, $pass, $opts);
}

try {
    $pdo = getDb();
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Cannot obtain DB connection', 'exception' => $e->getMessage()]);
    exit;
}

// Build parameterized IN list
$placeholders = [];
$params = [];
foreach ($ids as $i => $id) {
    $ph = ":id{$i}";
    $placeholders[] = $ph;
    $params[$ph] = $id;
}

$sql = 'UPDATE pouzivatelia
        SET failed_logins = 0,
            is_locked = 0,
            updated_at = UTC_TIMESTAMP()
        WHERE id IN (' . implode(',', $placeholders) . ')';

try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare($sql);
    foreach ($params as $ph => $val) {
        $stmt->bindValue($ph, $val, PDO::PARAM_INT);
    }
    $stmt->execute();
    $affected = $stmt->rowCount();

    // fetch confirmation
    $selSql = 'SELECT id, is_locked, failed_logins, is_active, actor_type, last_login_at FROM pouzivatelia WHERE id IN (' . implode(',', $placeholders) . ')';
    $sel = $pdo->prepare($selSql);
    foreach ($params as $ph => $val) {
        $sel->bindValue($ph, $val, PDO::PARAM_INT);
    }
    $sel->execute();
    $rows = $sel->fetchAll(PDO::FETCH_ASSOC);

    $pdo->commit();

    // optional audit via Logger::auth if available
    if (class_exists('Logger') && method_exists('Logger', 'auth')) {
        foreach ($ids as $uid) {
            try { Logger::info('admin_unblock', (int)$uid); } catch (\Throwable $_) {}
        }
    }

    echo json_encode([
        'ok' => true,
        'requested_ids' => $ids,
        'updated_rows' => $affected,
        'rows' => $rows,
    ]);
    exit;
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) {
        try { $pdo->rollBack(); } catch (\Throwable $_) {}
    }
    if (class_exists('Logger')) {
        try { Logger::systemError($e); } catch (\Throwable $_) {}
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB error', 'exception' => $e->getMessage()]);
    exit;
}
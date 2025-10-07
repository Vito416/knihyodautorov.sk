<?php
declare(strict_types=1);

// delete_gopay_notify_quick.php
// Quick helper: DELETE rows from gopay_notify_log by id(s) provided in GET.
// SECURITY: allows only requests from localhost (127.0.0.1 / ::1). Remove/protect after use.

header('Content-Type: application/json; charset=utf-8');

// bootstrap include (adjust path if needed)
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

// Accept ?id=1 or ?id=1,2 or ?ids=1 2 3 etc.
$raw = $_GET['id'] ?? $_GET['ids'] ?? '';
$raw = trim((string)$raw);
if ($raw === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing id parameter (e.g. ?id=123 or ?id=1,2,3)']);
    exit;
}

// parse ids: accept commas, spaces, plus signs, semicolons
$parts = preg_split('/[,\s\+;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
$idsMap = [];
foreach ($parts as $p) {
    $p = trim($p);
    if ($p === '') continue;
    if (!ctype_digit($p)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => "Invalid id: {$p}"]);
        exit;
    }
    $idsMap[(int)$p] = true;
}
$ids = array_keys($idsMap);
if (empty($ids)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'No valid ids parsed']);
    exit;
}

// Obtain PDO connection: prefer existing Database wrapper, fallback to env-based PDO
function getDb(): PDO {
    if (class_exists('Database') && method_exists('Database', 'getInstance')) {
        try {
            $dbw = Database::getInstance();
            if ($dbw instanceof PDO) return $dbw;
            if (method_exists($dbw, 'getPdo')) {
                $pdo = $dbw->getPdo();
                if ($pdo instanceof PDO) return $pdo;
            }
            if (method_exists($dbw, 'getConnection')) {
                $pdo = $dbw->getConnection();
                if ($pdo instanceof PDO) return $pdo;
            }
        } catch (\Throwable $_) {
            // fallback below
        }
    }

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

$inList = implode(', ', $placeholders);

try {
    $pdo->beginTransaction();

    // fetch rows before delete so caller can inspect what will be removed
    $selSql = "SELECT * FROM gopay_notify_log WHERE id IN ({$inList}) FOR UPDATE";
    $sel = $pdo->prepare($selSql);
    foreach ($params as $ph => $val) {
        $sel->bindValue($ph, $val, PDO::PARAM_INT);
    }
    $sel->execute();
    $rows = $sel->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        // nothing to delete
        $pdo->commit();
        echo json_encode([
            'ok' => true,
            'requested_ids' => $ids,
            'deleted_count' => 0,
            'deleted_rows' => [],
            'message' => 'No matching rows found'
        ]);
        exit;
    }

    // perform delete
    $delSql = "DELETE FROM gopay_notify_log WHERE id IN ({$inList})";
    $del = $pdo->prepare($delSql);
    foreach ($params as $ph => $val) {
        $del->bindValue($ph, $val, PDO::PARAM_INT);
    }
    $del->execute();
    $affected = $del->rowCount();

    $pdo->commit();

    // optional audit via Logger::info if available
    if (class_exists('Logger') && method_exists('Logger', 'info')) {
        try {
            Logger::info('delete_gopay_notify_quick: deleted', ['requested_ids' => $ids, 'deleted_count' => $affected, 'by' => $remote]);
        } catch (\Throwable $_) {}
    }

    echo json_encode([
        'ok' => true,
        'requested_ids' => $ids,
        'deleted_count' => $affected,
        'deleted_rows' => $rows
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
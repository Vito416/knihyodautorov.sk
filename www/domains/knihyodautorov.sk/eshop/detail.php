<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';

/**
 * Detail produktu (knihy)
 *
 * - očekává GET param id (integer)
 * - kompatibilní s DB: books, authors, categories, book_assets
 * - neprovádí žádné autorizace (download/checkout je zvlášť)
 */

// DB getter: prefer Database wrapper, jinak použij PDO z bootstrapu / global
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

// helper fetchOne (Database wrapper or PDO)
$fetchOne = function(string $sql, array $params = []) use ($dbWrapper, $pdo): ?array {
    if ($dbWrapper !== null && method_exists($dbWrapper, 'fetch')) {
        try { $r = $dbWrapper->fetch($sql, $params); return $r === false ? null : $r; }
        catch (\Throwable $e) { if (class_exists('Logger')) { try { Logger::systemError($e); } catch (\Throwable $_) {} } return null; }
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

// helper fetchAll
$fetchAll = function(string $sql, array $params = []) use ($dbWrapper, $pdo): array {
    if ($dbWrapper !== null && method_exists($dbWrapper, 'fetchAll')) {
        try { return (array)$dbWrapper->fetchAll($sql, $params); }
        catch (\Throwable $e) { if (class_exists('Logger')) { try { Logger::systemError($e); } catch (\Throwable $_) {} } return []; }
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

// read and validate id
$idRaw = $_GET['id'] ?? null;
if ($idRaw === null) {
    http_response_code(404);
    echo Templates::render('pages/404.php');
    exit;
}
$id = (int)$idRaw;
if ($id <= 0) {
    http_response_code(404);
    echo Templates::render('pages/404.php');
    exit;
}
// optional stricter validator
if (class_exists('Validator') && method_exists('Validator', 'validateNumberInRange')) {
    if (!Validator::validateNumberInRange((string)$id, 1, PHP_INT_MAX)) {
        http_response_code(404);
        echo Templates::render('pages/404.php');
        exit;
    }
}

// fetch book (ensure active)
$sql = "
SELECT b.*, a.meno AS author_name, c.nazov AS category_name, c.slug AS category_slug
FROM books b
LEFT JOIN authors a ON a.id = b.author_id
LEFT JOIN categories c ON c.id = b.main_category_id
WHERE b.id = :id AND b.is_active = 1
LIMIT 1
";
$book = $fetchOne($sql, ['id' => $id]);
if ($book === null) {
    // not found or inactive
    http_response_code(404);
    echo Templates::render('pages/404.php');
    exit;
}

// fetch assets (pdf, cover, sample, etc.)
$assets = $fetchAll('SELECT id, asset_type, filename, storage_path, mime_type, is_encrypted, download_filename, key_id FROM book_assets WHERE book_id = :book_id', ['book_id' => $id]);

// normalize types & prepare flags
$hasPdf = false;
$pdfAsset = null;
$cover = null;
$samples = [];
foreach ($assets as $a) {
    $a['id'] = isset($a['id']) ? (int)$a['id'] : null;
    $a['is_encrypted'] = ((int)($a['is_encrypted'] ?? 0) === 1);
    if ($a['asset_type'] === 'pdf') {
        $hasPdf = true;
        $pdfAsset = $a;
    } elseif ($a['asset_type'] === 'cover') {
        $cover = $a;
    } elseif ($a['asset_type'] === 'sample') {
        $samples[] = $a;
    }
}

// prepare data for template (do not expose internal file system path)
// for downloads we expose download endpoint with asset id; endpoint will verify ownership/rights
$bookForTpl = [
    'id' => (int)$book['id'],
    'title' => $book['title'] ?? '',
    'slug' => $book['slug'] ?? '',
    'description' => $book['description'] ?? '',
    'price' => isset($book['price']) ? (float)$book['price'] : 0.0,
    'currency' => $book['currency'] ?? '',
    'is_available' => ((int)($book['is_available'] ?? 0) === 1),
    'stock_quantity' => isset($book['stock_quantity']) ? (int)$book['stock_quantity'] : 0,
    'author_name' => $book['author_name'] ?? '',
    'category_name' => $book['category_name'] ?? '',
    'category_slug' => $book['category_slug'] ?? null,
    'cover_url' => $cover['storage_path'] ?? ($cover['filename'] ?? null),
];

// For security, do not include raw storage_path in template for direct download; instead link to download handler:
$downloadLink = null;
if ($pdfAsset !== null) {
    // link example: ?route=download&asset_id=123
    $downloadLink = '?route=download&asset_id=' . (int)$pdfAsset['id'];
}

try {
    echo Templates::render('pages/detail.php', [
        'book' => $bookForTpl,
        'hasPdf' => $hasPdf,
        'pdfAsset' => $pdfAsset ? ['id' => (int)$pdfAsset['id'], 'is_encrypted' => $pdfAsset['is_encrypted'], 'download_url' => $downloadLink, 'download_filename' => $pdfAsset['download_filename'] ?? null] : null,
        'samples' => $samples,
        'cover' => $cover,
    ]);
} catch (\Throwable $e) {
    if (class_exists('Logger')) { try { Logger::systemError($e, $bookForTpl['id'] ?? null); } catch (\Throwable $_) {} }
    http_response_code(500);
    echo Templates::render('pages/error.php', ['message' => 'Unable to render product detail']);
    exit;
}
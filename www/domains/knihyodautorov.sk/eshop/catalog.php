<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';

$perPageDefault = 20;

// get DB: prefer Database wrapper, otherwise PDO from bootstrap (named $pdo)
$dbWrapper = null;
$pdo = null;
try {
    if (class_exists('Database') && method_exists('Database', 'getInstance')) {
        $dbWrapper = Database::getInstance();
    } elseif (isset($pdo) && $pdo instanceof \PDO) {
        $pdo = $pdo;
    } elseif (isset($DB_PDO) && $DB_PDO instanceof \PDO) {
        $pdo = $DB_PDO;
    } else {
        if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof \PDO) {
            $pdo = $GLOBALS['pdo'];
        } else {
            throw new \RuntimeException('Database connection not available.');
        }
    }
} catch (\Throwable $e) {
    if (class_exists('Logger') && method_exists('Logger', 'systemError')) {
        try { Logger::systemError($e); } catch (\Throwable $_) {}
    }
    http_response_code(500);
    echo Templates::render('pages/error.php', ['message' => 'Internal server error (DB).']);
    exit;
}

/* fetch helpers (same as předtím) */
$fetchAll = function(string $sql, array $params = []) use ($dbWrapper, $pdo) : array {
    if ($dbWrapper !== null && method_exists($dbWrapper, 'fetchAll')) {
        try {
            return (array) $dbWrapper->fetchAll($sql, $params);
        } catch (\Throwable $e) {
            if (class_exists('Logger')) { try { Logger::systemError($e); } catch (\Throwable $_) {} }
            return [];
        }
    }

    $stmt = $pdo->prepare($sql);
    if ($stmt === false) {
        if (class_exists('Logger')) { try { Logger::systemMessage('error', 'PDO prepare failed', null, ['sql' => $sql]); } catch (\Throwable $_) {} }
        return [];
    }
    foreach ($params as $k => $v) {
        $name = (strpos((string)$k, ':') === 0) ? $k : ':' . $k;
        if (is_int($v)) {
            $stmt->bindValue($name, $v, \PDO::PARAM_INT);
        } elseif (is_bool($v)) {
            $stmt->bindValue($name, $v ? 1 : 0, \PDO::PARAM_INT);
        } elseif ($v === null) {
            $stmt->bindValue($name, null, \PDO::PARAM_NULL);
        } else {
            $stmt->bindValue($name, (string)$v, \PDO::PARAM_STR);
        }
    }

    $stmt->execute();
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    return $rows === false ? [] : $rows;
};

$fetchOne = function(string $sql, array $params = []) use ($dbWrapper, $pdo) : ?array {
    if ($dbWrapper !== null && method_exists($dbWrapper, 'fetch')) {
        try {
            $r = $dbWrapper->fetch($sql, $params);
            return $r === false ? null : $r;
        } catch (\Throwable $e) {
            if (class_exists('Logger')) { try { Logger::systemError($e); } catch (\Throwable $_) {} }
            return null;
        }
    }

    $stmt = $pdo->prepare($sql);
    if ($stmt === false) {
        if (class_exists('Logger')) { try { Logger::systemMessage('error', 'PDO prepare failed', null, ['sql' => $sql]); } catch (\Throwable $_) {} }
        return null;
    }
    foreach ($params as $k => $v) {
        $name = (strpos((string)$k, ':') === 0) ? $k : ':' . $k;
        if (is_int($v)) {
            $stmt->bindValue($name, $v, \PDO::PARAM_INT);
        } elseif (is_bool($v)) {
            $stmt->bindValue($name, $v ? 1 : 0, \PDO::PARAM_INT);
        } elseif ($v === null) {
            $stmt->bindValue($name, null, \PDO::PARAM_NULL);
        } else {
            $stmt->bindValue($name, (string)$v, \PDO::PARAM_STR);
        }
    }
    $stmt->execute();
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
};

// read & validate inputs
$page = (int)($_GET['p'] ?? 1);
if (class_exists('Validator') && method_exists('Validator', 'validateNumberInRange')) {
    if (!Validator::validateNumberInRange((string)$page, 1, 1000000)) {
        $page = 1;
    }
} else {
    if ($page < 1) $page = 1;
}

$perPage = (int)($_GET['per'] ?? $perPageDefault);
if ($perPage < 1 || $perPage > 200) $perPage = $perPageDefault;

$offset = ($page - 1) * $perPage;

$categorySlug = isset($_GET['cat']) ? trim((string)$_GET['cat']) : null;
$categoryId = null;
if ($categorySlug !== null && $categorySlug !== '') {
    if (!preg_match('/^[a-z0-9\\-\\_]+$/i', $categorySlug)) {
        $categorySlug = null;
    } else {
        $catRow = $fetchOne('SELECT id, nazov FROM categories WHERE slug = :slug LIMIT 1', ['slug' => $categorySlug]);
        if (is_array($catRow)) {
            $categoryId = (int)$catRow['id'];
        } else {
            $categoryId = null;
            $categorySlug = null;
        }
    }
}

// fetch categories for sidebar / top links
$categories = $fetchAll('SELECT id, nazov, slug FROM categories ORDER BY nazov ASC', []);

// build WHERE
$where = 'b.is_active = 1';
$params = [];
if ($categoryId !== null) {
    $where .= ' AND b.main_category_id = :category_id';
    $params['category_id'] = $categoryId;
}

// fetch total count for pagination
$countSql = 'SELECT COUNT(*) AS cnt FROM books b WHERE ' . $where;
$countRow = $fetchOne($countSql, $params);
$total = isset($countRow['cnt']) ? (int)$countRow['cnt'] : 0;
$totalPages = (int) max(1, ceil($total / max(1, $perPage)));

// fetch page of books including author, category and optional cover asset
$sql = "
SELECT
  b.id, b.title, b.slug, b.description, b.price, b.currency, b.is_available, b.stock_quantity,
  a.id AS author_id, a.meno AS author_name,
  c.id AS category_id, c.nazov AS category_name,
  ba.filename AS cover_filename, ba.storage_path AS cover_path
FROM books b
LEFT JOIN authors a ON a.id = b.author_id
LEFT JOIN categories c ON c.id = b.main_category_id
LEFT JOIN book_assets ba ON ba.book_id = b.id AND ba.asset_type = 'cover'
WHERE {$where}
ORDER BY b.title ASC
LIMIT :limit OFFSET :offset
";

$paramsWithLimit = $params;
$paramsWithLimit['limit'] = $perPage;
$paramsWithLimit['offset'] = $offset;

$books = $fetchAll($sql, $paramsWithLimit);

foreach ($books as &$b) {
    $b['id'] = (int)($b['id'] ?? 0);
    $b['price'] = isset($b['price']) ? (float)$b['price'] : 0.0;
    $b['stock_quantity'] = isset($b['stock_quantity']) ? (int)$b['stock_quantity'] : 0;
    $b['is_available'] = (int)($b['is_available'] ?? 0) === 1;
    $b['author_name'] = $b['author_name'] ?? '';
    $b['category_name'] = $b['category_name'] ?? '';
    if (!empty($b['cover_path'])) {
        $b['cover_url'] = $b['cover_path'];
    } elseif (!empty($b['cover_filename'])) {
        $b['cover_url'] = '/files/' . ltrim($b['cover_filename'], '/');
    } else {
        $b['cover_url'] = null;
    }
}
unset($b);

try {
    echo Templates::render('pages/catalog.php', [
        'books' => $books,
        'page' => $page,
        'perPage' => $perPage,
        'total' => $total,
        'totalPages' => $totalPages,
        'currentCategory' => $categorySlug,
        'categories' => $categories,
    ]);
} catch (\Throwable $e) {
    if (class_exists('Logger') && method_exists('Logger', 'systemError')) {
        try { Logger::systemError($e); } catch (\Throwable $_) {}
    }
    http_response_code(500);
    echo Templates::render('pages/error.php', ['message' => 'Unable to render catalog']);
    exit;
}
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
        $pdo = $dbWrapper->getPdo();
    } else {
        throw new \RuntimeException('Database connection not available.');
    }
} catch (\Throwable $e) {
    if (class_exists('Logger') && method_exists('Logger', 'systemError')) {
        try { Logger::systemError($e); } catch (\Throwable $_) {}
    }
    http_response_code(500);
    echo Templates::render('pages/error.php', ['message' => 'Internal server error (DB).']);
    exit;
}

/* --- fetch helpers (kopírované / kompatibilné s vaším prostredím) --- */
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

/* --- čítanie a validácia vstupov --- */
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
    if (!preg_match('/^[a-z0-9\-\_]+$/i', $categorySlug)) {
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

// search 'q' (title, short_description, author name)
$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$hasSearch = $q !== '';
$searchParam = '%' . str_replace('%', '\\%', $q) . '%';

// sort
$sort = $_GET['sort'] ?? '';
$sortSql = 'b.title ASC';
if ($sort === 'price_asc') $sortSql = 'b.price ASC, b.title ASC';
elseif ($sort === 'price_desc') $sortSql = 'b.price DESC, b.title ASC';
elseif ($sort === 'newest') $sortSql = 'b.created_at DESC, b.title ASC';

/* --- načítanie kategórií pre sidebar --- */
$categories = $fetchAll('SELECT id, nazov, slug FROM categories ORDER BY nazov ASC', []);

/* --- zostavenie WHERE --- */
$whereParts = ['b.is_active = 1'];
$params = [];

if ($categoryId !== null) {
    // Podporujeme filtering podľa hlavnej kategórie (main_category_id) alebo cez book_categories (ak chcete)
    $whereParts[] = 'b.main_category_id = :category_id';
    $params['category_id'] = $categoryId;
}

if ($hasSearch) {
    // hľadaj v názve knihy, krátky popis alebo v mene autora
    $whereParts[] = '(b.title LIKE :q OR b.short_description LIKE :q OR a.meno LIKE :q)';
    $params['q'] = $searchParam;
}

$where = implode(' AND ', $whereParts);

/* --- total count pre stránkovanie --- */
$countSql = "SELECT COUNT(DISTINCT b.id) AS cnt
             FROM books b
             LEFT JOIN authors a ON a.id = b.author_id
             WHERE {$where}";
$countRow = $fetchOne($countSql, $params);
$total = isset($countRow['cnt']) ? (int)$countRow['cnt'] : 0;
$totalPages = (int) max(1, ceil($total / max(1, $perPage)));

/* --- hlavný SQL: vyber knihy + autor + (primárna) kategória + cover (vyberie sa MIN(id) pre cover) --- */
/**
 * Poznámka:
 * - subquery v LEFT JOIN pre ba zabezpečí, že ak existuje viacero cover záznamov,
 *   použije sa ten s najmenším id (stabilný deterministický výber).
 */
$sql = "
SELECT
  b.id, b.title, b.slug,
  COALESCE(b.short_description, b.full_description, '') AS description,
  b.price, b.currency, b.is_available, b.stock_quantity, b.created_at,
  a.id AS author_id, a.meno AS author_name,
  c.id AS category_id, c.nazov AS category_name,
  ba.filename AS cover_filename, ba.storage_path AS cover_path
FROM books b
LEFT JOIN authors a ON a.id = b.author_id
LEFT JOIN categories c ON c.id = b.main_category_id
-- pick single cover per book using MIN(id)
LEFT JOIN book_assets ba ON ba.id = (
    SELECT MIN(id) FROM book_assets WHERE book_id = b.id AND asset_type = 'cover'
)
WHERE {$where}
GROUP BY b.id
ORDER BY {$sortSql}
LIMIT :limit OFFSET :offset
";

/* bind params + pagination (limit/offset must be integers) */
$paramsWithLimit = $params;
$paramsWithLimit['limit'] = $perPage;
$paramsWithLimit['offset'] = $offset;

/* execute */
$books = $fetchAll($sql, $paramsWithLimit);

/* normalize & build cover_url */
foreach ($books as &$b) {
    $b['id'] = (int)($b['id'] ?? 0);
    $b['price'] = isset($b['price']) ? (float)$b['price'] : 0.0;
    $b['stock_quantity'] = isset($b['stock_quantity']) ? (int)$b['stock_quantity'] : 0;
    $b['is_available'] = ((int)($b['is_available'] ?? 0) === 1);
    $b['author_name'] = $b['author_name'] ?? '';
    $b['category_name'] = $b['category_name'] ?? '';
    // prefer storage_path (absolute/internal path), potom filename fallback
    if (!empty($b['cover_path'])) {
        // path bude URL-encoded a předána proxy cover.php
        $b['cover_url'] = '/cover.php?path=' . rawurlencode($b['cover_path']);
    } elseif (!empty($b['cover_filename'])) {
        $b['cover_url'] = '/files/' . ltrim($b['cover_filename'], '/');
    } else {
        $b['cover_url'] = null;
    }
}
unset($b);

/* render via Templates (vaša šablóna pages/catalog.php) */
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
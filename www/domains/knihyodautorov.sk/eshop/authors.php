<?php
declare(strict_types=1);

$perPageDefault = 20;

try {
    $database = Database::getInstance();
} catch (\Throwable $e) {
    if (class_exists('Logger')) { try { Logger::systemError($e); } catch (\Throwable $_) {} }
    http_response_code(500);
    echo Templates::render('pages/error.php', ['message' => 'Internal server error (DB).']);
    exit;
}

/* --- input & validation --- */
$page = (int)($_GET['p'] ?? 1);
if ($page < 1) $page = 1;

$perPage = (int)($_GET['per'] ?? $perPageDefault);
if ($perPage < 1 || $perPage > 200) $perPage = $perPageDefault;

/* --- search --- */
$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$hasSearch = $q !== '';

// escape LIKE special chars (\ % _)
if ($hasSearch) {
    $esc = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);
    $searchParam = '%' . $esc . '%';
}

/* --- sort --- */
$sort = $_GET['sort'] ?? '';
$sortSql = 'a.meno ASC';
if ($sort === 'newest') $sortSql = 'a.created_at DESC, a.meno ASC';
elseif ($sort === 'books_desc') $sortSql = 'a.books_count DESC, a.meno ASC';
elseif ($sort === 'rating_desc') $sortSql = 'a.avg_rating DESC, a.meno ASC';

/* --- sidebar categories (cached) --- */
try {
    $categories = $database->cachedFetchAll('SELECT id, nazov, slug FROM categories ORDER BY nazov ASC');
} catch (\Throwable $e) {
    if (class_exists('Logger')) { try { Logger::warn('Failed to fetch categories for authors', null, ['exception' => (string)$e]); } catch (\Throwable $_) {} }
    $categories = [];
}

/* --- build WHERE and params --- */
$whereParts = ['1=1'];
$params = [];

if ($hasSearch) {
    $whereParts[] = '(a.meno LIKE :q ESCAPE \'\\\' OR a.bio LIKE :q ESCAPE \'\\\' OR a.story LIKE :q ESCAPE \'\\\')';
    $params['q'] = $searchParam;
}

$where = implode(' AND ', $whereParts);

/* --- main SQL
   - výber autora + jednoduchý subquery pre jeden cover (najmenší id cover pre ktorúkoľvek knihu autora)
   - žiadne GROUP BY
*/
$sql = "
SELECT
  a.id, a.meno, a.slug, a.bio, a.foto, a.story,
  a.books_count, a.ratings_count, a.rating_sum, a.avg_rating, a.last_rating_at,
  a.created_at, a.updated_at,
  (
    SELECT ba.storage_path
    FROM book_assets ba
    JOIN books bb ON bb.id = ba.book_id
    WHERE bb.author_id = a.id AND ba.asset_type = 'cover'
    ORDER BY ba.id ASC
    LIMIT 1
  ) AS cover_path,
  (
    SELECT ba.filename
    FROM book_assets ba
    JOIN books bb ON bb.id = ba.book_id
    WHERE bb.author_id = a.id AND ba.asset_type = 'cover'
    ORDER BY ba.id ASC
    LIMIT 1
  ) AS cover_filename
FROM authors a
WHERE {$where}
ORDER BY {$sortSql}
";

/* --- paginate (Database::paginate handles count+limit/offset) --- */
try {
    $pageData = $database->paginate($sql, $params, $page, $perPage, null);
    $authors = $pageData['items'];
    $total = $pageData['total'];
    $totalPages = (int) max(1, ceil($total / max(1, $perPage)));
} catch (\Throwable $e) {
    if (class_exists('Logger')) { try { Logger::systemError($e, null, ['phase' => 'authors.fetch']); } catch (\Throwable $_) {} }
    http_response_code(500);
    echo Templates::render('pages/error.php', ['message' => 'Internal server error (authors).']);
    exit;
}

/* --- normalize results and build cover_url --- */
foreach ($authors as &$a) {
    $a['id'] = (int)($a['id'] ?? 0);
    $a['books_count'] = isset($a['books_count']) ? (int)$a['books_count'] : 0;
    $a['ratings_count'] = isset($a['ratings_count']) ? (int)$a['ratings_count'] : 0;
    $a['rating_sum'] = isset($a['rating_sum']) ? (int)$a['rating_sum'] : 0;
    $a['avg_rating'] = isset($a['avg_rating']) ? (float)$a['avg_rating'] : null;
    $a['last_rating_at'] = $a['last_rating_at'] ?? null;

    // prefer cover_path (proxy via cover.php) else filename fallback in /files/
    if (!empty($a['cover_path'])) {
        // Poznámka: cover.php by mal validovať & sandboxovať path; ideálnejšie je používať asset_id
        $a['cover_url'] = '/cover.php?path=' . rawurlencode($a['cover_path']);
    } elseif (!empty($a['cover_filename'])) {
        $a['cover_url'] = '/files/' . ltrim($a['cover_filename'], '/');
    } else {
        $a['cover_url'] = null;
    }
}
unset($a);

/* --- return template + vars to index.php --- */
return [
    'template' => 'pages/authors.php',
    'vars' => [
        'authors' => $authors,
        'page' => $page,
        'perPage' => $perPage,
        'total' => $total,
        'totalPages' => $totalPages,
        'q' => $q,
        'sort' => $sort,
        'categories' => $categories,
    ],
];
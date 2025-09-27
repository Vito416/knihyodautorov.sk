<?php
declare(strict_types=1);

$perPageDefault = 12; // knih na stránku v detailu autora

try {
    $database = Database::getInstance();
} catch (\Throwable $e) {
    if (class_exists('Logger')) { try { Logger::systemError($e); } catch (\Throwable $_) {} }
    return ['template' => 'pages/error.php', 'vars' => ['message' => 'Internal server error (DB).']];
}

/* --- identifier: prefer slug, fallback id --- */
$slug = isset($_GET['slug']) ? trim((string)$_GET['slug']) : null;
$idParam = isset($_GET['id']) ? (int)$_GET['id'] : null;

if ($slug === '') $slug = null;
if ($slug !== null && !preg_match('/^[a-z0-9\-\_]+$/i', $slug)) {
    $slug = null;
}

/* --- pagination inputs --- */
$page = (int)($_GET['p'] ?? 1);
if ($page < 1) $page = 1;
$perPage = (int)($_GET['per'] ?? $perPageDefault);
if ($perPage < 1 || $perPage > 200) $perPage = $perPageDefault;

/* --- fetch author --- */
try {
    if ($slug !== null) {
        $author = $database->fetch(
            "SELECT id, meno, slug, bio, foto, story, books_count, ratings_count, rating_sum, avg_rating, last_rating_at, created_at, updated_at
             FROM authors
             WHERE slug = :slug
             LIMIT 1",
            ['slug' => $slug]
        );
    } elseif ($idParam) {
        $author = $database->fetch(
            "SELECT id, meno, slug, bio, foto, story, books_count, ratings_count, rating_sum, avg_rating, last_rating_at, created_at, updated_at
             FROM authors
             WHERE id = :id
             LIMIT 1",
            ['id' => $idParam]
        );
    } else {
        // no identifier -> 404
        http_response_code(404);
        return ['template' => 'pages/404.php', 'vars' => ['route' => 'author', 'user' => $user ?? null]];
    }
} catch (\Throwable $e) {
    if (class_exists('Logger')) { try { Logger::systemError($e, null, ['phase' => 'author.fetch']); } catch (\Throwable $_) {} }
    return ['template' => 'pages/error.php', 'vars' => ['message' => 'Internal server error (fetch author).']];
}

if (empty($author)) {
    http_response_code(404);
    return ['template' => 'pages/404.php', 'vars' => ['route' => 'author_not_found', 'user' => $user ?? null]];
}

$authorId = (int)$author['id'];

/* --- categories for sidebar (cached) --- */
try {
    $categories = $database->cachedFetchAll('SELECT id, nazov, slug FROM categories ORDER BY nazov ASC');
} catch (\Throwable $e) {
    if (class_exists('Logger')) { try { Logger::warn('Failed to fetch categories for author', $authorId, ['exception' => (string)$e]); } catch (\Throwable $_) {} }
    $categories = [];
}

/* --- fetch books by this author (paginate) --- */
/* Note: subqueries get single cover per book */
$booksSql = "
SELECT
  b.id, b.title, b.slug,
  COALESCE(b.short_description, b.full_description, '') AS description,
  b.price, b.currency, b.is_available, b.stock_quantity, b.created_at,
  c.id AS category_id, c.nazov AS category_name,
  (
    SELECT ba.storage_path FROM book_assets ba WHERE ba.book_id = b.id AND ba.asset_type = 'cover' ORDER BY ba.id ASC LIMIT 1
  ) AS cover_path,
  (
    SELECT ba.filename FROM book_assets ba WHERE ba.book_id = b.id AND ba.asset_type = 'cover' ORDER BY ba.id ASC LIMIT 1
  ) AS cover_filename
FROM books b
LEFT JOIN categories c ON c.id = b.main_category_id
WHERE b.is_active = 1 AND b.author_id = :aid
ORDER BY b.created_at DESC
";

try {
    $pageData = $database->paginate($booksSql, ['aid' => $authorId], $page, $perPage, null);
    $books = $pageData['items'];
    $total = $pageData['total'];
    $totalPages = (int) max(1, ceil($total / max(1, $perPage)));
} catch (\Throwable $e) {
    if (class_exists('Logger')) { try { Logger::systemError($e, null, ['phase' => 'author.books']); } catch (\Throwable $_) {} }
    return ['template' => 'pages/error.php', 'vars' => ['message' => 'Internal server error (author books).']];
}

/* --- helper pro asset URL --- */
$makeAssetUrl = function (?string $storagePath, ?string $filename) {
    if (!empty($storagePath)) {
        return '/cover.php?path=' . rawurlencode($storagePath);
    } elseif (!empty($filename)) {
        return '/files/' . ltrim($filename, '/');
    }
    return null;
};

/* --- normalize books --- */
foreach ($books as &$b) {
    $b['id'] = (int)($b['id'] ?? 0);
    $b['price'] = isset($b['price']) ? (float)$b['price'] : 0.0;
    $b['stock_quantity'] = isset($b['stock_quantity']) ? (int)$b['stock_quantity'] : 0;
    $b['is_available'] = ((int)($b['is_available'] ?? 0) === 1);
    $b['category_name'] = $b['category_name'] ?? '';
    $b['cover_url'] = $makeAssetUrl($b['cover_path'] ?? null, $b['cover_filename'] ?? null);
}
unset($b);

/* --- normalize author photo/url --- */
$author['books_count'] = isset($author['books_count']) ? (int)$author['books_count'] : 0;
$author['ratings_count'] = isset($author['ratings_count']) ? (int)$author['ratings_count'] : 0;
$author['rating_sum'] = isset($author['rating_sum']) ? (int)$author['rating_sum'] : 0;
$author['avg_rating'] = isset($author['avg_rating']) ? (float)$author['avg_rating'] : null;
$author['last_rating_at'] = $author['last_rating_at'] ?? null;

if (!empty($author['foto'])) {
    // foto může být storage_path nebo filename; pro jednoduchost použijeme stejný helper
    $author['foto_url'] = $makeAssetUrl($author['foto'], $author['foto']);
} else {
    $author['foto_url'] = null;
}

/* --- optional: other authors by same category / related (not necessary) --- */
/* - vynechal jsem pro jednoduchost; lze později doplnit */

/* --- return to index.php --- */
return [
    'template' => 'pages/author.php',
    'vars' => [
        'author' => $author,
        'books' => $books,
        'page' => $page,
        'perPage' => $perPage,
        'total' => $total,
        'totalPages' => $totalPages,
        'categories' => $categories,
    ],
];
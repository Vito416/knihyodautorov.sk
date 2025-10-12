<?php

declare(strict_types=1);

// --- Normalize DB wrapper ---
$db = $db ?? $database;
$perPageDefault = 20;

/* --- input validation --- */
$page = (int)($_GET['p'] ?? 1);
if ($page < 1) $page = 1;

$perPage = (int)($_GET['per'] ?? $perPageDefault);
if ($perPage < 1 || $perPage > 200) $perPage = $perPageDefault;

$categorySlug = isset($_GET['cat']) ? trim((string)$_GET['cat']) : null;
$categoryId = null;
if ($categorySlug !== null && $categorySlug !== '') {
    if (!preg_match('/^[a-z0-9\-\_]+$/i', $categorySlug)) {
        $categorySlug = null;
    } else {
        $catRow = $db->fetch('SELECT id, nazov FROM categories WHERE slug = :slug LIMIT 1', ['slug' => $categorySlug]);
        if (is_array($catRow)) {
            $categoryId = (int)$catRow['id'];
        } else {
            $categoryId = null;
            $categorySlug = null;
        }
    }
}

$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$hasSearch = $q !== '';

// escape LIKE-special characters (%) and (_)
if ($hasSearch) {
    $esc = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);
    $searchParam = '%' . $esc . '%';
}

/* --- sorting --- */
$sort = $_GET['sort'] ?? '';
$sortSql = 'b.title ASC';
if ($sort === 'price_asc') $sortSql = 'b.price ASC, b.title ASC';
elseif ($sort === 'price_desc') $sortSql = 'b.price DESC, b.title ASC';
elseif ($sort === 'newest') $sortSql = 'b.created_at DESC, b.title ASC';

/* --- categories for sidebar (prefer shared, avoid duplicate I/O) --- */
$categories = $categories ?? []; // pokud už jsou předány z frontcontrolleru / TrustedShared, použij je

/* --- build WHERE + params --- */
$whereParts = ['b.is_active = 1'];
$params = [];

if ($categoryId !== null) {
    $whereParts[] = 'b.main_category_id = :category_id';
    $params['category_id'] = $categoryId;
}

if ($hasSearch) {
    // použijeme ESCAPE '\' a vázaný parametr
    $whereParts[] = '(b.title LIKE :q ESCAPE \'\\\' OR b.short_description LIKE :q ESCAPE \'\\\' OR a.meno LIKE :q ESCAPE \'\\\')';
    $params['q'] = $searchParam;
}

$where = implode(' AND ', $whereParts);

/* --- hlavní SQL bez LIMIT --- */
/* Subquery v JOIN vybírá jediné cover asset (MIN(id)). Bez GROUP BY. */
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
LEFT JOIN book_assets ba ON ba.id = (
    SELECT MIN(id) FROM book_assets WHERE book_id = b.id AND asset_type = 'cover'
)
WHERE {$where}
ORDER BY {$sortSql}
";

/* --- použijeme Database::paginate (automatické COUNT + LIMIT/OFFSET) --- */
try {
    $pageData = $db->paginate($sql, $params, $page, $perPage, null);
    $books = $pageData['items'];
    $total = $pageData['total'];
    $totalPages = (int) max(1, ceil($total / max(1, $perPage)));
} catch (\Throwable $e) {
    return [
        'status' => 500,
        'template' => 'pages/404.php',
        'vars' => ['message' => 'Interná chyba servera (katalóg).']
    ];
}

/* --- normalize & build cover_url (bez posílání surových filesystem cest tam, kde to není safe) --- */
foreach ($books as &$b) {
    $b['id'] = (int)($b['id'] ?? 0);
    $b['price'] = isset($b['price']) ? (float)$b['price'] : 0.0;
    $b['stock_quantity'] = isset($b['stock_quantity']) ? (int)$b['stock_quantity'] : 0;
    $b['is_available'] = ((int)($b['is_available'] ?? 0) === 1);
    $b['author_name'] = $b['author_name'] ?? '';
    $b['category_name'] = $b['category_name'] ?? '';

    // doporučení: místo storage_path posílat ID assetu nebo podepsaný token. Tady jen fallback:
    if (!empty($b['cover_path'])) {
        // pokud opravdu používáš path proxy, ověř ji v cover.php (sandbox) a nechej cover.php najít podle ID nebo mapy
        $b['cover_url'] = '/cover.php?path=' . rawurlencode($b['cover_path']);
    } elseif (!empty($b['cover_filename'])) {
        $b['cover_url'] = '/files/' . ltrim($b['cover_filename'], '/');
    } else {
        $b['cover_url'] = null;
    }
}
unset($b);

/* --- return template + vars to index.php (index will render header/footer) --- */
return [
    'template' => 'pages/catalog.php',
    'vars' => [
        'navActive' => 'catalog',
        'books' => $books,
        'page' => $page,
        'perPage' => $perPage,
        'total' => $total,
        'totalPages' => $totalPages,
        'currentCategory' => $categorySlug,
        'categories' => $categories,
    ],
];
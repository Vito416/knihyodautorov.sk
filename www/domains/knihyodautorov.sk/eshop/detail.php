<?php
declare(strict_types=1);

/**
 * detail.php
 * Params:
 *  - ?slug=book-slug   (preferované)
 *  - ?id=123           (fallback)
 *
 * Returns to index.php:
 *  - template: stránka nebo partial
 *  - vars: book, assets, relatedBooks, user, hasPurchased
 *
 * Pokud router zjistí AJAX/fragment request, renderuje se jen `partials/book_detail_modal.php`.
 */

/** @var Database $db */
/** @var array|null $user */
/** @var bool $isFragmentRequest */

$slug = isset($_GET['slug']) ? trim((string)$_GET['slug']) : null;
$idParam = isset($_GET['id']) ? (int)$_GET['id'] : null;
$isFragmentRequest = isset($_GET['ajax']) || isset($_GET['fragment']);

if ($slug === '') $slug = null;
if ($slug !== null && !preg_match('/^[a-z0-9\-_]+$/i', $slug)) {
    $slug = null;
}

/* --- fetch book --- */
try {
    if ($slug !== null) {
        $book = $db->fetch(
            "SELECT
                b.*, 
                a.id AS author_id, a.meno AS author_name, a.slug AS author_slug,
                c.id AS category_id, c.nazov AS category_name, c.slug AS category_slug
             FROM books b
             LEFT JOIN authors a ON a.id = b.author_id
             LEFT JOIN categories c ON c.id = b.main_category_id
             WHERE b.slug = :slug AND b.is_active = 1
             LIMIT 1",
            ['slug' => $slug]
        );
    } elseif ($idParam) {
        $book = $db->fetch(
            "SELECT
                b.*, 
                a.id AS author_id, a.meno AS author_name, a.slug AS author_slug,
                c.id AS category_id, c.nazov AS category_name, c.slug AS category_slug
             FROM books b
             LEFT JOIN authors a ON a.id = b.author_id
             LEFT JOIN categories c ON c.id = b.main_category_id
             WHERE b.id = :id AND b.is_active = 1
             LIMIT 1",
            ['id' => $idParam]
        );
    } else {
        return [
            'template' => 'pages/404.php',
            'vars' => ['route' => 'detail', 'user' => $user ?? null]
        ];
    }
} catch (\Throwable $e) {
    if (class_exists('Logger')) { try { Logger::systemError($e, null, ['phase' => 'book.fetch']); } catch (\Throwable $_) {} }
    return [
        'template' => 'pages/error.php',
        'vars' => ['message' => 'Internal server error (fetch book).', 'user' => $user ?? null]
    ];
}

if (empty($book)) {
    return [
        'template' => 'pages/404.php',
        'vars' => ['route' => 'book_not_found', 'user' => $user ?? null]
    ];
}

$bookId = (int)$book['id'];

/* --- fetch assets --- */
try {
    $assets = $db->fetchAll(
        "SELECT id, asset_type, filename, mime_type, size_bytes, storage_path, download_filename, created_at
         FROM book_assets
         WHERE book_id = :bid
         ORDER BY asset_type ASC, id ASC",
        ['bid' => $bookId]
    );
} catch (\Throwable $e) {
    if (class_exists('Logger')) { try { Logger::warn('Failed to fetch assets for book', $bookId, ['exception' => (string)$e]); } catch (\Throwable $_) {} }
    $assets = [];
}

/* --- related books --- */
try {
    $relatedBooks = $db->fetchAll(
        "SELECT
            b.id, b.title, b.slug, b.price, b.currency,
            (
              SELECT ba.storage_path FROM book_assets ba WHERE ba.book_id = b.id AND ba.asset_type = 'cover' ORDER BY ba.id ASC LIMIT 1
            ) AS cover_path,
            (
              SELECT ba.filename FROM book_assets ba WHERE ba.book_id = b.id AND ba.asset_type = 'cover' ORDER BY ba.id ASC LIMIT 1
            ) AS cover_filename
         FROM books b
         WHERE b.is_active = 1
           AND b.id <> :bid
           AND (b.author_id = :author_id OR b.main_category_id = :category_id)
         ORDER BY b.created_at DESC
         LIMIT 6",
        [
            'bid' => $bookId,
            'author_id' => $book['author_id'] ?? 0,
            'category_id' => $book['category_id'] ?? 0,
        ]
    );
} catch (\Throwable $e) {
    if (class_exists('Logger')) { try { Logger::warn('Failed to fetch related books', $bookId, ['exception' => (string)$e]); } catch (\Throwable $_) {} }
    $relatedBooks = [];
}

/* --- normalize data --- */
$book['price'] = (float)($book['price'] ?? 0.0);
$book['stock_quantity'] = (int)($book['stock_quantity'] ?? 0);
$book['is_available'] = ((int)($book['is_available'] ?? 0) === 1);

$makeAssetUrl = function (?string $storagePath, ?string $filename) {
    if (!empty($storagePath)) {
        return '/cover.php?path=' . rawurlencode($storagePath);
    } elseif (!empty($filename)) {
        return '/files/' . ltrim($filename, '/');
    }
    return null;
};

foreach ($assets as &$asset) {
    $asset['url'] = $makeAssetUrl($asset['storage_path'] ?? null, $asset['filename'] ?? null);
}
unset($asset);

$book['cover_url'] = null;
foreach ($assets as $a) {
    if (($a['asset_type'] ?? '') === 'cover') {
        $book['cover_url'] = $a['url'];
        break;
    }
}

foreach ($relatedBooks as &$rb) {
    $rb['id'] = (int)($rb['id'] ?? 0);
    $rb['price'] = (float)($rb['price'] ?? 0.0);
    $rb['cover_url'] = $makeAssetUrl($rb['cover_path'] ?? null, $rb['cover_filename'] ?? null);
}
unset($rb);

/* --- purchased? --- */
$hasPurchased = false;
try {
    if (isset($user['id'])) {
        $p = $db->fetchValue(
            'SELECT 1 FROM orders o 
             JOIN order_items oi ON oi.order_id = o.id 
             WHERE o.user_id = :uid 
               AND oi.book_id = :bid 
               AND o.status = :paid 
             LIMIT 1',
            ['uid' => (int)$user['id'], 'bid' => $bookId, 'paid' => 'paid'],
            null
        );
        $hasPurchased = $p !== null;
    }
} catch (\Throwable $_) {
    $hasPurchased = false;
}

/* --- choose template --- */
$template = $isFragmentRequest
    ? 'partials/book_detail_modal.php'
    : 'pages/detail.php';

return [
    'template' => $template,
    'vars' => [
        'book'         => $book,
        'assets'       => $assets,
        'relatedBooks' => $relatedBooks,
        'user'         => $user ?? null,
        'hasPurchased' => $hasPurchased,
    ],
];
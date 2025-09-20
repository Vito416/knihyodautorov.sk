<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';

/**
 * /eshop/book.php
 *
 * Zobrazenie detailu knihy podľa slug-u (?slug=...).
 */

try {
    $db = Database::getInstance();
} catch (\Throwable $e) {
    if (class_exists('Logger')) { try { Logger::systemError($e); } catch (\Throwable $_) {} }
    http_response_code(500);
    echo Templates::render('pages/error.php', ['message' => 'Databáza nie je dostupná.']);
    exit;
}

$slug = isset($_GET['slug']) ? trim((string)$_GET['slug']) : '';
if ($slug === '') {
    http_response_code(404);
    echo Templates::render('pages/error.php', ['message' => 'Kniha nenájdená.']);
    exit;
}

// Načítaj knihu
$stmt = $db->prepare("
    SELECT b.id, b.title, b.slug, b.price, b.currency, b.description,
           b.is_active, b.is_available, b.stock_quantity,
           b.cover_path, a.name AS author_name, a.slug AS author_slug,
           c.nazov AS category_name, c.slug AS category_slug
    FROM books b
    JOIN authors a ON a.id = b.author_id
    LEFT JOIN categories c ON c.id = b.category_id
    WHERE b.slug = :slug AND b.is_active = 1
    LIMIT 1
");
$stmt->bindValue(':slug', $slug, PDO::PARAM_STR);
$stmt->execute();
$book = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$book) {
    http_response_code(404);
    echo Templates::render('pages/error.php', ['message' => 'Kniha neexistuje.']);
    exit;
}

// priprav CSRF token
$csrf = null;
if (class_exists('CSRF') && method_exists('CSRF', 'token')) {
    try { $csrf = CSRF::token(); } catch (\Throwable $_) {}
}

// podobné knihy (rovnaká kategória)
$similarBooks = [];
if (!empty($book['category_slug'])) {
    $stmt = $db->prepare("
        SELECT id, title, slug, cover_path, price, currency
        FROM books
        WHERE category_id = (SELECT id FROM categories WHERE slug = :cslug)
          AND is_active = 1 AND slug <> :slug
        ORDER BY RAND() LIMIT 4
    ");
    $stmt->bindValue(':cslug', $book['category_slug'], PDO::PARAM_STR);
    $stmt->bindValue(':slug', $slug, PDO::PARAM_STR);
    $stmt->execute();
    $similarBooks = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

echo Templates::render('pages/book_detail.php', [
    'pageTitle' => $book['title'],
    'navActive' => 'catalog',
    'book' => $book,
    'similarBooks' => $similarBooks,
    'csrf_token' => $csrf,
]);
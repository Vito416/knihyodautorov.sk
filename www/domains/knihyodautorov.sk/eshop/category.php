<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';

/**
 * /eshop/category.php?slug=...
 * Detail kategórie + knihy v nej
 */

$slug = isset($_GET['slug']) ? trim((string)$_GET['slug']) : '';
if ($slug === '') {
    http_response_code(400);
    echo Templates::render('pages/error.php', ['message' => 'Kategória nebola špecifikovaná.']);
    exit;
}

// ---------- DB pripojenie ----------
$dbWrapper = null;
$pdo = null;
try {
    if (class_exists('Database') && method_exists('Database', 'getInstance')) {
        $dbWrapper = Database::getInstance();
    } elseif (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof \PDO) {
        $pdo = $GLOBALS['pdo'];
    } else {
        throw new \RuntimeException('Databáza nedostupná.');
    }
} catch (\Throwable $e) {
    if (class_exists('Logger')) { try { Logger::systemError($e); } catch (\Throwable $_) {} }
    http_response_code(500);
    echo Templates::render('pages/error.php', ['message' => 'Chyba databázy.']);
    exit;
}

// ---------- Načítanie kategórie ----------
try {
    $sql = "SELECT id, nazov, slug, description 
            FROM categories 
            WHERE slug = :slug 
            LIMIT 1";

    if ($dbWrapper !== null && method_exists($dbWrapper, 'fetch')) {
        $category = $dbWrapper->fetch($sql, ['slug' => $slug]);
    } else {
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['slug' => $slug]);
        $category = $stmt->fetch(PDO::FETCH_ASSOC);
    }
} catch (\Throwable $e) {
    if (class_exists('Logger')) { try { Logger::systemError($e); } catch (\Throwable $_) {} }
    $category = null;
}

if (!$category) {
    http_response_code(404);
    echo Templates::render('pages/error.php', ['message' => 'Kategória nebola nájdená.']);
    exit;
}

// ---------- Načítanie kníh v kategórii ----------
try {
    $sql = "SELECT b.id, b.title, b.slug, b.price, b.currency, b.is_active, b.is_available, b.stock_quantity, b.cover_path
            FROM books b
            JOIN book_category bc ON bc.book_id = b.id
            WHERE bc.category_id = :cid AND b.is_active = 1
            ORDER BY b.title ASC";

    if ($dbWrapper !== null && method_exists($dbWrapper, 'fetchAll')) {
        $books = $dbWrapper->fetchAll($sql, ['cid' => $category['id']]);
    } else {
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['cid' => $category['id']]);
        $books = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (\Throwable $e) {
    if (class_exists('Logger')) { try { Logger::systemError($e); } catch (\Throwable $_) {} }
    $books = [];
}

// ---------- Render ----------
try {
    echo Templates::render('pages/category_detail.php', [
        'pageTitle' => $category['nazov'] ?? 'Kategória',
        'navActive' => 'categories',
        'category'  => $category,
        'books'     => $books,
    ]);
} catch (\Throwable $e) {
    if (class_exists('Logger')) { try { Logger::systemError($e); } catch (\Throwable $_) {} }
    http_response_code(500);
    echo Templates::render('pages/error.php', ['message' => 'Chyba pri vykreslení detailu kategórie.']);
    exit;
}
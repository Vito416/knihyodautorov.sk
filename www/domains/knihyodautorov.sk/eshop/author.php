<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';

/**
 * /eshop/author.php?slug=...
 * Detail autora + jeho knihy
 */

$slug = isset($_GET['slug']) ? trim((string)$_GET['slug']) : '';
if ($slug === '') {
    http_response_code(400);
    echo Templates::render('pages/error.php', ['message' => 'Autor nebol špecifikovaný.']);
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

// ---------- Načítanie autora ----------
try {
    $sql = "SELECT id, name, slug, bio, photo_path 
            FROM authors 
            WHERE slug = :slug 
            LIMIT 1";

    if ($dbWrapper !== null && method_exists($dbWrapper, 'fetch')) {
        $author = $dbWrapper->fetch($sql, ['slug' => $slug]);
    } else {
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['slug' => $slug]);
        $author = $stmt->fetch(PDO::FETCH_ASSOC);
    }
} catch (\Throwable $e) {
    if (class_exists('Logger')) { try { Logger::systemError($e); } catch (\Throwable $_) {} }
    $author = null;
}

if (!$author) {
    http_response_code(404);
    echo Templates::render('pages/error.php', ['message' => 'Autor nebol nájdený.']);
    exit;
}

// ---------- Načítanie kníh autora ----------
try {
    $sql = "SELECT id, title, slug, price, currency, is_active, is_available, stock_quantity, cover_path
            FROM books
            WHERE author_id = :aid AND is_active = 1
            ORDER BY title ASC";

    if ($dbWrapper !== null && method_exists($dbWrapper, 'fetchAll')) {
        $books = $dbWrapper->fetchAll($sql, ['aid' => $author['id']]);
    } else {
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['aid' => $author['id']]);
        $books = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (\Throwable $e) {
    if (class_exists('Logger')) { try { Logger::systemError($e); } catch (\Throwable $_) {} }
    $books = [];
}

// ---------- Render ----------
try {
    echo Templates::render('pages/author_detail.php', [
        'pageTitle' => $author['name'] ?? 'Autor',
        'navActive' => 'authors',
        'author'    => $author,
        'books'     => $books,
    ]);
} catch (\Throwable $e) {
    if (class_exists('Logger')) { try { Logger::systemError($e); } catch (\Throwable $_) {} }
    http_response_code(500);
    echo Templates::render('pages/error.php', ['message' => 'Chyba pri vykreslení detailu autora.']);
    exit;
}
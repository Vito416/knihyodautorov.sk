<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';

/**
 * /eshop/categories.php
 * Zobrazenie zoznamu kategórií.
 */

// ---------- DB pripojenie ----------
$dbWrapper = null;
$pdo = null;
try {
    if (class_exists('Database') && method_exists('Database', 'getInstance')) {
        $dbWrapper = Database::getInstance();
    } elseif (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof \PDO) {
        $pdo = $GLOBALS['pdo'];
    } else {
        throw new \RuntimeException('Database pripojenie nedostupné.');
    }
} catch (\Throwable $e) {
    if (class_exists('Logger')) { try { Logger::systemError($e); } catch (\Throwable $_) {} }
    http_response_code(500);
    echo Templates::render('pages/error.php', ['message' => 'Chyba databázy.']);
    exit;
}

// ---------- Načítanie kategórií ----------
try {
    $sql = "SELECT id, nazov, slug, description 
            FROM categories 
            WHERE 1 
            ORDER BY nazov ASC";

    if ($dbWrapper !== null && method_exists($dbWrapper, 'fetchAll')) {
        $categories = (array)$dbWrapper->fetchAll($sql);
    } else {
        $stmt = $pdo->query($sql);
        $categories = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    }
} catch (\Throwable $e) {
    if (class_exists('Logger')) { try { Logger::systemError($e); } catch (\Throwable $_) {} }
    $categories = [];
}

// ---------- Render ----------
try {
    echo Templates::render('pages/categories.php', [
        'pageTitle'  => 'Kategórie',
        'navActive'  => 'categories',
        'categories' => $categories,
    ]);
} catch (\Throwable $e) {
    if (class_exists('Logger')) { try { Logger::systemError($e); } catch (\Throwable $_) {} }
    http_response_code(500);
    echo Templates::render('pages/error.php', ['message' => 'Chyba pri vykreslení stránky kategórií.']);
    exit;
}
<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';

/**
 * /eshop/authors.php
 * Zobrazenie zoznamu autorov.
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

// ---------- Načítanie autorov ----------
try {
    $sql = "SELECT id, name, slug, bio, photo_path 
            FROM authors 
            WHERE 1 
            ORDER BY name ASC";

    if ($dbWrapper !== null && method_exists($dbWrapper, 'fetchAll')) {
        $authors = (array)$dbWrapper->fetchAll($sql);
    } else {
        $stmt = $pdo->query($sql);
        $authors = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    }
} catch (\Throwable $e) {
    if (class_exists('Logger')) { try { Logger::systemError($e); } catch (\Throwable $_) {} }
    $authors = [];
}

// ---------- Render ----------
try {
    echo Templates::render('pages/authors.php', [
        'pageTitle' => 'Autori',
        'navActive' => 'authors',
        'authors'   => $authors,
    ]);
} catch (\Throwable $e) {
    if (class_exists('Logger')) { try { Logger::systemError($e); } catch (\Throwable $_) {} }
    http_response_code(500);
    echo Templates::render('pages/error.php', ['message' => 'Chyba pri vykreslení stránky autorov.']);
    exit;
}
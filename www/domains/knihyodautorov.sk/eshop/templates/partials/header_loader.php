<?php
// templates/partials/header_loader.php
declare(strict_types=1);

/**
 * header_loader.php
 *
 * Backend pomocník pre partial headeru.
 * - Zabezpečí DB connection
 * - Overí session cez SessionManager (ak existuje)
 * - Načíta základné údaje o užívateľovi (id, display_name, email)
 * - Načíta zoznam kategórií pre menu
 * - Získa počet položiek v košíku (ak existuje tabuľka / API)
 * - Pripraví CSRF token pre meta / formuláre
 *
 * Vystavené premenné (pre header.php / nav.php):
 *   $user (null|array), $categories (array), $cart_count (int), $csrf_token (string|null)
 *   $pageTitle, $navActive
 *
 * Neposkytuje výstup HTML. Má byť safe-to-include a ticho ošetruje chyby.
 */

if (!defined('TEMPLATES_LOADER_INCLUDED')) {
    define('TEMPLATES_LOADER_INCLUDED', true);
}

// defaulty (ak nie sú nastavené už pred include)
if (!isset($navActive)) $navActive = $navActive ?? 'catalog';
if (!isset($pageTitle)) $pageTitle = $pageTitle ?? null;

// pripravíme výstupné premenné
$user = $user ?? null;
$categories = $categories ?? [];
$cart_count = $cart_count ?? 0;
$csrf_token = $csrf_token ?? null;

//
// 1) DB získanie (robustne ako inde)
//
$pdo = null;
$dbWrapper = null;
try {
    if (class_exists('Database') && method_exists('Database', 'getInstance')) {
        $dbWrapper = Database::getInstance();
        // Database wrapper môže mať getPdo() alebo byť PDO sama
        if (is_object($dbWrapper) && method_exists($dbWrapper, 'getPdo')) {
            $pdo = $dbWrapper->getPdo();
        } elseif ($dbWrapper instanceof \PDO) {
            $pdo = $dbWrapper;
        }
    }
    // fallback na $GLOBALS['pdo']
    if (!($pdo instanceof \PDO) && isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof \PDO) {
        $pdo = $GLOBALS['pdo'];
    }
} catch (\Throwable $e) {
    // ignore, ďalej budeme fungovať bez DB (statické menu)
    if (class_exists('Logger')) {
        try { Logger::systemError($e); } catch (\Throwable $_) {}
    }
    $pdo = null;
}

//
// 2) CSRF token (pre meta + formuláre) - best-effort
//
if ($csrf_token === null && class_exists('CSRF') && method_exists('CSRF', 'token')) {
    try {
        $t = CSRF::token();
        if (is_string($t) && $t !== '') $csrf_token = $t;
    } catch (\Throwable $_) {
        $csrf_token = null;
    }
}

//
// 3) Overenie session a načítanie užívateľa
//
try {
    if (($pdo !== null) && class_exists('SessionManager') && method_exists('SessionManager', 'validateSession')) {
        // SessionManager môže akceptovať PDO alebo Database wrapper
        $dbForSession = $dbWrapper ?? $pdo;
        try {
            $userId = SessionManager::validateSession($dbForSession);
        } catch (\Throwable $e) {
            if (class_exists('Logger')) { try { Logger::systemError($e); } catch (\Throwable $_) {} }
            $userId = null;
        }

        if (!empty($userId) && is_int($userId) && $userId > 0) {
            // načítame základné údaje o užívateľovi
            try {
                $sql = 'SELECT id, COALESCE(given_name, \'\') AS given_name, COALESCE(family_name, \'\') AS family_name, email_enc, email_key_version, created_at
                        FROM pouzivatelia WHERE id = :id LIMIT 1';
                if ($dbWrapper !== null && method_exists($dbWrapper, 'fetch')) {
                    $row = $dbWrapper->fetch($sql, [':id' => $userId]);
                } else {
                    $stmt = $pdo->prepare($sql);
                    $stmt->bindValue(':id', $userId, \PDO::PARAM_INT);
                    $stmt->execute();
                    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                    if ($row === false) $row = null;
                }
                if (!empty($row) && is_array($row)) {
                    // Sestavíme friendly display name (bez odkrývania šifrovaného e-mailu)
                    $display = trim(($row['given_name'] ?? '') . ' ' . ($row['family_name'] ?? ''));
                    if ($display === '') {
                        // fallback na email (ak máte funkciu na dešifrovanie, nepoužívame ju tu)
                        $display = $row['email_enc'] ? 'Používateľ' : 'Používateľ';
                    }
                    $user = [
                        'id' => (int)$row['id'],
                        'given_name' => $row['given_name'] ?? '',
                        'family_name' => $row['family_name'] ?? '',
                        'display_name' => $display,
                        // Nevkladať citlivé dešifrované údaje sem bez potreby
                    ];
                } else {
                    $user = null;
                }
            } catch (\Throwable $e) {
                if (class_exists('Logger')) { try { Logger::systemError($e, $userId); } catch (\Throwable $_) {} }
                $user = null;
            }
        } else {
            $user = null;
        }
    }
} catch (\Throwable $_) {
    $user = $user ?? null;
}

//
// 4) Načítanie kategórií (pre menu/side)
//
try {
    if ($pdo instanceof \PDO || ($dbWrapper !== null && method_exists($dbWrapper, 'fetchAll'))) {
        $sql = 'SELECT id, nazov, slug, parent_id FROM categories ORDER BY nazov ASC';
        if ($dbWrapper !== null && method_exists($dbWrapper, 'fetchAll')) {
            $categories = (array)$dbWrapper->fetchAll($sql, []);
        } else {
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            $categories = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        }
    } else {
        // fallback: statické kategorie (ak nemáš DB)
        $categories = [
            ['id'=>1,'nazov'=>'Beletria','slug'=>'beletria','parent_id'=>null],
            ['id'=>2,'nazov'=>'Detektívky','slug'=>'detektivky','parent_id'=>null],
            ['id'=>3,'nazov'=>'Eseje & Non-fiction','slug'=>'non-fiction','parent_id'=>null],
        ];
    }
} catch (\Throwable $e) {
    if (class_exists('Logger')) { try { Logger::systemError($e); } catch (\Throwable $_) {} }
    $categories = $categories ?? [];
}

//
// 5) Cart count (best-effort) - pokusíme sa spočítať položky v košíku ak existuje tabuľka cart_items
//
try {
    $cart_count = 0;
    if (!empty($user) && isset($user['id']) && ($pdo instanceof \PDO || ($dbWrapper !== null && method_exists($dbWrapper, 'fetch')))) {
        // predpoklad: tabuľka cart_items (user_id, quantity, etc.) alebo carts s items
        // Najprv overíme existenciu tabuľky (len pokusom SELECT)
        $trySql = 'SELECT SUM(quantity) AS cnt FROM cart_items WHERE user_id = :uid AND removed = 0';
        if ($dbWrapper !== null && method_exists($dbWrapper, 'fetch')) {
            $r = $dbWrapper->fetch($trySql, [':uid' => $user['id']]);
            if (!empty($r) && isset($r['cnt'])) $cart_count = (int)$r['cnt'];
        } elseif ($pdo instanceof \PDO) {
            $stmt = $pdo->prepare($trySql);
            $stmt->bindValue(':uid', (int)$user['id'], \PDO::PARAM_INT);
            $stmt->execute();
            $r = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($r && isset($r['cnt'])) $cart_count = (int)$r['cnt'];
        }
    } else {
        // pokus o anonymny cookie-based cart: ak máš cookie 'cart_id', môžeš spočítať
        if (isset($_COOKIE['cart_id']) && $pdo instanceof \PDO) {
            $cartId = $_COOKIE['cart_id'];
            $stmt = $pdo->prepare('SELECT SUM(quantity) AS cnt FROM cart_items WHERE cart_id = :cid AND removed = 0');
            $stmt->bindValue(':cid', $cartId, \PDO::PARAM_STR);
            $stmt->execute();
            $r = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($r && isset($r['cnt'])) $cart_count = (int)$r['cnt'];
        }
    }
} catch (\Throwable $_) {
    // ignore errors; cart_count zostane 0
    $cart_count = $cart_count ?? 0;
}

//
// 6) expose variables (zabezpečíme typy)
//
$categories = is_array($categories) ? array_values($categories) : [];
$cart_count = (int)($cart_count ?? 0);
$user = is_array($user) ? $user : null;

// hotovo - ticho (bez echo)
return;
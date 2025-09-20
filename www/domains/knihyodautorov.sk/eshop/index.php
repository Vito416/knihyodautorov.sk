<?php
declare(strict_types=1);

/**
 * Front controller / simple router for /eshop
 *
 * - očekává inc/bootstrap.php (inicializace Database, Templates, Mailer, Logger, Validator, ...)
 * - používá SessionManager::validateSession($db) pokud je dostupný (SessionManager interně volá session_start())
 * - whitelist rout, ochrana proti LFI
 */

require_once __DIR__ . '/inc/bootstrap.php';

// získej DB wrapper (Database instance) pokud existuje; SessionManager očekává buď Database nebo PDO
$db = null;
try {
    if (class_exists('Database') && method_exists('Database', 'getInstance')) {
        $db = Database::getInstance();
    } else {
        // fallback: pokud není Database wrapper, použij PDO z bootstrapu (pokud tam je nazvaný $pdo)
        if (isset($pdo) && $pdo instanceof \PDO) {
            $db = $pdo;
        } else {
            // pokus o získání PDO přes funkci, konstantu nebo vyhození smysluplné chyby
            throw new \RuntimeException('Database instance not available (Database::getInstance() ani $pdo).');
        }
    }
} catch (\Throwable $e) {
    // log a pokračuj jako guest (bez session)
    if (class_exists('Logger') && method_exists('Logger', 'systemError')) {
        try { Logger::systemError($e); } catch (\Throwable $_) {}
    }
    // pokud nic nefunguje, zastav (bez DB částí by handlery selhaly)
    http_response_code(500);
    echo 'Internal server error (DB init).';
    exit;
}

// validace session — používáme validateSession($db), která vrací userId nebo null
$currentUserId = null;
try {
    if (class_exists('SessionManager') && method_exists('SessionManager', 'validateSession')) {
        // validateSession() interně zavolá session_start() pokud ještě není aktivní
        $currentUserId = SessionManager::validateSession($db);
    } else {
        // prosté fallback: zajistit PHP session pro šablony/formy
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    }
} catch (\Throwable $e) {
    if (class_exists('Logger') && method_exists('Logger', 'systemError')) {
        try { Logger::systemError($e, null); } catch (\Throwable $_) {}
    }
    // zabezpečit, že máme session pro případné formuláře -- ale neshazujeme chybu do uživatele
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
}

// route selection (prefer PATH_INFO pokud máš rewrite, jinak GET param)
$route = null;
if (!empty($_SERVER['PATH_INFO'])) {
    $route = ltrim((string) $_SERVER['PATH_INFO'], '/');
} elseif (!empty($_GET['route'])) {
    $route = (string) $_GET['route'];
} else {
    $route = 'catalog';
}

// basic sanitization (přijmeme písmena, čísla, podtržítko, pomlčku)
$route = preg_replace('/[^a-z0-9_\\-]/i', '', $route);

$routes = [
    'catalog'        => 'catalog.php',
    'detail'         => 'detail.php',
    'cart'           => 'cart.php',
    'checkout'       => 'checkout.php',
    'gopay_callback' => 'gopay_callback.php',
    'order'          => 'order.php',
    'login'          => 'login.php',
    'logout'         => 'logout.php',
    'register'       => 'register.php',
    'google'         => 'google_auth.php',
    'download'       => 'download.php',
];

// pokud route není v whitelistu -> 404
if (!isset($routes[$route])) {
    http_response_code(404);
    try {
        echo Templates::render('pages/404.php', ['route' => $route]);
    } catch (\Throwable $e) {
        if (class_exists('Logger') && method_exists('Logger', 'systemError')) {
            try { Logger::systemError($e); } catch (\Throwable $_) {}
        }
        echo '<h1>404 – Not Found</h1>';
    }
    exit;
}

// nyní include handleru — před tím ověříme existenci souboru
$handler = __DIR__ . '/' . $routes[$route];
if (!is_file($handler) || !is_readable($handler)) {
    http_response_code(500);
    if (class_exists('Logger') && method_exists('Logger', 'systemMessage')) {
        try { Logger::systemMessage('error', 'Route handler missing', null, ['route' => $route, 'handler' => $handler]); } catch (\Throwable $_) {}
    }
    try {
        echo Templates::render('pages/error.php', ['message' => 'Internal server error (handler missing)']);
    } catch (\Throwable $e) {
        echo 'Internal server error';
    }
    exit;
}

// proměnné, které mohou handlery očekávat v globálním scope
// $db obsahuje Database instance nebo PDO; $currentUserId obsahuje ID uživatele nebo null
require $handler;
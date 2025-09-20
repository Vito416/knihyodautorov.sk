<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';

/**
 * login.php
 * Bezpečné prihlásenie používateľa — kompatibilné s Auth a SessionManager.
 * Jazyk chybových hlásení: slovensky.
 *
 * Predpoklady (musí byť k dispozícii v bootstrap.php alebo konfigu):
 *  - autoload tried: Auth, SessionManager, KeyManager, Logger, Database alebo $GLOBALS['pdo']
 *  - definovaná konštanta KEYS_DIR (security-first)
 *  - Templates::render() pre zobrazovanie stránok
 */

// Zabezpečené HTTP hlavičky (len ak ešte neboli odoslané)
if (!headers_sent()) {
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer-when-downgrade');
    header('Strict-Transport-Security: max-age=63072000; includeSubDomains; preload'); // vyžaduje HTTPS
    header("Content-Security-Policy: default-src 'self'; frame-ancestors 'none';");
}

// Povinné kritické triedy
$required = ['Auth', 'SessionManager', 'KeyManager', 'Logger'];
$missing = [];
foreach ($required as $c) {
    if (!class_exists($c)) $missing[] = $c;
}
if (!empty($missing)) {
    $msg = 'Interná chyba: chýbajú tieto knižnice: ' . implode(', ', $missing) . '.';
    if (class_exists('Logger')) {
        try { Logger::systemError(new \RuntimeException($msg)); } catch (\Throwable $_) {}
    }
    http_response_code(500);
    echo Templates::render('pages/error.php', ['message' => $msg]);
    exit;
}

// KEYS_DIR musí byť definovaný — Auth vyžaduje kľúče pre pepper/email/session
if (!defined('KEYS_DIR') || !is_string(KEYS_DIR) || KEYS_DIR === '') {
    $msg = 'Interná chyba: KEYS_DIR nie je nastavený. Skontrolujte konfiguráciu.';
    try { Logger::systemError(new \RuntimeException($msg)); } catch (\Throwable $_) {}
    http_response_code(500);
    echo Templates::render('pages/error.php', ['message' => $msg]);
    exit;
}

// POST processing (prihlásenie)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    // veľmi krátka sanitácia vstupu + validácia formátu
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo Templates::render('pages/login.php', ['error' => 'Neplatný e-mail alebo chýbajúci údaj.']);
        exit;
    }
    if ($password === '') {
        echo Templates::render('pages/login.php', ['error' => 'Zadajte heslo.']);
        exit;
    }

    // voliteľná CSRF validácia, ak je dostupná
    if (class_exists('CSRF') && method_exists('CSRF', 'validate')) {
        try {
            if (!CSRF::validate($_POST['csrf'] ?? null)) {
                echo Templates::render('pages/login.php', ['error' => 'Neplatný CSRF token.']);
                exit;
            }
        } catch (\Throwable $e) {
            // ak CSRF hádže chybu, považujeme to za internú chybu (logujeme)
            try { Logger::systemError($e); } catch (\Throwable $_) {}
            http_response_code(500);
            echo Templates::render('pages/error.php', ['message' => 'Interná chyba (CSRF).']);
            exit;
        }
    }

    // získať PDO inštanciu (Auth::login očakáva PDO)
    try {
        $pdo = null;
        if (class_exists('Database') && method_exists('Database', 'getInstance')) {
            $dbInst = Database::getInstance();
            if ($dbInst instanceof \PDO) {
                $pdo = $dbInst;
            } elseif (is_object($dbInst) && method_exists($dbInst, 'getPdo')) {
                $pdo = $dbInst->getPdo();
            }
        }
        if ($pdo === null && isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof \PDO) {
            $pdo = $GLOBALS['pdo'];
        }
        if (!($pdo instanceof \PDO)) {
            throw new \RuntimeException('Databázové pripojenie nie je dostupné vo forme PDO.');
        }
    } catch (\Throwable $e) {
        try { Logger::systemError($e); } catch (\Throwable $_) {}
        http_response_code(500);
        echo Templates::render('pages/error.php', ['message' => 'Interná chyba (DB).']);
        exit;
    }

    // call Auth::login (defensive wrapper)
    try {
        // maxFailed môže byť konfigurovateľné, tu základná hodnota
        $maxFailed = 5;
        $res = Auth::login($pdo, $email, $password, $maxFailed);

        if (!is_array($res)) {
            // neočakávaný návrat z Auth — logovať a 500
            $err = new \RuntimeException('Auth::login vrátil neočakávaný typ výsledku.');
            try { Logger::systemError($err); } catch (\Throwable $_) {}
            http_response_code(500);
            echo Templates::render('pages/error.php', ['message' => 'Interná chyba pri autentifikácii.']);
            exit;
        }

        if (empty($res['success'])) {
            // neúspešné prihlásenie — Auth už spravuje limiter/logy
            $msg = $res['message'] ?? 'Nesprávny e-mail alebo heslo.';
            // pre bezpečnosť nezobrazovať detaily (uniformné hlásenie)
            echo Templates::render('pages/login.php', ['error' => $msg]);
            exit;
        }

        // úspešné prihlásenie — očakávame user.id
        $user = $res['user'] ?? null;
        $userId = null;
        if (is_array($user) && isset($user['id'])) $userId = (int)$user['id'];
        if ($userId === null) {
            $err = new \RuntimeException('Auth success, ale chýba user id.');
            try { Logger::systemError($err); } catch (\Throwable $_) {}
            http_response_code(500);
            echo Templates::render('pages/error.php', ['message' => 'Interná chyba pri prihlasovaní.']);
            exit;
        }

        // vytvorenie session cez SessionManager (persistuje do DB, nastaví cookie)
        try {
            $token = SessionManager::createSession($pdo, $userId, 30, true, 'Lax');
        } catch (\Throwable $e) {
            try { Logger::systemError($e, $userId); } catch (\Throwable $_) {}
            http_response_code(500);
            echo Templates::render('pages/error.php', ['message' => 'Nepodarilo sa vytvoriť reláciu.']);
            exit;
        }

        // bezpečné vyčistenie citlivých údajov z pamäti
        try { unset($password); } catch (\Throwable $_) {}

        // logovanie úspechu (Auth môže už volať Logger::auth('login_success')) - dublovanie je OK (best-effort)
        try { Logger::auth('login_success', $userId); } catch (\Throwable $_) {}

        // redirect (relative)
        header('Location: index.php', true, 302);
        exit;

    } catch (\Throwable $e) {
        // všeobecná chyba pri autentifikácii / key management
        try { Logger::systemError($e); } catch (\Throwable $_) {}
        http_response_code(500);
        echo Templates::render('pages/error.php', ['message' => 'Chyba pri prihlásení (server). Skontrolujte konfiguráciu kľúčov a Logger.']);
        exit;
    }
}

// GET -> zobraziť formulár prihlásenia
echo Templates::render('pages/login.php', ['error' => null]);
<?php
/**
 * /eshop/_init.php
 *
 * Bootstrap pre e-shop:
 * - bezpečný štart session
 * - načítanie autoload (ručný /libs/autoload.php)
 * - načítanie PDO ($pdo) z /db/config/config.php
 * - načítanie SMTP konfigu ($smtp) z /db/config/configsmtp.php
 * - CSRF helpery, flash helpery
 * - jednoduché logovanie do /eshop/tmp/eshop.log
 *
 * POZOR: tento súbor sa nebude meniť po vygenerovaní — navrhni a over jeho nastavenia pred nasadením.
 */

declare(strict_types=1);

// ---------- nastavenia prostredia ----------
date_default_timezone_set('Europe/Bratislava'); // užívateľova zóna

// DEBUG: nastaviteľné cez environmentálnu premennú APP_DEBUG alebo $_SERVER['APP_DEBUG']
// V produkcii sa odporúča ponechať false.
$__APP_DEBUG = false;
if (
    (isset($_SERVER['APP_DEBUG']) && in_array(strtolower((string)$_SERVER['APP_DEBUG']), ['1','true','on'], true))
    || (getenv('APP_DEBUG') !== false && in_array(strtolower(getenv('APP_DEBUG')), ['1','true','on'], true))
) {
    $__APP_DEBUG = true;
}
defined('APP_DEBUG') || define('APP_DEBUG', $__APP_DEBUG);

// ROOT a cesty
defined('ESHOP_ROOT') || define('ESHOP_ROOT', realpath(__DIR__ . '/..'));
defined('LIBS_DIR')   || define('LIBS_DIR', realpath(ESHOP_ROOT . '/../libs') ?: ESHOP_ROOT . '/../libs');
defined('DB_CONFIG')  || define('DB_CONFIG', ESHOP_ROOT . '/../db/config/config.php');
defined('SMTP_CONFIG')|| define('SMTP_CONFIG', ESHOP_ROOT . '/../db/config/configsmtp.php');
defined('TMP_DIR')    || define('TMP_DIR', ESHOP_ROOT . '/tmp');
defined('LOG_FILE')   || define('LOG_FILE', TMP_DIR . '/eshop.log');

// Vytvorím tmp adresár, ak neexistuje (správne práva)
if (!is_dir(TMP_DIR)) {
    @mkdir(TMP_DIR, 0775, true);
    @chmod(TMP_DIR, 0775);
}

// ---------- bezpečný štart session ----------
if (session_status() === PHP_SESSION_NONE) {
    // session cookie parametre
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    $cookieParams = [
        'lifetime' => 0,
        'path' => '/',
        'domain' => $_SERVER['HTTP_HOST'] ?? '',
        'secure' => $secure,
        'httponly' => true,
        // "Lax" je rozumná voľba pre e-shop (umožní redirect after OAuth/payments)
        'samesite' => 'Lax'
    ];

    // kompatibilita pre staršie PHP < 7.3
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params($cookieParams);
    } else {
        session_set_cookie_params(
            $cookieParams['lifetime'],
            $cookieParams['path'] . '; samesite=' . $cookieParams['samesite'],
            $cookieParams['domain'],
            $cookieParams['secure'],
            $cookieParams['httponly']
        );
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    if ($secure) {
        ini_set('session.cookie_secure', '1');
    }

    session_start();
    // Naštartovanie session bez výmeny id; po úspešnom prihlásení zavolaj session_regenerate_user_id()
}

// Pomocná funkcia na bezpečné regenerovanie session id
if (!function_exists('session_regenerate_user_id')) {
    function session_regenerate_user_id(bool $delete_old_session = true): void {
        // wrapper, použijeme natívne PHP ak je dostupné
        if (function_exists('session_regenerate_id')) {
            session_regenerate_id($delete_old_session);
        }
    }
}

// ---------- jednoduché logovanie (do TMP_DIR/eshop.log) ----------
if (!function_exists('eshop_log')) {
    /**
     * Zapíše riadok do logu s timestampom.
     * @param string $level
     * @param string $message
     */
    function eshop_log(string $level, string $message): void {
        $line = sprintf("[%s] %s: %s\n", date('Y-m-d H:i:s'), strtoupper($level), $message);
        // zabezpečíme, že adresár existuje
        $logFile = LOG_FILE;
        @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
        if (defined('APP_DEBUG') && APP_DEBUG) {
            error_log($line);
        }
    }
}

// zaregistrujeme jednoduchý exception handler aby sa chyby logovali
set_exception_handler(function(Throwable $e) {
    eshop_log('ERROR', "Uncaught exception: {$e->getMessage()} in {$e->getFile()}:{$e->getLine()}");
    if (defined('APP_DEBUG') && APP_DEBUG) {
        http_response_code(500);
        echo "<pre>Nezachytená výnimka: " . htmlspecialchars($e->getMessage()) . "\n" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    } else {
        // v produkcii ticho
        http_response_code(500);
        echo "Server error";
    }
    exit(1);
});

// ---------- autoload (ručný /libs/autoload.php) ----------
$autoloadPath = LIBS_DIR . '/autoload.php';
if (is_file($autoloadPath) && is_readable($autoloadPath)) {
    require_once $autoloadPath;
    eshop_log('INFO', "Načítaný autoload: {$autoloadPath}");
} else {
    eshop_log('ERROR', "Nenájdený autoload: {$autoloadPath}");
    throw new RuntimeException("Chýba autoload knižníc na ceste: {$autoloadPath}");
}

// ---------- načítanie PDO ($pdo) ----------
$pdo = null;
try {
    // podpora oboch variantov: súbor vráti PDO alebo nastaví $pdo v rámci súboru
    $maybe = require DB_CONFIG;
    if ($maybe instanceof PDO) {
        $pdo = $maybe;
    } elseif (isset($pdo) && $pdo instanceof PDO) {
        // keď súbor nastavil $pdo priamo
        // nič, $pdo už nastavené
    } else {
        // pokúsime sa zistiť globálnu premennú $pdo definovanú vo file
        if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
            $pdo = $GLOBALS['pdo'];
        }
    }

    if (!$pdo instanceof PDO) {
        throw new RuntimeException('db/config/config.php nevrátilo PDO ani nenastavilo $pdo.');
    }
    eshop_log('INFO', 'PDO pripojenie načítané úspešne.');
} catch (Throwable $e) {
    eshop_log('ERROR', 'Chyba pri načítaní PDO: ' . $e->getMessage());
    throw $e;
}

// ---------- načítanie SMTP config ($smtp) ----------
$smtp = null;
try {
    $maybeSmtp = require SMTP_CONFIG;
    if (is_array($maybeSmtp)) {
        $smtp = $maybeSmtp;
    } elseif (isset($smtp) && is_array($smtp)) {
        // súbor nastavil $smtp priamo
    } elseif (isset($GLOBALS['smtp']) && is_array($GLOBALS['smtp'])) {
        $smtp = $GLOBALS['smtp'];
    }

    if (!is_array($smtp)) {
        throw new RuntimeException('db/config/configsmtp.php musí vracať pole s SMTP nastaveniami.');
    }
    eshop_log('INFO', 'SMTP konfigurácia načítaná.');
} catch (Throwable $e) {
    eshop_log('ERROR', 'Chyba pri načítaní SMTP konfigurácie: ' . $e->getMessage());
    throw $e;
}

// ---------- CSRF helpery ----------
if (!isset($_SESSION['_csrf_tokens']) || !is_array($_SESSION['_csrf_tokens'])) {
    $_SESSION['_csrf_tokens'] = [];
}

/**
 * Vytvorí CSRF token pre danú formu (string $key) a uloží ho do session s TTL.
 * @param string $key
 * @param int $ttl seconds
 * @return string token
 */
function csrf_create_token(string $key = 'default', int $ttl = 3600): string {
    $token = bin2hex(random_bytes(24));
    $_SESSION['_csrf_tokens'][$key] = [
        'token' => $token,
        'expires' => time() + $ttl
    ];
    return $token;
}

/**
 * Vracia token (vytvorí nový ak neexistuje alebo expiroval).
 * @param string $key
 * @param int $ttl
 * @return string
 */
function csrf_get_token(string $key = 'default', int $ttl = 3600): string {
    if (isset($_SESSION['_csrf_tokens'][$key])) {
        $rec = $_SESSION['_csrf_tokens'][$key];
        if (isset($rec['expires']) && $rec['expires'] >= time() && !empty($rec['token'])) {
            return $rec['token'];
        }
    }
    return csrf_create_token($key, $ttl);
}

/**
 * Overí CSRF token; vráti true/false. Po úspechu token vymaže (one-time).
 * @param string|null $token
 * @param string $key
 * @return bool
 */
function csrf_verify_token(?string $token, string $key = 'default'): bool {
    if (empty($token) || !isset($_SESSION['_csrf_tokens'][$key])) {
        return false;
    }
    $rec = $_SESSION['_csrf_tokens'][$key];
    if (!isset($rec['expires']) || $rec['expires'] < time()) {
        unset($_SESSION['_csrf_tokens'][$key]);
        return false;
    }
    $valid = hash_equals($rec['token'], $token);
    // one-time use
    unset($_SESSION['_csrf_tokens'][$key]);
    return $valid;
}

/**
 * Echo hidden input pre formulár (zjednodušenie)
 * @param string $key
 * @return void
 */
function csrf_field(string $key = 'default'): void {
    $t = htmlspecialchars(csrf_get_token($key), ENT_QUOTES | ENT_HTML5);
    echo '<input type="hidden" name="_csrf" value="' . $t . '">';
}

// ---------- Flash správy (session-based) ----------
if (!isset($_SESSION['_flash']) || !is_array($_SESSION['_flash'])) {
    $_SESSION['_flash'] = [];
}

/**
 * Nastaví flash správu (uvedie typ napr. 'success', 'error', 'info')
 * @param string $key
 * @param mixed $value
 */
function flash_set(string $key, $value): void {
    $_SESSION['_flash'][$key] = $value;
}

/**
 * Získa a zároveň odstráni flash správu
 * @param string $key
 * @param null $default
 * @return mixed
 */
function flash_get(string $key, $default = null) {
    if (isset($_SESSION['_flash'][$key])) {
        $v = $_SESSION['_flash'][$key];
        unset($_SESSION['_flash'][$key]);
        return $v;
    }
    return $default;
}

/**
 * Vráti všetky flash správy (a vymaže ich)
 * @return array
 */
function flash_all(): array {
    $all = $_SESSION['_flash'] ?? [];
    $_SESSION['_flash'] = [];
    return $all;
}

// ---------- užívateľská session / helpery ----------
/**
 * Prihlási používateľa (uloží user_id do session + regen id)
 * @param int $userId
 */
function auth_login(int $userId): void {
    $_SESSION['user_id'] = $userId;
    session_regenerate_id(true);
}

/**
 * Odhlási používateľa
 */
function auth_logout(): void {
    unset($_SESSION['user_id']);
    session_regenerate_id(true);
}

/**
 * Vráti user_id alebo null
 * @return int|null
 */
function auth_user_id(): ?int {
    return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
}

/**
 * Načítanie používateľa z DB (vráti asociatívne pole alebo null)
 * @param PDO|null $pdoOpt
 * @return array|null
 */
function auth_user(PDO $pdoOpt = null): ?array {
    $uid = auth_user_id();
    if ($uid === null) return null;
    $pdoLocal = $pdoOpt ?? ($GLOBALS['pdo'] ?? null);
    if (!($pdoLocal instanceof PDO)) return null;

    $stmt = $pdoLocal->prepare("SELECT id, meno, email, telefon, adresa, newsletter FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$uid]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    return $user ?: null;
}

// ---------- utility: bezpečné redirect + old input ----------
/**
 * Presmerovanie a ukončenie skriptu
 * @param string $url
 */
function redirect(string $url): void {
    header("Location: $url");
    exit;
}

// ---------- exportovanie globálnych premenných (pre include súbory) ----------
$GLOBALS['pdo'] = $pdo;
$GLOBALS['smtp'] = $smtp;
$GLOBALS['eshop_init_loaded'] = true;

// Log successful init
eshop_log('INFO', 'ESHOP _init.php inicializovaný.');

// ---------------- koniec súboru ----------------
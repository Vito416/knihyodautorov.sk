<?php
declare(strict_types=1);

class Auth
{
    // délka (v hex) tokenu v cookie (32 bytes -> 64 hex chars)
    private const TOKEN_HEX_LENGTH = 64;
    private const TOKEN_BYTES = 32;

        // --- Argon2 options (čtou se z ENV pokud dostupné) ---
    private static function getArgon2Options(): array
    {
        return [
            'memory_cost' => (int)($_ENV['ARGON_MEMORY_KIB'] ?? (1 << 16)), // 65536 KiB = 64 MiB
            'time_cost'   => (int)($_ENV['ARGON_TIME_COST'] ?? 4),
            'threads'     => (int)($_ENV['ARGON_THREADS'] ?? 2),
        ];
    }

    // --- Pepper loader: prefer KeyManager file, fallback to env PASSWORD_PEPPER (base64) ---
    // --- Pepper loader: prefer KeyManager file, fallback to env PASSWORD_PEPPER (base64) ---
    private static function loadPepper(): ?string
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        try {
            if (class_exists('KeyManager')) {
                $keysDir = $_ENV['KEYS_DIR'] ?? (defined('KEYS_DIR') ? KEYS_DIR : null);
                $basename = 'password_pepper';
                try {
                    $info = KeyManager::getRawKeyBytes('PASSWORD_PEPPER', $keysDir, $basename, false);
                    if (!empty($info['raw'])) {
                        $cached = $info['raw']; // binary string
                        return $cached;
                    }
                } catch (\Throwable $e) {
                    error_log('[Auth::loadPepper] KeyManager getRawKeyBytes failed: ' . $e->getMessage());
                }
            }

            $b64 = $_ENV['PASSWORD_PEPPER'] ?? getenv('PASSWORD_PEPPER') ?: '';
            if ($b64 !== '') {
                $raw = base64_decode($b64, true);
                if ($raw !== false) {
                    $cached = $raw;
                    return $cached;
                }
                error_log('[Auth::loadPepper] PASSWORD_PEPPER env invalid base64');
            }
        } catch (\Throwable $e) {
            error_log('[Auth::loadPepper] unexpected: ' . $e->getMessage());
        }

        $cached = null;
        return null;
    }


    // --- Preprocess password (HMAC with pepper) before hashing/verifying ---
    private static function preprocessPassword(string $password): string
    {
        $pepper = self::loadPepper();
        if ($pepper === null) {
            return $password;
        }
        // returns binary string (use true to get raw binary)
        return hash_hmac('sha256', $password, $pepper, true);
    }

    // --- Centralized hash + verify helpers ---
    public static function hashPassword(string $password): string
    {
        $pwd = self::preprocessPassword($password);
        $opts = self::getArgon2Options();
        $hash = password_hash($pwd, PASSWORD_ARGON2ID, $opts);
        if ($hash === false) {
            throw new \RuntimeException('password_hash failed');
        }
        return $hash;
    }

    public static function verifyPassword(string $password, string $storedHash): bool
    {
        // try with pepper-preprocessed password first (covers new accounts)
        $pepper = self::loadPepper();
        if ($pepper !== null) {
            $pre = hash_hmac('sha256', $password, $pepper, true);
            if (password_verify($pre, $storedHash)) {
                return true;
            }
            // fallthrough to legacy check
        }
        // legacy fallback: verify raw password (accounts created before pepper)
        return password_verify($password, $storedHash);
    }

    /**
     * Přihlášení pomocí emailu + hesla.
     * Vrátí [bool, message]
     *
     * $opts:
     *   - max_failed (int) default 5
     *   - session_lifetime_days (int) default 30
     *   - allow_multiple_sessions (bool) default true
     *   - session_samesite (string) 'Lax'|'Strict'|'None'
     */
    public static function loginWithPassword(PDO $db, string $email, string $password, array $opts = []): array
    {
        $maxFailed = (int)($opts['max_failed'] ?? 5);
        $sessionDays = (int)($opts['session_lifetime_days'] ?? 30);
        $allowMultiple = $opts['allow_multiple_sessions'] ?? true;
        $samesite = $opts['session_samesite'] ?? 'Lax';

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [false, 'Neplatné přihlášení'];
        }

        try {
            $stmt = $db->prepare('SELECT id, heslo_hash, is_active, is_locked, must_change_password, failed_logins FROM pouzivatelia WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            $u = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [false, 'Chyba serveru'];
        }

        if (!$u) {
            usleep(150000);
            return [false, 'Neplatné přihlášení'];
        }

        if (empty($u['is_active']) || !empty($u['is_locked'])) {
            return [false, 'Neplatné přihlášení'];
        }

        // --- new: use centralized verifyPassword (handles pepper + fallback) ---
        if (!self::verifyPassword($password, (string)$u['heslo_hash'])) {
            try {
                $stmt = $db->prepare('UPDATE pouzivatelia SET failed_logins = failed_logins + 1 WHERE id = ?');
                $stmt->execute([$u['id']]);
                $stmt = $db->prepare('SELECT failed_logins FROM pouzivatelia WHERE id = ?');
                $stmt->execute([$u['id']]);
                $failed = (int)$stmt->fetchColumn();

                if ($failed >= $maxFailed) {
                    $stmt = $db->prepare('UPDATE pouzivatelia SET is_locked = 1 WHERE id = ?');
                    $stmt->execute([$u['id']]);
                }
            } catch (\Throwable $e) {
                // ignore internal errors
            }
            return [false, 'Neplatné přihlášení'];
        }

        // success: reset failed_logins + update last_login_at
        try {
            $stmt = $db->prepare('UPDATE pouzivatelia SET failed_logins = 0, last_login_at = UTC_TIMESTAMP() WHERE id = ?');
            $stmt->execute([$u['id']]);
        } catch (\Throwable $e) {
            // log interně
        }

        // rehash if needed (target: Argon2id with configured opts)
        $optsArgon = self::getArgon2Options();
        if (password_needs_rehash((string)$u['heslo_hash'], PASSWORD_ARGON2ID, $optsArgon)) {
            try {
                $newHash = self::hashPassword($password);
                if ($newHash !== false) {
                    $stmt = $db->prepare('UPDATE pouzivatelia SET heslo_hash = ? WHERE id = ?');
                    $stmt->execute([$newHash, $u['id']]);
                }
            } catch (\Throwable $e) {
                error_log('[Auth::loginWithPassword] rehash failed: ' . $e->getMessage());
            }
        }

        // init session cookie params before session_start
        self::initSessionCookie(['session_samesite' => $samesite]);

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$u['id'];

        // persistent session token: cookie stores hex token, DB stores binary SHA256
        $rawToken = bin2hex(random_bytes(self::TOKEN_BYTES)); // 64 hex chars
        $tokenHashBin = hash('sha256', $rawToken, true); // binary 32 bytes

        $dt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $expiresAt = $dt->modify('+' . $sessionDays . ' days')->format('Y-m-d H:i:s');

        try {
            if (!$allowMultiple) {
                $stmt = $db->prepare('UPDATE sessions SET revoked = 1 WHERE user_id = ?');
                $stmt->execute([(int)$u['id']]);
            }

            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;

            // NOTE: sessions.token_hash must be VARBINARY(32) in DB
            $stmt = $db->prepare('INSERT INTO sessions (token_hash, user_id, created_at, last_seen_at, expires_at, ip, user_agent, revoked) VALUES (?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), ?, ?, ?, 0)');
            // ensure ip is stored as plain string or inet_pton() depending on DB schema; here pass raw string (migration later)
            $stmt->execute([$tokenHashBin, (int)$u['id'], $expiresAt, $ip, $ua]);
        } catch (\Throwable $e) {
            self::clearSessionCookie();
            return [true, 'OK'];
        }

        $isSecure = self::isHttps();
        $cookieOpts = [
            'expires' => time() + ($sessionDays * 24 * 60 * 60),
            'path' => '/',
            'secure' => $isSecure,
            'httponly' => true,
            'samesite' => $samesite,
        ];
        $cookieDomain = $_ENV['SESSION_DOMAIN'] ?? ($_SERVER['HTTP_HOST'] ?? null);
        if (!empty($cookieDomain)) {
            $cookieOpts['domain'] = $cookieDomain;
        }

        if (PHP_VERSION_ID >= 70300) {
            setcookie('session_token', $rawToken, $cookieOpts);
        } else {
            $domain = $cookieOpts['domain'] ?? '';
            setcookie('session_token', $rawToken, $cookieOpts['expires'], $cookieOpts['path'], $domain, $cookieOpts['secure'], $cookieOpts['httponly']);
        }

        if (!empty($u['must_change_password'])) {
            return [true, 'MUST_CHANGE_PASSWORD'];
        }

        return [true, 'OK'];
    }

    /**
     * Inicializace parametrů PHP session cookie.
     * Volat dříve než session_start() a před výstupem.
     * $config: ['session_samesite' => 'Lax'|'Strict'|'None']
     */
    public static function initSessionCookie(array $config = []): void
    {
        $isSecure = self::isHttps();
        $cookieDomain = $_ENV['SESSION_DOMAIN'] ?? ($_SERVER['HTTP_HOST'] ?? null);
        $samesite = $config['session_samesite'] ?? 'Lax';

        $params = [
            'lifetime' => 0,
            'path' => '/',
            'secure' => $isSecure,
            'httponly' => true,
            'samesite' => $samesite,
        ];
        if (!empty($cookieDomain)) {
            $params['domain'] = $cookieDomain;
        }

        if (PHP_VERSION_ID >= 70300) {
            session_set_cookie_params($params);
        } else {
            $domain = $params['domain'] ?? '';
            session_set_cookie_params(0, '/', $domain, $isSecure, true);
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    /**
     * Validate persistent cookie and if valid, set $_SESSION['user_id'].
     * Vrací user_id nebo null.
     *
     * Volitelné $opts:
     *   - enforce_ip_check (bool) default false (recommended false, může blokovat mobilní uživatele)
     */
    public static function validateSession(PDO $db, array $opts = []): ?int
    {
        $enforceIp = $opts['enforce_ip_check'] ?? false;

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        if (!empty($_SESSION['user_id'])) {
            return (int)$_SESSION['user_id'];
        }

        if (empty($_COOKIE['session_token'])) {
            return null;
        }

        $token = (string)$_COOKIE['session_token'];
        if (!ctype_xdigit($token) || strlen($token) !== self::TOKEN_HEX_LENGTH) {
            self::clearSessionCookie();
            return null;
        }

        // compute binary hash to match VARBINARY(32) in DB
        $tokenHashBin = hash('sha256', $token, true);

        try {
            $stmt = $db->prepare('SELECT user_id, ip, user_agent FROM sessions WHERE token_hash = ? AND revoked = 0 AND expires_at > UTC_TIMESTAMP() LIMIT 1');
            $stmt->execute([$tokenHashBin]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            self::clearSessionCookie();
            return null;
        }

        if (!$row) {
            self::clearSessionCookie();
            return null;
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

        // optional IP check (configurable)
        if ($enforceIp && !empty($row['ip'])) {
            // lepší než substr-prefix: zkontrolujte, zda první 2 octety sedí (nebo jiná logika)
            // zde volíme kontrolu prvních 2 oktetů IPv4 jako kompromis
            if (filter_var($ip, FILTER_VALIDATE_IP) && filter_var($row['ip'], FILTER_VALIDATE_IP)) {
                // IPv4 check first two octets
                if (strpos($ip, '.') !== false && strpos($row['ip'], '.') !== false) {
                    $ipParts = explode('.', $ip);
                    $rowParts = explode('.', $row['ip']);
                    if ($ipParts[0] !== $rowParts[0] || $ipParts[1] !== $rowParts[1]) {
                        self::clearSessionCookie();
                        return null;
                    }
                } else {
                    // pro IPv6 můžeme vynechat nebo implementovat jinou logiku
                    if ($ip !== $row['ip']) {
                        self::clearSessionCookie();
                        return null;
                    }
                }
            }
        }

        // user-agent musí být stejný
        if (!hash_equals((string)$row['user_agent'], $ua)) {
            self::clearSessionCookie();
            return null;
        }

        // set session user id and update last_seen_at
        $_SESSION['user_id'] = (int)$row['user_id'];

        try {
            $stmt = $db->prepare('UPDATE sessions SET last_seen_at = UTC_TIMESTAMP() WHERE token_hash = ?');
            $stmt->execute([$tokenHashBin]);
        } catch (\Throwable $e) {
            // ignore
        }

        return (int)$_SESSION['user_id'];
    }

    /**
     * requireLogin: přesměrování pokud není přihlášen; doporučeno volat po validateSession($db)
     */
    public static function requireLogin(string $redirectUrl = '/eshop/login.php'): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        if (!empty($_SESSION['user_id'])) {
            return;
        }
        header('Location: ' . $redirectUrl);
        exit;
    }

    /**
     * Logout: revoke session in DB (if DB provided) and destroy PHP session.
     */
    public static function logout(?PDO $db = null): void
    {
        // revoke only the token in cookie (not všechny)
        if ($db instanceof PDO && !empty($_COOKIE['session_token'])) {
            $token = (string)$_COOKIE['session_token'];
            if (ctype_xdigit($token) && strlen($token) === self::TOKEN_HEX_LENGTH) {
                $tokenHash = hash('sha256', $token);
                try {
                    $stmt = $db->prepare('UPDATE sessions SET revoked = 1 WHERE token_hash = ?');
                    $stmt->execute([$tokenHash]);
                } catch (\Throwable $e) {
                    // ignore
                }
            }
        }

        // clear cookie
        self::clearSessionCookie();

        // destroy PHP session safely
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'] ?? '/', $params['domain'] ?? '', $params['secure'] ?? false, $params['httponly'] ?? true
            );
        }
        $_SESSION = [];
        session_unset();
        session_destroy();
    }

    /**
     * Smaže session_token cookie (sjednocený postup).
     */
    private static function clearSessionCookie(): void
    {
        $isSecure = self::isHttps();
        $cookieDomain = $_ENV['SESSION_DOMAIN'] ?? ($_SERVER['HTTP_HOST'] ?? null);
        $samesite = $_ENV['SESSION_SAMESITE'] ?? 'Lax';

        if (PHP_VERSION_ID >= 70300) {
            $opts = [
                'expires' => time() - 3600,
                'path' => '/',
                'secure' => $isSecure,
                'httponly' => true,
                'samesite' => $samesite,
            ];
            if (!empty($cookieDomain)) {
                $opts['domain'] = $cookieDomain;
            }
            setcookie('session_token', '', $opts);
        } else {
            $domain = $cookieDomain ?? '';
            setcookie('session_token', '', time() - 3600, '/', $domain, $isSecure, true);
        }
    }

    /**
     * Kontrola, zda požadavek přijel přes HTTPS (respektuje běžné proxy hlavičky).
     */
    private static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            if (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') return true;
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower($_SERVER['HTTP_X_FORWARDED_SSL']) === 'on') {
            return true;
        }
        if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') return true;
        if (!empty($_SERVER['REQUEST_SCHEME']) && strtolower($_SERVER['REQUEST_SCHEME']) === 'https') return true;
        if (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) return true;
        return false;
    }

    // ------------------------------------------------------------
    // CSRF ochrana
    // ------------------------------------------------------------
    public static function ensureCsrfToken(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }

    public static function validateCsrfToken(?string $token): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        if (empty($_SESSION['csrf_token'])) {
            return false;
        }
        return hash_equals((string)$_SESSION['csrf_token'], (string)$token);
    }
}
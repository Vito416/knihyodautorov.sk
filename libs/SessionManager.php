<?php
declare(strict_types=1);

final class SessionManager
{
    private const TOKEN_BYTES = 32;

    private function __construct() {}

    public static function createSession($db, int $userId, int $days = 30, bool $allowMultiple = true, string $samesite = 'Lax'): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        session_regenerate_id(true);

        $rawToken = bin2hex(random_bytes(self::TOKEN_BYTES));
        // raw binary sha256 (32 bytes) — vhodné do VARBINARY(32)
        $tokenHashBin = hash('sha256', $rawToken, true);

        // timestamps with microseconds for DATETIME(6)
        $nowUtc = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $expiresAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify("+{$days} days")->format('Y-m-d H:i:s.u');

        try {
            // revoke others if requested
            if (!$allowMultiple) {
                $sql = 'UPDATE sessions SET revoked = 1 WHERE user_id = :user_id';
                self::executeDb($db, $sql, [':user_id' => $userId]);
            }

            // Collect client info (raw)
            $ipRaw = $_SERVER['REMOTE_ADDR'] ?? null;
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;

            // Ask Logger for hashed IP (Logger::getHashedIp returns hex hash & key id)
            $ipHashHex = null;
            $ipHashBin = null;
            $ipHashKeyId = null;
            if (class_exists('Logger')) {
                try {
                    $ipRes = Logger::getHashedIp($ipRaw);
                    $ipHashHex = $ipRes['hash'] ?? null;
                    $ipHashKeyId = $ipRes['key_id'] ?? null;
                    // convert hex -> binary (VARBINARY(32))
                    if (is_string($ipHashHex) && ctype_xdigit($ipHashHex) && strlen($ipHashHex) === 64) {
                        $ipHashBin = @hex2bin($ipHashHex);
                    } else {
                        $ipHashBin = null;
                    }
                } catch (\Throwable $_) {
                    $ipHashBin = null;
                }
            }

            // Insert into sessions (token_hash is binary)
            $sql = 'INSERT INTO sessions (token_hash, user_id, created_at, last_seen_at, expires_at, ip_hash, ip_hash_key, user_agent, revoked)
                    VALUES (:token_hash, :user_id, :created_at, :last_seen_at, :expires_at, :ip_hash, :ip_hash_key, :user_agent, 0)';

            $params = [
                ':token_hash'   => $tokenHashBin,
                ':user_id'      => $userId,
                ':created_at'   => $nowUtc,
                ':last_seen_at' => $nowUtc,
                ':expires_at'   => $expiresAt,
                ':ip_hash'      => $ipHashBin,
                ':ip_hash_key'  => $ipHashKeyId,
                ':user_agent'   => self::truncateUserAgent($ua),
            ];

            self::executeDb($db, $sql, $params);

            // Audit via Logger (delegate hashing, key-id, etc.)
            if (class_exists('Logger')) {
                try {
                    $meta = ['source' => 'SessionManager::createSession'];
                    // include token hash (hex) so we can link audit <-> sessions without exposing raw cookie token
                    $meta['_token_hash'] = bin2hex($tokenHashBin);
                    Logger::session('session_created', $userId, ['source' => 'SessionManager::createSession'], null, null, null, $tokenHashBin);
                } catch (\Throwable $_) { /* silent */ }
            }
        } catch (\Throwable $e) {
            if (class_exists('Logger')) {
                try { Logger::systemError($e, $userId); } catch (\Throwable $_) {}
            }
            throw new \RuntimeException('Nie je možné vytvoriť session.');
        }

        // Cookie nastavení až po úspěšném zápisu do DB
        $cookieOpts = [
            'expires' => time() + $days * 86400,
            'path' => '/',
            'secure' => self::isHttps(),
            'httponly' => true,
            'samesite' => $samesite,
        ];
        $cookieDomain = $_ENV['SESSION_DOMAIN'] ?? ($_SERVER['HTTP_HOST'] ?? null);
        if (!empty($cookieDomain)) $cookieOpts['domain'] = $cookieDomain;

        if (PHP_VERSION_ID >= 70300) {
            setcookie('session_token', $rawToken, $cookieOpts);
        } else {
            setcookie(
                'session_token',
                $rawToken,
                $cookieOpts['expires'],
                $cookieOpts['path'],
                $cookieDomain ?? '',
                $cookieOpts['secure'],
                $cookieOpts['httponly']
            );
        }

        $_SESSION['user_id'] = $userId;

        return $rawToken;
    }

    public static function validateSession($db): ?int
    {
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        $rawToken = $_COOKIE['session_token'] ?? null;
        if (!$rawToken) return null;

        $tokenHashBin = hash('sha256', $rawToken, true);
        $ipRaw = $_SERVER['REMOTE_ADDR'] ?? null;
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;

        try {
            $sql = 'SELECT user_id, ip_hash, ip_hash_key, user_agent, expires_at, revoked
                    FROM sessions
                    WHERE token_hash = :token_hash
                    LIMIT 1';
            $row = self::fetchOne($db, $sql, [':token_hash' => $tokenHashBin]);
        } catch (\Throwable $e) {
            if (class_exists('Logger')) { try { Logger::systemError($e); } catch (\Throwable $_) {} }
            return null;
        }

        if (!$row) return null;
        if ((int)($row['revoked'] ?? 0) === 1) return null;

        // expires_at check
        try {
            $expiresAt = new \DateTimeImmutable($row['expires_at'], new \DateTimeZone('UTC'));
            if ($expiresAt < new \DateTimeImmutable('now', new \DateTimeZone('UTC'))) return null;
        } catch (\Throwable $_) {
            return null;
        }

        // Optional: verify IP/user agent match
        // compute current hashed ip and compare to stored ip_hash (binary)
        if ($ipRaw !== null && isset($row['ip_hash'])) {
            try {
                $ipRes = Logger::getHashedIp($ipRaw);
                $curHex = $ipRes['hash'] ?? null;
                $curBin = (is_string($curHex) && ctype_xdigit($curHex) && strlen($curHex) === 64) ? @hex2bin($curHex) : null;
                // only compare when both exist
                if ($curBin !== null && $row['ip_hash'] !== null) {
                    if ($curBin !== $row['ip_hash']) return null;
                }
            } catch (\Throwable $_) {
                // ignore hash compare on error
            }
        }

        // user-agent compare (optional)
        if (($row['user_agent'] ?? null) !== null && ($ua ?? null) !== null) {
            if ($row['user_agent'] !== $ua) return null;
        }

        $userId = (int)$row['user_id'];
        $_SESSION['user_id'] = $userId;

        // Update last_seen_at
        try {
            $nowUtc = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
            $sql = 'UPDATE sessions SET last_seen_at = :last_seen_at WHERE token_hash = :token_hash';
            self::executeDb($db, $sql, [':last_seen_at' => $nowUtc, ':token_hash' => $tokenHashBin]);
        } catch (\Throwable $e) {
            if (class_exists('Logger')) { try { Logger::systemError($e, $userId); } catch (\Throwable $_) {} }
        }

        return $userId;
    }

    public static function destroySession($db): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();

        $rawToken = $_COOKIE['session_token'] ?? null;
        $userId = $_SESSION['user_id'] ?? null;

        if ($rawToken) {
            $tokenHashBin = hash('sha256', $rawToken, true);
            try {
                $sql = 'UPDATE sessions SET revoked = 1 WHERE token_hash = :token_hash';
                self::executeDb($db, $sql, [':token_hash' => $tokenHashBin]);
            } catch (\Throwable $e) {
                if (class_exists('Logger')) { try { Logger::systemError($e, $userId); } catch (\Throwable $_) {} }
            }
            setcookie('session_token', '', time() - 3600, '/');
        }

        $userId = $_SESSION['user_id'] ?? null;
        $_SESSION = [];
        @session_destroy();

        $rawToken = $_COOKIE['session_token'] ?? null;
        $tokenHashBin = $rawToken !== null ? hash('sha256', $rawToken, true) : null;

        if (class_exists('Logger')) {
            try { Logger::session('session_destroyed', $_SESSION['user_id'] ?? null, null, null, null, null, $tokenHashBin); } catch (\Throwable $_) {}
        }
    }

    private static function isHttps(): bool
    {
        $proto = strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');
        return $proto === 'https' || (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
    }

    /* -------------------------
     * DB helpers (ponechat nebo vylepšit)
     * ------------------------- */
    private static function executeDb($db, string $sql, array $params = []): void
    {
        // same logic as v původním souboru (Database wrapper or PDO)
        if ($db instanceof Database) {
            $db->prepareAndRun($sql, $params);
            return;
        }
        if ($db instanceof \PDO) {
            $stmt = $db->prepare($sql);
            if ($stmt === false) throw new \RuntimeException('Failed to prepare statement');
            foreach ($params as $k => $v) {
                $name = (strpos((string)$k, ':') === 0) ? $k : ':' . $k;
                if ($v === null) {
                    $stmt->bindValue($name, null, \PDO::PARAM_NULL);
                } elseif (is_int($v)) {
                    $stmt->bindValue($name, $v, \PDO::PARAM_INT);
                } elseif (is_bool($v)) {
                    $stmt->bindValue($name, $v ? 1 : 0, \PDO::PARAM_INT);
                } elseif (is_string($v)) {
                    // binary detection: raw binary may not contain NUL — but token_hash/ip_hash are fixed lengths
                    // if length is 32 bytes, treat as binary blob
                    if (strlen($v) === 32 && !preg_match('//u', $v)) {
                        // heuristic: 32 bytes non-UTF8 -> binary
                        $stmt->bindValue($name, $v, \PDO::PARAM_LOB);
                    } elseif (strpos($v, "\0") !== false && defined('PDO::PARAM_LOB')) {
                        $stmt->bindValue($name, $v, \PDO::PARAM_LOB);
                    } else {
                        $stmt->bindValue($name, $v, \PDO::PARAM_STR);
                    }
                } else {
                    $stmt->bindValue($name, (string)$v, \PDO::PARAM_STR);
                }
            }
            $stmt->execute();
            return;
        }
        throw new \InvalidArgumentException('Unsupported $db provided to SessionManager (expected Database or PDO)');
    }

    private static function fetchOne($db, string $sql, array $params = []): ?array
    {
        if ($db instanceof Database) {
            return $db->fetch($sql, $params);
        }
        if ($db instanceof \PDO) {
            $stmt = $db->prepare($sql);
            if ($stmt === false) throw new \RuntimeException('Failed to prepare statement');
            foreach ($params as $k => $v) {
                $name = (strpos((string)$k, ':') === 0) ? $k : ':' . $k;
                if ($v === null) {
                    $stmt->bindValue($name, null, \PDO::PARAM_NULL);
                } elseif (is_int($v)) {
                    $stmt->bindValue($name, $v, \PDO::PARAM_INT);
                } elseif (is_bool($v)) {
                    $stmt->bindValue($name, $v ? 1 : 0, \PDO::PARAM_INT);
                } elseif (is_string($v)) {
                    if (strlen($v) === 32 && !preg_match('//u', $v)) {
                        $stmt->bindValue($name, $v, \PDO::PARAM_LOB);
                    } elseif (strpos($v, "\0") !== false && defined('PDO::PARAM_LOB')) {
                        $stmt->bindValue($name, $v, \PDO::PARAM_LOB);
                    } else {
                        $stmt->bindValue($name, $v, \PDO::PARAM_STR);
                    }
                } else {
                    $stmt->bindValue($name, (string)$v, \PDO::PARAM_STR);
                }
            }
            $stmt->execute();
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $row === false ? null : $row;
        }
        throw new \InvalidArgumentException('Unsupported $db provided to SessionManager (expected Database or PDO)');
    }

    private static function truncateUserAgent(?string $ua): ?string
    {
        if ($ua === null) return null;
        return mb_substr($ua, 0, 255);
    }
}
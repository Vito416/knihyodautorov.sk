<?php
declare(strict_types=1);

final class SessionManager
{
    private const TOKEN_BYTES = 32; // raw bytes
    private const COOKIE_NAME = 'session_token';
    private function __construct() {}

    /* -------------------------
     * Helpers
     * ------------------------- */

    private static function getKeysDir(): ?string
    {
        return defined('KEYS_DIR') ? KEYS_DIR : ($_ENV['KEYS_DIR'] ?? null);
    }

    private static function base64url_encode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64url_decode(string $b64u): ?string
    {
        $remainder = strlen($b64u) % 4;
        if ($remainder) {
            $b64u .= str_repeat('=', 4 - $remainder);
        }
        $decoded = base64_decode(strtr($b64u, '-_', '+/'), true);
        return $decoded === false ? null : $decoded;
    }

    private static function truncateUserAgent(?string $ua): ?string
    {
        if ($ua === null) return null;
        return mb_substr($ua, 0, 255);
    }

    private static function isHttps(): bool
    {
        $proto = strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');
        return $proto === 'https' || (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
    }

    /* -------------------------
     * DB helpers (reused)
     * ------------------------- */
    private static function executeDb($db, string $sql, array $params = []): void
    {
        if ($db instanceof Database) {
            $db->prepareAndRun($sql, $params);
            return;
        }
        if ($db instanceof \PDO) {
            $stmt = $db->prepare($sql);
            if ($stmt === false) throw new \RuntimeException('Failed to prepare statement');
            foreach ($params as $k => $v) {
                $name = (strpos((string)$k, ':') === 0) ? $k : ':' . $k;

                // NULL / INT / BOOL handling
                if ($v === null) {
                    $stmt->bindValue($name, null, \PDO::PARAM_NULL);
                    continue;
                }
                if (is_int($v)) {
                    $stmt->bindValue($name, $v, \PDO::PARAM_INT);
                    continue;
                }
                if (is_bool($v)) {
                    $stmt->bindValue($name, $v ? 1 : 0, \PDO::PARAM_INT);
                    continue;
                }

                // Strings and binary data
                if (is_string($v)) {
                    // explicit BLOB param names used in this class
                    if ($name === ':blob' || $name === ':session_blob') {
                        $stmt->bindValue($name, $v, \PDO::PARAM_LOB);
                        continue;
                    }

                    // binary token/hash fields (fixed 32 bytes) should be bound as LOB/BINARY
                    if (($name === ':token_hash' || $name === ':ip_hash') && strlen($v) === 32) {
                        $stmt->bindValue($name, $v, \PDO::PARAM_LOB);
                        continue;
                    }

                    // if contains a NUL, treat as binary
                    if (strpos($v, "\0") !== false) {
                        $stmt->bindValue($name, $v, \PDO::PARAM_LOB);
                        continue;
                    }

                    // default: regular string
                    $stmt->bindValue($name, $v, \PDO::PARAM_STR);
                    continue;
                }

                // fallback stringify
                $stmt->bindValue($name, (string)$v, \PDO::PARAM_STR);
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
                    if ($name === ':blob' || $name === ':session_blob') {
                        $stmt->bindValue($name, $v, \PDO::PARAM_LOB);
                    } elseif (($name === ':token_hash' || $name === ':ip_hash') && strlen($v) === 32) {
                        $stmt->bindValue($name, $v, \PDO::PARAM_LOB);
                    } elseif (strpos($v, "\0") !== false) {
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

    /* -------------------------
     * Crypto helpers (session payload encryption)
     * ------------------------- */

    private static function ensureCryptoInitialized(): void
    {
        if (!class_exists('Crypto') || !class_exists('KeyManager')) {
            throw new \RuntimeException('Crypto/KeyManager required for session encryption');
        }
        // idempotent: Crypto::initFromKeyManager will set internal key if not set
        try {
            Crypto::initFromKeyManager(self::getKeysDir());
        } catch (\Throwable $e) {
            throw new \RuntimeException('Crypto initialization failed: ' . $e->getMessage());
        }
    }

    /**
     * Persist current $_SESSION (except transient runtime-only things) encrypted into DB session_blob.
     * Uses Crypto::encrypt(..., 'binary') to create a versioned binary payload.
     */
    private static function persistSessionBlob($db, string $tokenHashBin): void
    {
        // gather session snapshot (exclude internal/ephemeral data if you want)
        $sess = $_SESSION ?? [];
        // ensure we don't accidentally store enormous resources or objects - keep scalars/arrays only
        $filtered = self::sanitizeSessionForStorage($sess);

        try {
            self::ensureCryptoInitialized();
            $plaintext = json_encode($filtered, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            if ($plaintext === false) throw new \RuntimeException('Failed to JSON encode session');
            $blob = Crypto::encrypt($plaintext, 'binary');
            if (!is_string($blob) || $blob === '') {
                throw new \RuntimeException('Crypto produced empty blob');
            }

            $sql = 'UPDATE sessions SET session_blob = :blob WHERE token_hash = :token_hash';
            self::executeDb($db, $sql, [':blob' => $blob, ':token_hash' => $tokenHashBin]);
        } catch (\Throwable $e) {
            // log and fail silently (do not break app flow)
            if (class_exists('Logger')) {
                try { Logger::systemError($e, $_SESSION['user_id'] ?? null); } catch (\Throwable $_) {}
            }
        }
    }

    /**
     * Load encrypted session blob (binary) from $row (or DB if needed), decrypt, and populate $_SESSION.
     * Return true on success, false otherwise.
     */
    private static function loadSessionBlobAndPopulate($db, array $row, string $tokenHashBin): bool
    {
        // prefer blob from fetched $row when present
        $blob = $row['session_blob'] ?? null;

        // if not present in row, fetch it
        if ($blob === null) {
            try {
                $sql = 'SELECT session_blob FROM sessions WHERE token_hash = :token_hash LIMIT 1';
                $r = self::fetchOne($db, $sql, [':token_hash' => $tokenHashBin]);
                $blob = $r['session_blob'] ?? null;
            } catch (\Throwable $e) {
                if (class_exists('Logger')) { try { Logger::systemError($e, $_SESSION['user_id'] ?? null); } catch (\Throwable $_) {} }
                return false;
            }
        }

        if ($blob === null) {
            // nothing to restore -> leave $_SESSION as-is
            return true;
        }

        try {
            self::ensureCryptoInitialized();
            // Crypto::decrypt accepts both base64 compact and binary-versioned payloads
            $plain = Crypto::decrypt($blob);
            if ($plain === null) {
                // decryption failed -> treat session as invalid (security-first)
                return false;
            }

            $data = json_decode($plain, true);
            if (!is_array($data)) { return false; }

            // merge: ensure user_id from DB takes precedence
            $userIdFromDb = $_SESSION['user_id'] ?? null;
            $_SESSION = $data;
            if ($userIdFromDb !== null) $_SESSION['user_id'] = $userIdFromDb;
            return true;
        } catch (\Throwable $e) {
            if (class_exists('Logger')) { try { Logger::systemError($e, $_SESSION['user_id'] ?? null); } catch (\Throwable $_) {} }
            return false;
        }
    }

    /**
     * Basic sanitization of session array before storing:
     * - remove resources, objects (keep scalars and arrays)
     */
    private static function sanitizeSessionForStorage(array $sess): array
    {
        $clean = [];
        foreach ($sess as $k => $v) {
            // skip objects/resources
            if (is_object($v) || is_resource($v)) continue;
            if (is_array($v)) {
                $clean[$k] = self::sanitizeSessionForStorage($v);
            } else {
                $clean[$k] = $v;
            }
        }
        return $clean;
    }

    /* -------------------------
     * Session operations
     * ------------------------- */

    public static function createSession($db, int $userId, int $days = 30, bool $allowMultiple = true, string $samesite = 'Lax'): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        session_regenerate_id(true);

        $rawToken = random_bytes(self::TOKEN_BYTES); // raw bytes
        $cookieToken = self::base64url_encode($rawToken); // safe for cookie
        $keysDir = self::getKeysDir();

        try {
            // derive HMAC using newest session key and obtain key version
            $derived = KeyManager::deriveHmacWithLatest('SESSION_KEY', $keysDir, 'session_key', $rawToken);
            $tokenHashBin = $derived['hash'];                // binary 32 bytes
            $tokenHashKeyVer = $derived['version'] ?? null;  // e.g. 'v2' or 'env'
            if (!is_string($tokenHashBin) || strlen($tokenHashBin) !== 32) {
                throw new RuntimeException('Derived token hash invalid');
            }
        } catch (\Throwable $e) {
            if (class_exists('Logger')) { try { Logger::systemError($e, $userId); } catch (\Throwable $_) {} }
            throw new RuntimeException('Unable to initialize session key.');
        }

        $nowUtc = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $expiresAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify("+{$days} days")->format('Y-m-d H:i:s.u');

        try {
            if (!$allowMultiple) {
                $sql = 'UPDATE sessions SET revoked = 1 WHERE user_id = :user_id';
                self::executeDb($db, $sql, [':user_id' => $userId]);
            }

            $ipRaw = $_SERVER['REMOTE_ADDR'] ?? null;
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;

            $ipHashBin = null;
            $ipHashKey = null;
            if (class_exists('Logger')) {
                try {
                    $ipRes = Logger::getHashedIp($ipRaw);
                    $ipHashBin = $ipRes['hash'] ?? null;
                    $ipHashKey = $ipRes['key_id'] ?? null;
                } catch (\Throwable $_) {
                    $ipHashBin = null;
                }
            }

            // Insert - session_blob will be null initially; we'll persist after cookie + $_SESSION set
            $sql = 'INSERT INTO sessions (token_hash, token_hash_key, user_id, created_at, last_seen_at, expires_at, ip_hash, ip_hash_key, user_agent, revoked, session_blob)
                    VALUES (:token_hash, :token_hash_key, :user_id, :created_at, :last_seen_at, :expires_at, :ip_hash, :ip_hash_key, :user_agent, 0, NULL)';
            $params = [
                ':token_hash'   => $tokenHashBin,
                ':token_hash_key' => $tokenHashKeyVer,
                ':user_id'      => $userId,
                ':created_at'   => $nowUtc,
                ':last_seen_at' => $nowUtc,
                ':expires_at'   => $expiresAt,
                ':ip_hash'      => $ipHashBin,
                ':ip_hash_key'  => $ipHashKey,
                ':user_agent'   => self::truncateUserAgent($ua),
            ];

            self::executeDb($db, $sql, $params);

            if (class_exists('Logger')) {
                try {
                    $meta = ['source' => 'SessionManager::createSession', '_token_hash_key' => $tokenHashKeyVer];
                    $meta['_token_hash_hex'] = bin2hex($tokenHashBin);
                    Logger::session('session_created', $userId, $meta, $ipRaw, $ua, null, $tokenHashBin);
                } catch (\Throwable $_) {}
            }
        } catch (\Throwable $e) {
            if (class_exists('Logger')) { try { Logger::systemError($e, $userId); } catch (\Throwable $_) {} }
            throw new \RuntimeException('Unable to persist session.');
        }

        // set cookie AFTER DB write
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
            setcookie(self::COOKIE_NAME, $cookieToken, $cookieOpts);
        } else {
            setcookie(
                self::COOKIE_NAME,
                $cookieToken,
                $cookieOpts['expires'],
                $cookieOpts['path'],
                $cookieDomain ?? '',
                $cookieOpts['secure'],
                $cookieOpts['httponly']
            );
        }

        // set user_id in session and persist encrypted session blob
        $_SESSION['user_id'] = $userId;
        try {
            self::persistSessionBlob($db, $tokenHashBin);
        } catch (\Throwable $_) {}

        return $cookieToken;
    }

    public static function validateSession($db): ?int
    {
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        $cookie = $_COOKIE[self::COOKIE_NAME] ?? null;
        if (!$cookie || !is_string($cookie)) return null;

        $rawToken = self::base64url_decode($cookie);
        if ($rawToken === null || strlen($rawToken) !== self::TOKEN_BYTES) {
            return null;
        }

        $keysDir = self::getKeysDir();
        try {
            $candidates = KeyManager::deriveHmacCandidates('SESSION_KEY', $keysDir, 'session_key', $rawToken);
            if (empty($candidates)) return null;
        } catch (\Throwable $e) {
            if (class_exists('Logger')) { try { Logger::systemError($e); } catch (\Throwable $_) {} }
            return null;
        }

        $row = null;
        $candidate = null;
        $candidateVersion = null;
        foreach ($candidates as $c) {
            // $c = ['version'=>'vN','hash'=>binary]
            $candidate = $c['hash'];
            $candidateVersion = $c['version'] ?? null;

            try {
                $sql = 'SELECT user_id, ip_hash, ip_hash_key, user_agent, expires_at, revoked, session_blob
                        FROM sessions
                        WHERE token_hash = :token_hash
                        LIMIT 1';
                $row = self::fetchOne($db, $sql, [':token_hash' => $candidate]);
            } catch (\Throwable $e) {
                if (class_exists('Logger')) { try { Logger::systemError($e); } catch (\Throwable $_) {} }
                return null;
            }
            if ($row !== null) break;
        }

        if ($row === null) return null;
        if ((int)($row['revoked'] ?? 0) === 1) return null;

        try {
            $expiresAt = new \DateTimeImmutable($row['expires_at'], new \DateTimeZone('UTC'));
            if ($expiresAt < new \DateTimeImmutable('now', new \DateTimeZone('UTC'))) return null;
        } catch (\Throwable $_) {
            return null;
        }

        $ipRaw = $_SERVER['REMOTE_ADDR'] ?? null;
        if ($ipRaw !== null && isset($row['ip_hash']) && $row['ip_hash'] !== null) {
            try {
                $ipRes = Logger::getHashedIp($ipRaw);
                $curBin = $ipRes['hash'] ?? null;
                if ($curBin !== null && $row['ip_hash'] !== null) {
                    // timing-safe comparison if both are strings
                    if (is_string($curBin) && is_string($row['ip_hash'])) {
                        if (!hash_equals($row['ip_hash'], $curBin)) return null;
                    } else {
                        // fallback conservative check
                        if ($curBin !== $row['ip_hash']) return null;
                    }
                }
            } catch (\Throwable $_) {}
        }

        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
        if (($row['user_agent'] ?? null) !== null && $ua !== null) {
            if ($row['user_agent'] !== $ua) return null;
        }

        // populate session from encrypted blob; if decryption fails -> treat session as invalid
        $decryptedOk = self::loadSessionBlobAndPopulate($db, $row, $candidate);
        if ($decryptedOk === false) {
            // revoke session to be safe
            try {
                $sql = 'UPDATE sessions SET revoked = 1 WHERE token_hash = :token_hash';
                self::executeDb($db, $sql, [':token_hash' => $candidate]);
                if (class_exists('Logger')) {
                    try {
                        Logger::systemMessage('warning', 'session revoked after decrypt failure', null, [
                            'stage'=>'validateSession',
                            'token_hash_hex' => is_string($candidate) ? bin2hex($candidate) : null
                        ]);
                    } catch (\Throwable $_) {}
                }
            } catch (\Throwable $_) {}
            return null;
        }

        $userId = (int)$row['user_id'];
        $_SESSION['user_id'] = $userId;

        // update last_seen
        try {
            $nowUtc = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
            $sql = 'UPDATE sessions SET last_seen_at = :last_seen_at WHERE token_hash = :token_hash';
            self::executeDb($db, $sql, [':last_seen_at' => $nowUtc, ':token_hash' => $candidate]);
        } catch (\Throwable $e) {
            if (class_exists('Logger')) { try { Logger::systemError($e, $userId); } catch (\Throwable $_) {} }
        }

        return $userId;
    }

    public static function destroySession($db): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();

        $cookie = $_COOKIE[self::COOKIE_NAME] ?? null;
        $userId = $_SESSION['user_id'] ?? null;

        if ($cookie && is_string($cookie)) {
            $rawToken = self::base64url_decode($cookie);
            if (is_string($rawToken) && strlen($rawToken) === self::TOKEN_BYTES) {
                $keysDir = self::getKeysDir();
                    try {
                        $candidates = KeyManager::deriveHmacCandidates('SESSION_KEY', $keysDir, 'session_key', $rawToken);
                        foreach ($candidates as $c) {
                            $candidate = $c['hash'];
                            try {
                                $sql = 'UPDATE sessions SET revoked = 1 WHERE token_hash = :token_hash';
                                self::executeDb($db, $sql, [':token_hash' => $candidate]);
                            } catch (\Throwable $e) {
                                if (class_exists('Logger')) { try { Logger::systemError($e, $userId); } catch (\Throwable $_) {} }
                            }
                        }
                    } catch (\Throwable $_) {
                        // ignore
                    }
            }
        }

        // clear cookie securely (same attributes as createSession)
        setcookie(self::COOKIE_NAME, '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        // destroy PHP session
        $userId = $_SESSION['user_id'] ?? null;
        $_SESSION = [];
        @session_destroy();

        // Audit session destruction (pass token hash bin if available)
        if (class_exists('Logger')) {
            try {
                if (isset($candidate)) {
                    Logger::session('session_destroyed', $userId ?? null, null, null, null, null, $candidate);
                } else {
                    Logger::session('session_destroyed', $userId ?? null, null, null, null, null, null);
                }
            } catch (\Throwable $_) {}
        }
    }
}
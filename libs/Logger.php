<?php
declare(strict_types=1);

/**
 * Logger facade for:
 *  - auth_events
 *  - register_events
 *  - verify_events
 *  - system_error
 *
 * Používá Database::getInstance() (tvůj singleton).
 *
 * Poznámka: Kód neroluje logy do souborů. Pokud DB není inicializována / INSERT selže,
 * logování jen "tichounce" selže (nezpůsobí výjimku ani echo).
 */
final class Logger
{
    private function __construct() {}

    /* -----------------------
       HELPERS
       ----------------------- */

    public static function getClientIp(): ?string
    {
        $trusted = $_ENV['TRUSTED_PROXIES'] ?? '';
        $trustedList = $trusted ? array_map('trim', explode(',', $trusted)) : [];

        $remote = $_SERVER['REMOTE_ADDR'] ?? null;
        $useForwarded = $remote && in_array($remote, $trustedList, true);

        $headers = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_REAL_IP',
            'HTTP_X_FORWARDED_FOR',
        ];

        if ($useForwarded) {
            foreach ($headers as $header) {
                if (!empty($_SERVER[$header])) {
                    $ips = explode(',', $_SERVER[$header]);
                    foreach ($ips as $candidate) {
                        $candidate = trim($candidate);
                        if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                            return $candidate;
                        }
                    }
                }
            }
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        if ($ip && filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }

        return null;
    }

    /**
     * Compute HMAC-based IP hash using KeyManager (preferred) or env fallback.
     * Returns array: ['hash' => ?string, 'key_id' => ?string, 'used' => 'keymanager'|'env'|'fallback'|'none']
     *
     * - Does NOT log or expose the raw IP.
     * - Calls KeyManager::getSaltInfo($keysDir) if available (and memzero()s the raw key afterwards).
     */
    public static function getHashedIp(?string $ip = null): array
    {
        $ipRaw = $ip ?? self::getClientIp();
        if ($ipRaw === null) {
            return ['hash' => null, 'key_id' => null, 'used' => 'none'];
        }

        // try KeyManager first
        try {
            // pokud máš konstantu KEYS_DIR definovanou, použij ji; jinak fallback na env var nebo null
            $keysDir = defined('KEYS_DIR') ? (KEYS_DIR) : ($_ENV['KEYS_DIR'] ?? null);

            // prefer calling KeyManager with the configured keys dir; if null, KeyManager will fallback to ENV
            $saltInfo = KeyManager::getSaltInfo($keysDir);
            $saltRaw = $saltInfo['raw'] ?? null;
            $saltVer = $saltInfo['version'] ?? null;

            if (!empty($saltRaw)) {
                $hmac = hash_hmac('sha256', $ipRaw, $saltRaw);
                // wipe sensitive key bytes ASAP
                KeyManager::memzero($saltRaw);
                return ['hash' => $hmac, 'key_id' => $saltVer, 'used' => 'keymanager'];
            }
            // if saltRaw empty, fall through to env fallback
        } catch (\Throwable $e) {
            // silent fallback — but you may want to system log this as a warning separately
            // Logger::warn('KeyManager salt fetch failed', null, ['err'=>$e->getMessage()]); // optional
        }

        // env fallback: expect base64 in APP_SALT (or raw string)
        $envVal = $_ENV['APP_SALT'] ?? ($_SERVER['APP_SALT'] ?? null);
        if (!empty($envVal)) {
            $decoded = base64_decode($envVal, true);
            $secret = ($decoded !== false && $decoded !== '') ? $decoded : $envVal;
            $hmac = hash_hmac('sha256', $ipRaw, $secret);
            return ['hash' => $hmac, 'key_id' => null, 'used' => 'env'];
        }

        // last resort: plain sha256 (still better than storing raw IP)
        $h = hash('sha256', $ipRaw);
        return ['hash' => $h, 'key_id' => null, 'used' => 'fallback'];
    }

    private static function getUserAgent(): ?string
    {
        return $_SERVER['HTTP_USER_AGENT'] ?? null;
    }

    private static function safeJsonEncode($data): ?string
    {
        if ($data === null) return null;
        $json = @json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json === false ? null : $json;
    }

    private static function ipToBinary(?string $ip): ?string
    {
        if ($ip === null) return null;
        if (!function_exists('inet_pton')) return null;
        $packed = @inet_pton($ip);
        return $packed === false ? null : $packed;
    }

    /* -----------------------
       VALIDACE TYPŮ (ENUM)
       ----------------------- */

    private static function validateAuthType(string $type): string
    {
        $allowed = ['login_success','login_failure','logout','password_reset','lockout'];
        return in_array($type, $allowed, true) ? $type : 'login_failure';
    }

    private static function validateRegisterType(string $type): string
    {
        $allowed = ['register_success','register_failure'];
        return in_array($type, $allowed, true) ? $type : 'register_failure';
    }

    private static function validateVerifyType(string $type): string
    {
        $allowed = ['verify_success','verify_failure'];
        return in_array($type, $allowed, true) ? $type : 'verify_failure';
    }

    /* -----------------------
       AUTH / REGISTER / VERIFY
       ----------------------- */

    /**
     * Log do auth_events
     * @param string $type one of login_success|login_failure|logout|password_reset|lockout
     * @param int|null $userId
     * @param array|null $meta - uloží se do JSON sloupce meta
     * @param string|null $ip optional (pokud null, vezme $_SERVER)
     * @param string|null $userAgent optional
     */
    public static function auth(string $type, ?int $userId = null, ?array $meta = null, ?string $ip = null, ?string $userAgent = null): void
    {
        if (!Database::isInitialized()) return;

        $type = self::validateAuthType($type);
        $userAgent = $userAgent ?? self::getUserAgent();

        // central IP hashing
        $ipResult = self::getHashedIp($ip);
        $ipHash = $ipResult['hash'];
        $ipKeyId = $ipResult['key_id'];
        $ipUsed = $ipResult['used'];

        $filteredMeta = self::filterSensitive($meta) ?? [];
        $filteredMeta['_ip_hash_used'] = $ipUsed;
        if ($ipKeyId !== null) $filteredMeta['_ip_hash_key'] = $ipKeyId;
        $json = self::safeJsonEncode($filteredMeta);

        try {
            $db = Database::getInstance();
            $db->execute(
                "INSERT INTO auth_events (user_id, type, ip_hash, ip_hash_key, user_agent, occurred_at, meta)
                VALUES (:user_id, :type, :ip_hash, :ip_hash_key, :ua, NOW(), :meta)",
                [
                    ':user_id' => $userId,
                    ':type'    => $type,
                    ':ip_hash' => $ipHash,
                    ':ip_hash_key' => $ipKeyId,
                    ':ua'      => $userAgent,
                    ':meta'    => $json,
                ]
            );
        } catch (\Throwable $e) {
            return;
        }
    }

    /**
     * Log do register_events
     */
    public static function register(string $type, ?int $userId = null, ?array $meta = null, ?string $ip = null, ?string $userAgent = null): void
    {
        if (!Database::isInitialized()) return;

        $type = self::validateRegisterType($type);
        $userAgent = $userAgent ?? self::getUserAgent();

        $ipResult = self::getHashedIp($ip);
        $ipHash = $ipResult['hash'];
        $ipKeyId = $ipResult['key_id'];
        $ipUsed = $ipResult['used'];

        $filteredMeta = self::filterSensitive($meta) ?? [];
        $filteredMeta['_ip_hash_used'] = $ipUsed;
        if ($ipKeyId !== null) $filteredMeta['_ip_hash_key'] = $ipKeyId;
        $json = self::safeJsonEncode($filteredMeta);

        try {
            $db = Database::getInstance();
            $db->execute(
                "INSERT INTO register_events (user_id, type, ip_hash, ip_hash_key, user_agent, occurred_at, meta)
                VALUES (:user_id, :type, :ip_hash, :ip_hash_key, :ua, NOW(), :meta)",
                [
                    ':user_id' => $userId,
                    ':type'    => $type,
                    ':ip_hash' => $ipHash,
                    ':ip_hash_key' => $ipKeyId,
                    ':ua'      => $userAgent,
                    ':meta'    => $json,
                ]
            );
        } catch (\Throwable $e) {
            return;
        }
    }

    /**
     * Log do verify_events
     */
    public static function verify(string $type, ?int $userId = null, ?array $meta = null, ?string $ip = null, ?string $userAgent = null): void
    {
        if (!Database::isInitialized()) return;

        $type = self::validateVerifyType($type);
        $userAgent = $userAgent ?? self::getUserAgent();

        $ipResult = self::getHashedIp($ip);
        $ipHash = $ipResult['hash'];
        $ipKeyId = $ipResult['key_id'];
        $ipUsed = $ipResult['used'];

        $filteredMeta = self::filterSensitive($meta) ?? [];
        $filteredMeta['_ip_hash_used'] = $ipUsed;
        if ($ipKeyId !== null) $filteredMeta['_ip_hash_key'] = $ipKeyId;
        $json = self::safeJsonEncode($filteredMeta);

        try {
            $db = Database::getInstance();
            $db->execute(
                "INSERT INTO verify_events (user_id, type, ip_hash, ip_hash_key, user_agent, occurred_at, meta)
                VALUES (:user_id, :type, :ip_hash, :ip_hash_key, :ua, NOW(), :meta)",
                [
                    ':user_id' => $userId,
                    ':type'    => $type,
                    ':ip_hash' => $ipHash,
                    ':ip_hash_key' => $ipKeyId,
                    ':ua'      => $userAgent,
                    ':meta'    => $json,
                ]
            );
        } catch (\Throwable $e) {
            return;
        }
    }

    /* -----------------------
       SYSTEM (system_error)
       ----------------------- */

    /**
     * Log jednoduché zprávy do system_error (přímá náhrada za error_log('[msg]'))
     * level: 'notice'|'warning'|'error'|'critical'
     */
    public static function systemMessage(string $level, string $message, ?int $userId = null, ?array $context = null, ?string $token = null, bool $aggregateByFingerprint = false): void
    {
        if (!Database::isInitialized()) return;

        $level = in_array($level, ['notice','warning','error','critical'], true) ? $level : 'error';
        $ipResult = self::getHashedIp(null);
        $ipHash = $ipResult['hash'];
        $ipKeyId = $ipResult['key_id'];
        $ipUsed = $ipResult['used'];
        $ua = self::getUserAgent();

        // fingerprint - ze zprávy + (pokud je v context file/line, použij je)
        $file = $context['file'] ?? null;
        $line = $context['line'] ?? null;
        $fingerprint = hash('sha256', $level . '|' . $message . '|' . ($file ?? '') . ':' . ($line ?? ''));
        $context = $context ?? [];
        $context['_ip_hash_used'] = $ipUsed;
        if ($ipKeyId !== null) $context['_ip_hash_key'] = $ipKeyId;
        $jsonContext = self::safeJsonEncode($context);

        try {
            $db = Database::getInstance();

            if ($aggregateByFingerprint) {
                // najdi poslední záznam se stejným fingerprint (pokud existuje) -> update occurrences
                $row = $db->fetch("SELECT id, occurrences FROM system_error WHERE fingerprint = :fp ORDER BY last_seen DESC LIMIT 1", [':fp' => $fingerprint]);
                if ($row !== null) {
                    $db->execute("UPDATE system_error SET occurrences = occurrences + 1, last_seen = NOW() WHERE id = :id", [':id' => $row['id']]);
                    return;
                }
            }

            // INSERT:
            $db->execute(
                "INSERT INTO system_error
                (level, message, exception_class, file, line, stack_trace, token, context, fingerprint, occurrences, user_id, ip_hash, ip_hash_key, user_agent, url, method, http_status, created_at, last_seen)
                VALUES
                (:level, :message, NULL, :file, :line, NULL, :token, :context, :fingerprint, 1, :user_id, :ip_hash, :ip_hash_key, :ua, :url, :method, :status, NOW(), NOW())",
                [
                    ':level'      => $level,
                    ':message'    => $message,
                    ':file'       => $file,
                    ':line'       => $line,
                    ':token'      => $token,
                    ':context'    => $jsonContext,
                    ':fingerprint'=> $fingerprint,
                    ':user_id'    => $userId,
                    ':ip_hash'    => $ipHash,
                    ':ip_hash_key'=> $ipKeyId,
                    ':ua'         => $ua,
                    ':url'        => $_SERVER['REQUEST_URI'] ?? null,
                    ':method'     => $_SERVER['REQUEST_METHOD'] ?? null,
                    ':status'     => http_response_code() ?: null,
                ]
            );
        } catch (\Throwable $e) {
            return;
        }
    }

    /**
     * Log Throwable / Exception do system_error (včetně stacktrace a class)
     * @param \Throwable $e
     * @param int|null $userId
     * @param string|null $token
     * @param array|null $context
     * @param bool $aggregateByFingerprint - pokud true, pokusí se najít stejný fingerprint a zvýšit occurrences
     */
    public static function systemError(\Throwable $e, ?int $userId = null, ?string $token = null, ?array $context = null, bool $aggregateByFingerprint = true): void
    {
        if (!Database::isInitialized()) return;

        $message = (string)$e->getMessage();
        $exceptionClass = get_class($e);
        $file = $e->getFile();
        $line = $e->getLine();
        $stack = $e->getTraceAsString();

        // centralised IP hashing (do not store raw ip)
        $ipResult = self::getHashedIp(null);
        $ipHash = $ipResult['hash'];
        $ipKeyId = $ipResult['key_id'];
        $ipUsed = $ipResult['used'];

        $ua = self::getUserAgent();
        $jsonContext = self::safeJsonEncode($context);

        $fingerprint = hash('sha256', $message . '|' . $exceptionClass . '|' . $file . ':' . $line);

        // add forensics info into context (do not add raw ip)
        $context = $context ?? [];
        $context['_ip_hash_used'] = $ipUsed;
        if ($ipKeyId !== null) $context['_ip_hash_key'] = $ipKeyId;
        $jsonContext = self::safeJsonEncode($context);

        try {
            $db = Database::getInstance();

            if ($aggregateByFingerprint) {
                $row = $db->fetch("SELECT id, occurrences FROM system_error WHERE fingerprint = :fp ORDER BY last_seen DESC LIMIT 1", [':fp' => $fingerprint]);
                if ($row !== null) {
                    $db->execute("UPDATE system_error SET occurrences = occurrences + 1, last_seen = NOW() WHERE id = :id", [':id' => $row['id']]);
                    return;
                }
            }

            $db->execute(
                "INSERT INTO system_error
                (level, message, exception_class, file, line, stack_trace, token, context, fingerprint, occurrences, user_id, ip_hash, ip_hash_key, user_agent, url, method, http_status, created_at, last_seen)
                VALUES
                (:level, :message, :exception_class, :file, :line, :stack_trace, :token, :context, :fingerprint, 1, :user_id, :ip_hash, :ip_hash_key, :ua, :url, :method, :status, NOW(), NOW())",
                [
                    ':level'           => 'error',
                    ':message'         => $message,
                    ':exception_class' => $exceptionClass,
                    ':file'            => $file,
                    ':line'            => $line,
                    ':stack_trace'     => $stack,
                    ':token'           => $token,
                    ':context'         => $jsonContext,
                    ':fingerprint'     => $fingerprint,
                    ':user_id'         => $userId,
                    ':ip_hash'         => $ipHash,
                    ':ip_hash_key'     => $ipKeyId,
                    ':ua'              => $ua,
                    ':url'             => $_SERVER['REQUEST_URI'] ?? null,
                    ':method'          => $_SERVER['REQUEST_METHOD'] ?? null,
                    ':status'          => http_response_code() ?: null,
                ]
            );
        } catch (\Throwable $ex) {
            return;
        }
    }

    /* -----------------------
       CONVENIENCE ALIASES (nahraď error_log(...) => Logger::error(...))
       ----------------------- */

        /* -----------------------
       SESSION / CSRF AUDIT
       ----------------------- */

    /**
     * Filter sensitive keys from meta before logging.
     * Removes keys like 'csrf','token','password','card_number','cc','cvv' etc.
     *
     * @param array|null $meta
     * @return array|null
     */
    private static function filterSensitive(?array $meta): ?array
    {
        if ($meta === null) return null;

        $blacklist = [
            'csrf', 'token', 'password', 'pwd', 'pass',
            'card_number', 'cardnum', 'cc_number', 'ccnum', 'cvv', 'cvc',
            'authorization', 'auth_token', 'api_key', 'secret'
        ];

        $clean = [];
        foreach ($meta as $k => $v) {
            $lk = strtolower((string)$k);
            if (in_array($lk, $blacklist, true)) {
                // replace with marker (do not log value)
                $clean[$k] = '[REDACTED]';
                continue;
            }
            // optionally, if value is an array, we can recurse shallowly but keep size small
            if (is_array($v)) {
                // shallow sanitize nested arrays
                $nested = [];
                foreach ($v as $nk => $nv) {
                    $nlk = strtolower((string)$nk);
                    if (in_array($nlk, $blacklist, true)) {
                        $nested[$nk] = '[REDACTED]';
                    } else {
                        $nested[$nk] = $nv;
                    }
                }
                $clean[$k] = $nested;
                continue;
            }
            $clean[$k] = $v;
        }

        return $clean;
    }

    /**
     * Log session-related audit events into session_audit table.
     *
     * @param string $event e.g. 'session_created','session_destroyed','session_regenerated','csrf_valid','csrf_invalid'
     * @param int|null $userId
     * @param array|null $meta - non-sensitive meta (will be filtered)
     * @param string|null $ip optional (raw IP; will be hashed before DB)
     * @param string|null $userAgent optional
     * @param string|null $outcome optional 'success'|'failure'|other
     */
    public static function session(string $event, ?int $userId = null, ?array $meta = null, ?string $ip = null, ?string $userAgent = null, ?string $outcome = null): void
    {
        if (!Database::isInitialized()) return;

        // allowed events (soft validation)
        $allowed = [
            'session_created','session_destroyed','session_regenerated',
            'csrf_valid','csrf_invalid','session_expired','session_activity'
        ];
        $event = in_array($event, $allowed, true) ? $event : 'session_activity';

        // centralised IP hashing
        $ipResult = self::getHashedIp($ip);
        $ipHash = $ipResult['hash'];       // string|null
        $ipKeyId = $ipResult['key_id'];    // version or null
        $ipUsed = $ipResult['used'];       // 'keymanager'|'env'|'fallback'|'none'

        $ua = $userAgent ?? self::getUserAgent();
        $filteredMeta = self::filterSensitive($meta) ?? [];
        // include which method/key was used (for future forensics), but do NOT include raw IP
        $filteredMeta['_ip_hash_used'] = $ipUsed;
        if ($ipKeyId !== null) {
            $filteredMeta['_ip_hash_key'] = $ipKeyId;
        }

        $json = self::safeJsonEncode($filteredMeta);

        // session id may be null (no active session)
        $sessId = null;
        if (function_exists('session_id')) {
            $sid = session_id();
            if ($sid !== '' && $sid !== null) $sessId = $sid;
        }

        try {
            $db = Database::getInstance();
            $db->execute(
                "INSERT INTO session_audit (session_id, event, user_id, ip_hash, ua, meta_json, outcome, created_at)
                VALUES (:session_id, :event, :user_id, :ip_hash, :ua, :meta, :outcome, NOW())",
                [
                    ':session_id' => $sessId,
                    ':event'      => $event,
                    ':user_id'    => $userId,
                    ':ip_hash'    => $ipHash,
                    ':ua'         => $ua,
                    ':meta'       => $json,
                    ':outcome'    => $outcome,
                ]
            );
        } catch (\Throwable $e) {
            // silent fail as per design - do not throw or echo
            return;
        }
    }

    public static function error(string $message, ?int $userId = null, ?array $context = null, ?string $token = null): void
    {
        self::systemMessage('error', $message, $userId, $context, $token, false);
    }

    public static function warn(string $message, ?int $userId = null, ?array $context = null): void
    {
        self::systemMessage('warning', $message, $userId, $context, null, false);
    }

    public static function info(string $message, ?int $userId = null, ?array $context = null): void
    {
        self::systemMessage('notice', $message, $userId, $context, null, false);
    }

    public static function critical(string $message, ?int $userId = null, ?array $context = null): void
    {
        self::systemMessage('critical', $message, $userId, $context, null, false);
    }
}
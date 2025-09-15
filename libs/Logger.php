<?php
declare(strict_types=1);

/**
 * Production-ready Logger
 *
 * - No debug output, no echo, no error_log debug messages.
 * - Deferred queue for writes before Database is initialized.
 * - Silent fail-on-error behaviour (design choice): logging must not break app flow.
 *
 * * After Database::init() in bootstrap, call DeferredHelper::flush();
 */

final class Logger
{
    private function __construct() {}
    const IP_HASH_BINARY = true;
    // -------------------------
    // HELPERS
    // -------------------------
    private static function truncateUserAgent(?string $ua): ?string
    {
        if ($ua === null) return null;
        return mb_substr($ua, 0, 255);
    }

    /**
     * Prepare IP storage form (hex string or binary depending on IP_HASH_BINARY).
     * Returns string|null (binary string when IP_HASH_BINARY is true).
     */
    private static function prepareIpForStorage(?string $ipHash)
    {
        if ($ipHash === null) return null;

        $useBinary = false;
        if (defined('IP_HASH_BINARY')) {
            $val = constant('IP_HASH_BINARY');
            $useBinary = is_bool($val) ? $val : filter_var((string)$val, FILTER_VALIDATE_BOOLEAN);
        } elseif (isset($_ENV['IP_HASH_BINARY'])) {
            $useBinary = filter_var($_ENV['IP_HASH_BINARY'], FILTER_VALIDATE_BOOLEAN);
        }

        if ($useBinary) {
            if (is_string($ipHash) && ctype_xdigit($ipHash) && strlen($ipHash) === 64) {
                $bin = @hex2bin($ipHash);
                return $bin === false ? null : $bin;
            }
            return null;
        }

        return $ipHash;
    }

    public static function getClientIp(): ?string
    {
        $trusted = $_ENV['TRUSTED_PROXIES'] ?? '';
        $trustedList = $trusted ? array_map('trim', explode(',', $trusted)) : [];
        $remote = $_SERVER['REMOTE_ADDR'] ?? null;
        $useForwarded = $remote && in_array($remote, $trustedList, true);

        $headers = ['HTTP_CF_CONNECTING_IP','HTTP_X_REAL_IP','HTTP_X_FORWARDED_FOR'];
        if ($useForwarded) {
            foreach ($headers as $h) {
                if (!empty($_SERVER[$h])) {
                    $ips = explode(',', $_SERVER[$h]);
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
        if ($ip && filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        return null;
    }

    /**
     * Compute hashed IP using KeyManager or APP_SALT or fallback sha256.
     * Returns ['hash'=>?string, 'key_id'=>?string, 'used'=>'keymanager'|'env'|'fallback'|'none']
     */
    public static function getHashedIp(?string $ip = null): array
    {
        $ipRaw = $ip ?? self::getClientIp();
        if ($ipRaw === null) return ['hash'=>null,'key_id'=>null,'used'=>'none'];

        // KeyManager attempt (best-effort; don't throw here)
        try {
            if (class_exists('KeyManager') && method_exists('KeyManager', 'getSaltInfo')) {
                $keysDir = defined('KEYS_DIR') ? KEYS_DIR : ($_ENV['KEYS_DIR'] ?? null);
                $saltInfo = \KeyManager::getSaltInfo($keysDir);
                $saltRaw = $saltInfo['raw'] ?? null;
                $saltVer = $saltInfo['version'] ?? null;
                if (!empty($saltRaw) && is_string($saltRaw)) {
                    $hmac = hash_hmac('sha256', $ipRaw, $saltRaw);
                    // attempt memzero if available (best-effort)
                    if (method_exists('KeyManager','memzero')) {
                        try { \KeyManager::memzero($saltRaw); } catch (\Throwable $_) {}
                    } elseif (function_exists('sodium_memzero')) {
                        @sodium_memzero($saltRaw);
                    }
                    return ['hash'=>$hmac,'key_id'=>$saltVer,'used'=>'keymanager'];
                }
            }
        } catch (\Throwable $e) {
            // silent fallback to env
        }

        // env APP_SALT fallback
        $envVal = $_ENV['APP_SALT'] ?? ($_SERVER['APP_SALT'] ?? null);
        if (!empty($envVal)) {
            $decoded = base64_decode($envVal, true);
            $secret = ($decoded !== false && $decoded !== '') ? $decoded : $envVal;
            $hmac = hash_hmac('sha256', $ipRaw, $secret);
            return ['hash'=>$hmac,'key_id'=>null,'used'=>'env'];
        }

        // last resort
        $h = hash('sha256', $ipRaw);
        return ['hash'=>$h,'key_id'=>null,'used'=>'fallback'];
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

    private static function filterSensitive(?array $meta): ?array
    {
        if ($meta === null) return null;
        $blacklist = ['csrf','token','password','pwd','pass','card_number','cardnum','cc_number','ccnum','cvv','cvc','authorization','auth_token','api_key','secret','g-recaptcha-response','recaptcha_token','recaptcha'];
        $clean = [];
        foreach ($meta as $k => $v) {
            $lk = strtolower((string)$k);
            if (in_array($lk, $blacklist, true)) {
                $clean[$k] = '[REDACTED]';
                continue;
            }
            if (is_array($v)) {
                $nested = [];
                foreach ($v as $nk => $nv) {
                    $nlk = strtolower((string)$nk);
                    $nested[$nk] = in_array($nlk, $blacklist, true) ? '[REDACTED]' : $nv;
                }
                $clean[$k] = $nested;
                continue;
            }
            $clean[$k] = $v;
        }
        return $clean;
    }

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

    // -------------------------
    // AUTH / REGISTER / VERIFY
    // -------------------------
    public static function auth(string $type, ?int $userId = null, ?array $meta = null, ?string $ip = null, ?string $userAgent = null): void
    {
        $type = self::validateAuthType($type);
        $userAgent = self::truncateUserAgent($userAgent ?? self::getUserAgent());

        $ipResult = self::getHashedIp($ip);
        $ipHash = self::prepareIpForStorage($ipResult['hash']);
        $ipKeyId = $ipResult['key_id'];
        $ipUsed = $ipResult['used'];

        $filteredMeta = self::filterSensitive($meta) ?? [];
        $filteredMeta['_ip_hash_used'] = $ipUsed;
        if ($ipKeyId !== null) $filteredMeta['_ip_hash_key'] = $ipKeyId;
        $json = self::safeJsonEncode($filteredMeta);

        $sql = "INSERT INTO auth_events (user_id, type, ip_hash, ip_hash_key, user_agent, occurred_at, meta)
                VALUES (:user_id, :type, :ip_hash, :ip_hash_key, :ua, UTC_TIMESTAMP(), :meta)";
        $params = [
            ':user_id' => $userId,
            ':type' => $type,
            ':ip_hash' => $ipHash,
            ':ip_hash_key' => $ipKeyId,
            ':ua' => $userAgent,
            ':meta' => $json,
        ];

        if (\Database::isInitialized()) {
            // flush earlier items to try to preserve ordering
            DeferredHelper::flush();
            try {
                \Database::getInstance()->execute($sql, $params);
            } catch (\Throwable $e) {
                // silent fail in production
                return;
            }
            return;
        }

        // DB not ready -> enqueue safe, pre-sanitized SQL/params
        DeferredHelper::enqueue(function() use ($sql, $params) {
            try {
                \Database::getInstance()->execute($sql, $params);
            } catch (\Throwable $e) {
                // silent fail – logger nikdy nesmí shodit aplikaci
            }
        });
    }

    public static function register(string $type, ?int $userId = null, ?array $meta = null, ?string $ip = null, ?string $userAgent = null): void
    {
        $type = self::validateRegisterType($type);
        $userAgent = self::truncateUserAgent($userAgent ?? self::getUserAgent());

        $ipResult = self::getHashedIp($ip);
        $ipHash = self::prepareIpForStorage($ipResult['hash']);
        $ipKeyId = $ipResult['key_id'];
        $ipUsed = $ipResult['used'];

        $filteredMeta = self::filterSensitive($meta) ?? [];
        $filteredMeta['_ip_hash_used'] = $ipUsed;
        if ($ipKeyId !== null) $filteredMeta['_ip_hash_key'] = $ipKeyId;
        $json = self::safeJsonEncode($filteredMeta);

        $sql = "INSERT INTO register_events (user_id, type, ip_hash, ip_hash_key, user_agent, occurred_at, meta)
                VALUES (:user_id, :type, :ip_hash, :ip_hash_key, :ua, UTC_TIMESTAMP(), :meta)";
        $params = [
            ':user_id' => $userId,
            ':type' => $type,
            ':ip_hash' => $ipHash,
            ':ip_hash_key' => $ipKeyId,
            ':ua' => $userAgent,
            ':meta' => $json,
        ];

        if (\Database::isInitialized()) {
            DeferredHelper::flush();
            try {
                \Database::getInstance()->execute($sql, $params);
            } catch (\Throwable $e) {
                return;
            }
            return;
        }

        DeferredHelper::enqueue(function() use ($sql, $params) {
            try {
                \Database::getInstance()->execute($sql, $params);
            } catch (\Throwable $e) {
                // silent fail – logger nikdy nesmí shodit aplikaci
            }
        });
    }

    public static function verify(string $type, ?int $userId = null, ?array $meta = null, ?string $ip = null, ?string $userAgent = null): void
    {
        $type = self::validateVerifyType($type);
        $userAgent = self::truncateUserAgent($userAgent ?? self::getUserAgent());

        $ipResult = self::getHashedIp($ip);
        $ipHash = self::prepareIpForStorage($ipResult['hash']);
        $ipKeyId = $ipResult['key_id'];
        $ipUsed = $ipResult['used'];

        $filteredMeta = self::filterSensitive($meta) ?? [];
        $filteredMeta['_ip_hash_used'] = $ipUsed;
        if ($ipKeyId !== null) $filteredMeta['_ip_hash_key'] = $ipKeyId;
        $json = self::safeJsonEncode($filteredMeta);

        $sql = "INSERT INTO verify_events (user_id, type, ip_hash, ip_hash_key, user_agent, occurred_at, meta)
                VALUES (:user_id, :type, :ip_hash, :ip_hash_key, :ua, UTC_TIMESTAMP(), :meta)";
        $params = [
            ':user_id' => $userId,
            ':type' => $type,
            ':ip_hash' => $ipHash,
            ':ip_hash_key' => $ipKeyId,
            ':ua' => $userAgent,
            ':meta' => $json,
        ];

        if (\Database::isInitialized()) {
            DeferredHelper::flush();
            try {
                \Database::getInstance()->execute($sql, $params);
            } catch (\Throwable $e) {
                return;
            }
            return;
        }

        DeferredHelper::enqueue(function() use ($sql, $params) {
            try {
                \Database::getInstance()->execute($sql, $params);
            } catch (\Throwable $e) {
                // silent fail – logger nikdy nesmí shodit aplikaci
            }
        });
    }

    // -------------------------
    // SESSION AUDIT
    // -------------------------
    public static function session(string $event, ?int $userId = null, ?array $meta = null, ?string $ip = null, ?string $userAgent = null, ?string $outcome = null, ?string $tokenHashBin = null): void
    {
        $allowed = ['session_created','session_destroyed','session_regenerated','csrf_valid','csrf_invalid','session_expired','session_activity'];
        $event = in_array($event, $allowed, true) ? $event : 'session_activity';

        $ipResult = self::getHashedIp($ip);
        $ipHash = self::prepareIpForStorage($ipResult['hash']);
        $ipKeyId = $ipResult['key_id'];
        $ipUsed = $ipResult['used'];

        $ua = self::truncateUserAgent($userAgent ?? self::getUserAgent());
        $filteredMeta = self::filterSensitive($meta) ?? [];
        $filteredMeta['_ip_hash_used'] = $ipUsed;
        if ($ipKeyId !== null) $filteredMeta['_ip_hash_key'] = $ipKeyId;
        $json = self::safeJsonEncode($filteredMeta);

        $sessId = null;
        if (function_exists('session_id')) {
            $sid = session_id();
            if ($sid !== '' && $sid !== null) $sessId = $sid;
        }

        $sql = "INSERT INTO session_audit (session_token, session_id, event, user_id, ip_hash, ip_hash_key, ua, meta_json, outcome, created_at)
                VALUES (:session_token, :session_id, :event, :user_id, :ip_hash, :ip_hash_key, :ua, :meta, :outcome, UTC_TIMESTAMP())";
        $params = [
            ':session_token' => $tokenHashBin,
            ':session_id' => $sessId,
            ':event' => $event,
            ':user_id' => $userId,
            ':ip_hash' => $ipHash,
            ':ip_hash_key' => $ipKeyId,
            ':ua' => $ua,
            ':meta' => $json,
            ':outcome' => $outcome,
        ];

        if (\Database::isInitialized()) {
            DeferredHelper::flush();
            try {
                \Database::getInstance()->execute($sql, $params);
            } catch (\Throwable $e) {
                return;
            }
            return;
        }

        DeferredHelper::enqueue(function() use ($sql, $params) {
            try {
                \Database::getInstance()->execute($sql, $params);
            } catch (\Throwable $e) {
                // silent fail – logger nikdy nesmí shodit aplikaci
            }
        });
    }

    // -------------------------
    // SYSTEM MESSAGE / ERROR (with fingerprint aggregation)
    // -------------------------
    public static function systemMessage(string $level, string $message, ?int $userId = null, ?array $context = null, ?string $token = null, bool $aggregateByFingerprint = false): void
    {
        $level = in_array($level, ['notice','warning','error','critical'], true) ? $level : 'error';
        $ipResult = self::getHashedIp(null);
        $ipHash = self::prepareIpForStorage($ipResult['hash']);
        $ipKeyId = $ipResult['key_id'];
        $ipUsed = $ipResult['used'];
        $ua = self::truncateUserAgent(self::getUserAgent());

        $file = $context['file'] ?? null;
        $line = $context['line'] ?? null;
        $fingerprint = hash('sha256', $level . '|' . $message . '|' . ($file ?? '') . ':' . ($line ?? ''));
        $context = $context ?? [];
        $context['_ip_hash_used'] = $ipUsed;
        if ($ipKeyId !== null) $context['_ip_hash_key'] = $ipKeyId;
        $jsonContext = self::safeJsonEncode($context);

        // Upsert style: requires UNIQUE index on fingerprint in DB
        $sql = "INSERT INTO system_error
            (level, message, exception_class, file, line, stack_trace, token, context, fingerprint, occurrences, user_id, ip_hash, ip_hash_key, user_agent, url, method, http_status, created_at, last_seen)
            VALUES (:level, :message, NULL, :file, :line, NULL, :token, :context, :fingerprint, 1, :user_id, :ip_hash, :ip_hash_key, :ua, :url, :method, :status, UTC_TIMESTAMP(), UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE occurrences = occurrences + 1, last_seen = UTC_TIMESTAMP(), message = VALUES(message)";

        $params = [
            ':level' => $level,
            ':message' => $message,
            ':file' => $file,
            ':line' => $line,
            ':token' => $token,
            ':context' => $jsonContext,
            ':fingerprint' => $fingerprint,
            ':user_id' => $userId,
            ':ip_hash' => $ipHash,
            ':ip_hash_key' => $ipKeyId,
            ':ua' => $ua,
            ':url' => $_SERVER['REQUEST_URI'] ?? null,
            ':method' => $_SERVER['REQUEST_METHOD'] ?? null,
            ':status' => http_response_code() ?: null,
        ];

        if (\Database::isInitialized()) {
            DeferredHelper::flush();
            try {
                \Database::getInstance()->execute($sql, $params);
            } catch (\Throwable $e) {
                return;
            }
            return;
        }

        DeferredHelper::enqueue(function() use ($sql, $params) {
            try {
                \Database::getInstance()->execute($sql, $params);
            } catch (\Throwable $e) {
                // silent fail – logger nikdy nesmí shodit aplikaci
            }
        });
    }

    public static function systemError(\Throwable $e, ?int $userId = null, ?string $token = null, ?array $context = null, bool $aggregateByFingerprint = true): void
    {
        if ($e instanceof \PDOException) {
            $message = 'Database error';
        } else {
            $message = (string)$e->getMessage();
        }

        $exceptionClass = get_class($e);
        $file = $e->getFile();
        $line = $e->getLine();
        $stack = !empty($_ENV['DEBUG']) ? $e->getTraceAsString() : null;

        $ipResult = self::getHashedIp(null);
        $ipHash = self::prepareIpForStorage($ipResult['hash']);
        $ipKeyId = $ipResult['key_id'];
        $ipUsed = $ipResult['used'];

        $ua = self::truncateUserAgent(self::getUserAgent());
        $fingerprint = hash('sha256', $message . '|' . $exceptionClass . '|' . $file . ':' . $line);

        $context = $context ?? [];
        $context['_ip_hash_used'] = $ipUsed;
        if ($ipKeyId !== null) $context['_ip_hash_key'] = $ipKeyId;
        $jsonContext = self::safeJsonEncode($context);

        $sql = "INSERT INTO system_error
            (level, message, exception_class, file, line, stack_trace, token, context, fingerprint, occurrences, user_id, ip_hash, ip_hash_key, user_agent, url, method, http_status, created_at, last_seen)
            VALUES ('error', :message, :exception_class, :file, :line, :stack_trace, :token, :context, :fingerprint, 1, :user_id, :ip_hash, :ip_hash_key, :ua, :url, :method, :status, UTC_TIMESTAMP(), UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE occurrences = occurrences + 1, last_seen = UTC_TIMESTAMP(), stack_trace = VALUES(stack_trace)";

        $params = [
            ':message' => $message,
            ':exception_class' => $exceptionClass,
            ':file' => $file,
            ':line' => $line,
            ':stack_trace' => $stack,
            ':token' => $token,
            ':context' => $jsonContext,
            ':fingerprint' => $fingerprint,
            ':user_id' => $userId,
            ':ip_hash' => $ipHash,
            ':ip_hash_key' => $ipKeyId,
            ':ua' => $ua,
            ':url' => $_SERVER['REQUEST_URI'] ?? null,
            ':method' => $_SERVER['REQUEST_METHOD'] ?? null,
            ':status' => http_response_code() ?: null,
        ];

        if (\Database::isInitialized()) {
            DeferredHelper::flush();
            try {
                \Database::getInstance()->execute($sql, $params);
            } catch (\Throwable $ex) {
                return;
            }
            return;
        }

        DeferredHelper::enqueue(function() use ($sql, $params) {
            try {
                \Database::getInstance()->execute($sql, $params);
            } catch (\Throwable $e) {
                // silent fail – logger nikdy nesmí shodit aplikaci
            }
        });
    }

    // Convenience aliases
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
        self::systemMessage('critical', $message, null, $context, null, false);
    }
}
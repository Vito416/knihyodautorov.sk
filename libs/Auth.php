<?php
declare(strict_types=1);

final class Auth
{
    /**
     * Safe wrappers for LoginLimiter to avoid fatal errors if limiter API is missing or throws.
     */
    private static function limiterIsBlocked(?string $clientIp): bool
    {
        if (!class_exists('LoginLimiter') || !method_exists('LoginLimiter', 'isBlocked')) {
            return false; // fail-open
        }
        try {
            return (bool) LoginLimiter::isBlocked($clientIp);
        } catch (\Throwable $_) {
            return false; // fail-open
        }
    }

    private static function limiterGetSecondsUntilUnblock(?string $clientIp): int
    {
        if (!class_exists('LoginLimiter') || !method_exists('LoginLimiter', 'getSecondsUntilUnblock')) {
            return 0;
        }
        try {
            return (int) LoginLimiter::getSecondsUntilUnblock($clientIp);
        } catch (\Throwable $_) {
            return 0;
        }
    }

    /**
     * @param string|null $clientIp
     * @param bool $success
     * @param int|null $userId
     * @param string|resource|null $usernameHashBinForAttempt Binary string or null
     */
    private static function limiterRegisterAttempt(?string $clientIp, bool $success, ?int $userId, $usernameHashBinForAttempt): void
    {
        if (!class_exists('LoginLimiter') || !method_exists('LoginLimiter', 'registerAttempt')) {
            return;
        }
        try {
            LoginLimiter::registerAttempt($clientIp, $success, $userId, $usernameHashBinForAttempt);
        } catch (\Throwable $_) {
            // best-effort: ignore limiter failures
        }
    }

    private static function getArgon2Options(): array
    {
        // Defaults (reasonable safe baseline)
        $defaultMemory = 1 << 16; // 64 MiB
        $defaultTime   = 4;
        $defaultThreads= 2;

        // Read env / sanitize
        $mem = isset($_ENV['ARGON_MEMORY_KIB']) ? (int)$_ENV['ARGON_MEMORY_KIB'] : $defaultMemory;
        $time = isset($_ENV['ARGON_TIME_COST']) ? (int)$_ENV['ARGON_TIME_COST'] : $defaultTime;
        $threads = isset($_ENV['ARGON_THREADS']) ? (int)$_ENV['ARGON_THREADS'] : $defaultThreads;

        // Safety caps - tune to your infra
        $maxMemory = 1 << 20; // 1 GiB (in KiB -> 1048576)
        $maxTime = 10;
        $maxThreads = 8;

        // enforce minimums and maximums
        $mem = max(1 << 12, min($mem, $maxMemory)); // min 4 MiB
        $time = max(1, min($time, $maxTime));
        $threads = max(1, min($threads, $maxThreads));

        return [
            'memory_cost' => $mem,
            'time_cost'   => $time,
            'threads'     => $threads,
        ];
    }

    private static function updateEmailHashIdempotent(PDO $db, int $userId, string $hashBin, string $hashVer): void
    {
        $sql = 'UPDATE pouzivatelia SET email_hash = :h, email_hash_key_version = :v WHERE id = :id AND (email_hash IS NULL OR email_hash = "")';
        $stmt = $db->prepare($sql);
        $stmt->bindValue(':h', $hashBin, PDO::PARAM_LOB);
        $stmt->bindValue(':v', $hashVer, PDO::PARAM_STR);
        $stmt->bindValue(':id', $userId, PDO::PARAM_INT);
        try {
            $stmt->execute();
        } catch (\Throwable $e) {
            if (class_exists('Logger')) {
                Logger::error('Auth: email_hash idempotent update failed (likely race)', $userId, ['err' => $e->getMessage()]);
            }
            // swallow - race is acceptable
        }
    }

    /**
     * Require KEYS_DIR to be defined and non-empty. Throws RuntimeException otherwise.
     *
     * Use this when the code cannot operate without keys (production behaviour).
     *
     * @return string absolute path to keys dir
     * @throws \RuntimeException if KEYS_DIR isn't defined or is empty
     */
    private static function requireKeysDir(): string
    {
        if (defined('KEYS_DIR') && is_string(KEYS_DIR) && KEYS_DIR !== '') {
            return KEYS_DIR;
        }

        // If you sometimes use environment variable instead of define(), and you want the strict behaviour
        // remove the env check below. For strictness as you requested, we *do not* fallback to env.
        // If you *do* want to accept $_ENV['KEYS_DIR'] as well, uncomment the following lines:
        // $env = $_ENV['KEYS_DIR'] ?? null;
        // if (is_string($env) && $env !== '') return $env;

        throw new \RuntimeException('KEYS_DIR is not defined. Set KEYS_DIR constant in config before calling Auth.');
    }
    /**
     * Get pepper raw bytes + version or throw if unavailable.
     * Production requirement: KeyManager must be present and provide the key.
     *
     * Return: ['raw'=>binary32, 'version'=>'vN']
     * Throws RuntimeException on any missing/invalid key.
     */
    private static function getPepperInfo(): array
    {
        // Do not cache raw pepper bytes in static scope to minimize time they exist in memory.
        if (!class_exists('KeyManager') || !method_exists('KeyManager', 'getPasswordPepperInfo')) {
            throw new \RuntimeException('KeyManager::getPasswordPepperInfo required but not available');
        }

        $keysDir = self::requireKeysDir();

        try {
            $info = KeyManager::getPasswordPepperInfo($keysDir);
        } catch (\Throwable $e) {
            throw new \RuntimeException('KeyManager error while fetching PASSWORD_PEPPER: ' . $e->getMessage());
        }

        if (empty($info['raw']) || !is_string($info['raw']) || strlen($info['raw']) !== 32) {
            throw new \RuntimeException('PASSWORD_PEPPER not available or invalid (expected 32 raw bytes)');
        }

        $version = $info['version'] ?? 'v1';
        return ['raw' => $info['raw'], 'version' => $version];
    }

    /**
     * Return pepper version string suitable for storing in DB (heslo_key_version).
     * This function will throw if KeyManager or key is unavailable.
     *
     * @return string  e.g. 'v1', 'v2'
     * @throws \RuntimeException if pepper is unavailable/invalid
     */
    public static function getPepperVersionForStorage(): string
    {
        $pep = self::getPepperInfo();
        return $pep['version'];
    }

    /**
     * Preprocess password using pepper (HMAC-SHA256).
     * Returns binary string (raw) suitable for password_hash/verify.
     * Assumes KeyManager is present (getPepperInfo() will throw otherwise).
     */
    private static function preprocessPassword(string $password): string
    {
        $pep = self::getPepperInfo();
        // Use raw binary pepper; produce binary HMAC to pass to password_hash.
        return hash_hmac('sha256', $password, $pep['raw'], true);
    }

    /**
     * Create password hash. Returns hash string (same as password_hash).
     * The caller should store also heslo_algo (see below) — this method returns only the hash.
     */
    public static function hashPassword(string $password): string
    {
        $inp = self::preprocessPassword($password);
        $hash = password_hash($inp, PASSWORD_ARGON2ID, self::getArgon2Options());
        if ($hash === false) {
            throw new \RuntimeException('password_hash failed');
        }
        return $hash;
    }

    /**
     * Build heslo_algo metadata string to store in DB alongside heslo_hash.
     * Now returns only algorithm name (e.g. "argon2id").
     */
    public static function buildHesloAlgoMetadata(string $hash): string
    {
        $info = password_get_info($hash);
        $algoName = $info['algoName'] ?? 'unknown';
        return $algoName;
    }
    
    /**
     * Verify password.
     *
     * Behavior:
     *  - If DB contains heslo_key_version (pepper version), require pepper verification only.
     *  - If DB does not contain a pepper version, try peppered verification first (if we currently have a pepper),
     *    then fallback to plain password verification (migration-friendly).
     *
     * @param string $password plaintext password from user
     * @param string $storedHash stored password hash from DB
     * @param ?string $hesloKeyVersion value of heslo_key_version column from DB (eg. 'v1') or null
     * @return bool
     */
    public static function verifyPassword(string $password, string $storedHash, ?string $hesloKeyVersion = null): bool
    {
        // Pokus získat pepper - pokud není dostupný, necháme $pep === null a rozhodneme podle hodnoty v DB.
        $pep = null;
        try {
            $pep = self::getPepperInfo();
        } catch (\Throwable $_) {
            // Pepper není momentálně dostupný — nechceme hned padnout pro účty bez heslo_key_version.
            $pep = null;
        }

        // Pokud DB explicitně obsahuje heslo_key_version, ověření vyžaduje pepper.
        if ($hesloKeyVersion !== null && $hesloKeyVersion !== '') {
            if ($pep === null) {
                // Konfigurace/cílový stav: účet byl uložen s pepperem, ale my ho nemáme -> považujeme to za kritickou chybu.
                // Neprovádíme fallback na nepeperované ověření, aby se nezneužily hesla.
                throw new \RuntimeException('Password pepper required by account but currently unavailable');
            }
            $pre = hash_hmac('sha256', $password, $pep['raw'], true);
            return password_verify($pre, $storedHash);
        }

        // Pokud DB nemá heslo_key_version, zkusíme ověřit pomocí současného pepperu (pokud existuje),
        // a pokud to selže, fallback na plain password_verify (migration-friendly).
        if ($pep !== null) {
            $pre = hash_hmac('sha256', $password, $pep['raw'], true);
            if (password_verify($pre, $storedHash)) return true;
        }

        return password_verify($password, $storedHash);
    }

    /**
     * Login by email + password.
     * Returns associative result:
     *  ['success' => bool, 'user' => array|null, 'message' => string]
     *
     * Note: does NOT create session/cookies - leave that to controller (or integrate SessionManager / JWT here).
     */
    public static function login(PDO $db, string $email, string $password, int $maxFailed = 5): array
    {
        // základní validace formátu (ale NELOGUJEME a NEUCHOVÁVÁME plain email)
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            if (class_exists('Logger')) {
                Logger::auth('login_failure', null);
            } else {
                throw new \RuntimeException('Logger required for auth logging');
            }
            // uniform delay to mitigate enumeration/timing
            usleep(150_000);
            return ['success' => false, 'user' => null, 'message' => 'Neplatné přihlášení'];
        }

        // Normalise email
        $emailNormalized = strtolower(trim($email));

        // get client IP once (best-effort)
        $clientIp = null;
        try {
            if (class_exists('Logger') && method_exists('Logger', 'getClientIp')) {
                $clientIp = Logger::getClientIp();
            }
        } catch (\Throwable $_) {
            $clientIp = null;
        }

        // limiter check using clientIp (defensive + anonymized logging)
        try {
            if (self::limiterIsBlocked($clientIp)) {
                $secs = self::limiterGetSecondsUntilUnblock($clientIp);

                // anonymize IP for logs: short prefix of HMAC (if available)
                $ipShort = null;
                try {
                    if (class_exists('Logger') && method_exists('Logger', 'getHashedIp')) {
                        $r = Logger::getHashedIp($clientIp);
                        $hb = $r['hash'] ?? null;
                        if (is_string($hb) && strlen($hb) >= 4) {
                            $ipShort = substr(bin2hex($hb), 0, 8); // first 8 hex chars
                        }
                    }
                } catch (\Throwable $_) {
                    $ipShort = null;
                }
                if (class_exists('Logger')) {
                    if (method_exists('Logger', 'info')) {
                        Logger::info('Auth: login blocked by limiter', null, ['ip_sh' => $ipShort, 'wait_s' => $secs]);
                    }
                    if (method_exists('Logger', 'auth')) {
                        Logger::auth('login_failure', null);
                    }
                }
                $msg = $secs > 0 ? "Příliš mnoho pokusů. Zkuste za {$secs} sekund." : "Příliš mnoho pokusů. Vyzkoušej později.";
                return ['success' => false, 'user' => null, 'message' => $msg];
            }
        } catch (\Throwable $_) {
            // fail-open: pokud limiter selže, pokračujeme v login flow
        }

        // 1) HMAC-based lookup of user by email_hash (no plaintext email SELECT)
        $u = false;
        $usernameHashBinForAttempt = null; // will be passed to LoginLimiter.registerAttempt
        try {
            if (!class_exists('KeyManager')) {
                // KeyManager required for secure email lookup
                throw new \RuntimeException('KeyManager required for secure email lookup');
            }
            $keysDir = self::requireKeysDir();
            if (!method_exists('KeyManager', 'deriveHmacWithLatest') || !method_exists('KeyManager', 'deriveHmacCandidates')) {
                throw new \RuntimeException('KeyManager deriveHmac helpers missing (deriveHmacWithLatest / deriveHmacCandidates required)');
            }
            // compute latest username/email HMAC for recording attempt (best-effort)
            try {
                $hinfoLatest = KeyManager::deriveHmacWithLatest('EMAIL_HASH_KEY', $keysDir, 'email_hash_key', $emailNormalized);
                $usernameHashBinForAttempt = $hinfoLatest['hash'] ?? null; // binary or null
            } catch (\Throwable $_) {
                // best-effort: if we can't derive latest, keep null
                $usernameHashBinForAttempt = null;
            }

            // derive candidate hashes (supports key rotation) and try them one by one
            $candidates = KeyManager::deriveHmacCandidates('EMAIL_HASH_KEY', $keysDir, 'email_hash_key', $emailNormalized);
            if (!empty($candidates) && is_array($candidates)) {
                $q = $db->prepare('SELECT id, email_hash, email_hash_key_version, email_enc, email_key_version, heslo_hash, heslo_algo, heslo_key_version, is_active, is_locked, failed_logins, actor_type
                                FROM pouzivatelia WHERE email_hash = :h LIMIT 1');
                foreach ($candidates as $cand) {
                    if (!isset($cand['hash'])) continue;
                    $hashBin = $cand['hash']; // binary
                    $q->bindValue(':h', $hashBin, PDO::PARAM_LOB);
                    $q->execute();
                    $found = $q->fetch(PDO::FETCH_ASSOC);
                    if ($found) { $u = $found; $emailFound = true; break; }
                }
            }
        } catch (\Throwable $e) {
            // critical: cannot perform secure lookup -> log and return server error
            if (class_exists('Logger')) {
                Logger::systemError($e);
                return ['success' => false, 'user' => null, 'message' => 'Chyba serveru'];
            } else {
                throw new \RuntimeException('Logger required for system error reporting');
            }
        }

        // 2) If user not found or not active/locked -> register failed attempt and return generic failure
        if (!$u || empty($u['is_active']) || !empty($u['is_locked'])) {
            $userId = is_array($u) && isset($u['id']) ? (int)$u['id'] : null;
            // register IP attempt (failure) - best-effort
            self::limiterRegisterAttempt($clientIp, false, $userId, $usernameHashBinForAttempt);

            if (class_exists('Logger')) {
                if (method_exists('Logger', 'auth')) {
                    Logger::auth('login_failure', $userId);
                }
            } else {
                throw new \RuntimeException('Logger required for auth logging');
            }
            usleep(150_000);
            return ['success' => false, 'user' => null, 'message' => 'Neplatné přihlášení'];
        }

        // 3) Verify password (may require pepper)
        $storedHash = (string)($u['heslo_hash'] ?? '');
        $hesloKeyVersion = isset($u['heslo_key_version']) && $u['heslo_key_version'] !== '' ? (string)$u['heslo_key_version'] : null;

        try {
            $ok = self::verifyPassword($password, $storedHash, $hesloKeyVersion);
        } catch (\Throwable $e) {
            // critical error (pepper missing etc.)
            if (class_exists('Logger')) Logger::systemError($e, $u['id'] ?? null);
            return ['success' => false, 'user' => null, 'message' => 'Chyba serveru'];
        }

        if (!$ok) {
            // password incorrect -> increment failed_logins and possibly lock account
            try {
            $stmt = $db->prepare('UPDATE pouzivatelia
                                SET failed_logins = failed_logins + 1,
                                    is_locked = CASE WHEN failed_logins + 1 >= :max THEN 1 ELSE is_locked END
                                WHERE id = :id');
            $stmt->execute([':max' => $maxFailed, ':id' => $u['id']]);

            $stmt = $db->prepare('SELECT failed_logins, is_locked FROM pouzivatelia WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $u['id']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $failed = (int)($row['failed_logins'] ?? 0);
            $isLocked = !empty($row['is_locked']);
            if ($isLocked) {
                if (class_exists('Logger')) Logger::auth('lockout', $u['id']);
            } else {
                if (class_exists('Logger')) Logger::auth('login_failure', $u['id']);
            }
            } catch (\Throwable $e) {
                if (class_exists('Logger')) Logger::systemError($e, $u['id'] ?? null);
            }

            // register IP limiter failure
            self::limiterRegisterAttempt($clientIp, false, (int)$u['id'], $usernameHashBinForAttempt);

            return ['success' => false, 'user' => null, 'message' => 'Neplatné přihlášení'];
        }

        // 4) Successful login: reset failed counters, update last_login, and record success in limiter
        try {
            // get IP hash info for storing in users table
            if (!class_exists('Logger')) throw new \RuntimeException('Logger required for IP hashing helper');
            $ipResult = ['hash' => null, 'key_id' => null];
            if (class_exists('Logger') && method_exists('Logger','getHashedIp')) {
                try { $ipResult = Logger::getHashedIp($clientIp); } catch (\Throwable $_) { /* keep defaults */ }
            }
            $ipHashBin = $ipResult['hash'] ?? null;
            $ipKeyId = $ipResult['key_id'] ?? null;

            $stmt = $db->prepare('UPDATE pouzivatelia
                                    SET failed_logins = 0,
                                        last_login_at = UTC_TIMESTAMP(),
                                        last_login_ip_hash = :ip_hash,
                                        last_login_ip_key = :ip_key
                                    WHERE id = :id');

            if ($ipHashBin !== null) {
                $stmt->bindValue(':ip_hash', $ipHashBin, PDO::PARAM_LOB);
            } else {
                $stmt->bindValue(':ip_hash', null, PDO::PARAM_NULL);
            }
            $stmt->bindValue(':ip_key', $ipKeyId, $ipKeyId !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->bindValue(':id', $u['id'], PDO::PARAM_INT);
            $stmt->execute();
        } catch (\Throwable $e) {
            if (class_exists('Logger')) Logger::systemError($e, $u['id'] ?? null);
        }

        // register success attempt in limiter (best-effort)
        self::limiterRegisterAttempt($clientIp, true, (int)$u['id'], $usernameHashBinForAttempt);

        // --- automatic email migration (hash + optional encryption) ---
        try {
            // use normalized input email (never store plain email)
            $emailToMigrate = $emailNormalized;
            $keysDir = self::requireKeysDir();

            // 1) email_hash: fill if missing (idempotent)
            if (empty($u['email_hash']) && $emailToMigrate !== '' && class_exists('KeyManager')) {
                try {
                    $hinfo = KeyManager::deriveHmacWithLatest('EMAIL_HASH_KEY', $keysDir, 'email_hash_key', $emailToMigrate);
                    $hashBin = $hinfo['hash'] ?? null;
                    $hashVer = $hinfo['version'] ?? ($hinfo['ver'] ?? 'v1');

                    if ($hashBin !== null) {
                        // use centralized helper: idempotent and logs on failure
                        self::updateEmailHashIdempotent($db, (int)$u['id'], $hashBin, $hashVer);
                    }
                } catch (\Throwable $e) {
                    if (class_exists('Logger')) Logger::error('Auth: email_hash derive failed', $u['id'] ?? null, ['exception' => (string)$e]);
                }
            }

            // 2) email_enc: encrypt and store if missing (idempotent)
            if (empty($u['email_enc']) && $emailToMigrate !== '' && class_exists('KeyManager') && function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
                try {
                    if (!method_exists('KeyManager', 'getEmailKeyInfo')) {
                        throw new \RuntimeException('KeyManager::getEmailKeyInfo missing');
                    }
                    $ek = KeyManager::getEmailKeyInfo($keysDir);
                    $emailKeyRaw = $ek['raw'] ?? null;
                    $emailKeyVer = $ek['version'] ?? 'v1';
                    if (!method_exists('KeyManager', 'keyByteLen')) {
                        throw new \RuntimeException('KeyManager::keyByteLen missing');
                    }
                    if (is_string($emailKeyRaw) && strlen($emailKeyRaw) === KeyManager::keyByteLen()) {
                        $nonceLen = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
                        $nonce = random_bytes($nonceLen);
                        $ad = 'email:enc:v1';
                        $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($emailToMigrate, $ad, $nonce, $emailKeyRaw);
                        if ($cipher === false) throw new \RuntimeException('email encrypt failed');

                        $encPayload = chr(1) . chr($nonceLen) . $nonce . $cipher;

                        $upd2 = $db->prepare('UPDATE pouzivatelia
                                            SET email_enc = :enc, email_key_version = :kv
                                            WHERE id = :id AND (email_enc IS NULL OR email_enc = "")');
                        $upd2->bindValue(':enc', $encPayload, PDO::PARAM_LOB);
                        $upd2->bindValue(':kv', $emailKeyVer, PDO::PARAM_STR);
                        $upd2->bindValue(':id', $u['id'], PDO::PARAM_INT);

                        try { $upd2->execute(); } catch (\Throwable $e) {
                            if (class_exists('Logger')) Logger::error('Auth: email_enc update failed on login', $u['id'], ['exception' => (string)$e]);
                        }

                        try { KeyManager::memzero($emailKeyRaw); } catch (\Throwable $_) {}
                        unset($emailKeyRaw);
                    }
                } catch (\Throwable $e) {
                    if (class_exists('Logger')) Logger::error('Auth: email encryption failed', $u['id'] ?? null, ['exception' => (string)$e]);
                }
            }
        } catch (\Throwable $e) {
            if (class_exists('Logger')) Logger::error('Auth: email migration on login failed', $u['id'] ?? null, ['exception' => (string)$e]);
        }
        // --- end migration ---

        // --- počítání rehash mimo transakci (pokud je potřeba) ---
        $needRehash = password_needs_rehash($storedHash, PASSWORD_ARGON2ID, self::getArgon2Options());
        $newHash = null;
        $newAlgoMeta = null;
        $newPepver = null;
        if ($needRehash) {
            // compute normalized new hash outside transaction (CPU work)
            $newHash = self::hashPassword($password); // může throw
            $newAlgoMeta = self::buildHesloAlgoMetadata($newHash);
            try {
                $newPepver = self::getPepperVersionForStorage();
            } catch (\Throwable $inner) {
                $newPepver = null;
                if (class_exists('Logger')) Logger::error('Auth: cannot obtain pepper version during rehash', $u['id'] ?? null, ['exception' => (string)$inner]);
            }
        }

        // --- atomická transakce: reset failed + last_login + optional password update ---
        try {
            $db->beginTransaction();

            $stmt = $db->prepare('UPDATE pouzivatelia
                                    SET failed_logins = 0,
                                        last_login_at = UTC_TIMESTAMP(),
                                        last_login_ip_hash = :ip_hash,
                                        last_login_ip_key = :ip_key
                                    WHERE id = :id');
            if ($ipHashBin !== null) {
                $stmt->bindValue(':ip_hash', $ipHashBin, PDO::PARAM_LOB);
            } else {
                $stmt->bindValue(':ip_hash', null, PDO::PARAM_NULL);
            }
            $stmt->bindValue(':ip_key', $ipKeyId, $ipKeyId !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->bindValue(':id', $u['id'], PDO::PARAM_INT);
            $stmt->execute();

            if ($newHash !== null) {
                if ($newPepver !== null) {
                    $sth = $db->prepare('UPDATE pouzivatelia SET heslo_hash = :hash, heslo_algo = :meta, heslo_key_version = :pep WHERE id = :id');
                    $sth->bindValue(':hash', $newHash, PDO::PARAM_STR);
                    $sth->bindValue(':meta', $newAlgoMeta, PDO::PARAM_STR);
                    $sth->bindValue(':pep', $newPepver, PDO::PARAM_STR);
                    $sth->bindValue(':id', $u['id'], PDO::PARAM_INT);
                    $sth->execute();
                } else {
                    $sth = $db->prepare('UPDATE pouzivatelia SET heslo_hash = :hash, heslo_algo = :meta WHERE id = :id');
                    $sth->bindValue(':hash', $newHash, PDO::PARAM_STR);
                    $sth->bindValue(':meta', $newAlgoMeta, PDO::PARAM_STR);
                    $sth->bindValue(':id', $u['id'], PDO::PARAM_INT);
                    $sth->execute();
                }
            }

            $db->commit();
            // wipe sensitive temporaries
            if (isset($newHash)) { unset($newHash); }
            if (isset($newAlgoMeta)) { unset($newAlgoMeta); }
            if (isset($newPepver)) { unset($newPepver); }
        } catch (\Throwable $e) {
            if ($db->inTransaction()) { try { $db->rollBack(); } catch (\Throwable $_) {} }
            if (class_exists('Logger')) Logger::systemError($e, $u['id'] ?? null);
        }

        if (class_exists('Logger')) Logger::auth('login_success', $u['id']);

        $allowed = ['id','actor_type','is_active','last_login_at']; // extend intentionally
        $uSafe = [];
        foreach ($allowed as $k) {
            if (array_key_exists($k, $u)) $uSafe[$k] = $u[$k];
        }
        // hygiene: remove sensitive temporaries from local scope
        try { unset($password, $emailNormalized, $usernameHashBinForAttempt); } catch (\Throwable $_) {}
        return ['success' => true, 'user' => $uSafe, 'message' => 'OK'];

    }

    /**
     * Check if user is admin by actor_type
     */
    public static function isAdmin(array $userData): bool
    {
        return isset($userData['actor_type']) && $userData['actor_type'] === 'admin';
    }
}
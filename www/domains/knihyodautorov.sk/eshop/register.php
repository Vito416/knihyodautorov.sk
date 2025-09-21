<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';

/**
 * register.php (slovensky, production-ready, integrované s Mailer::enqueue)
 */

// overenie kritických závislostí (KeyManager, Logger a Validator sú požadované)
$required = ['KeyManager', 'Logger', 'Validator'];
$missing = [];
foreach ($required as $c) {
    if (!class_exists($c)) $missing[] = $c;
}
if (!empty($missing)) {
    $msg = 'Interná chyba: chýbajú knižnice: ' . implode(', ', $missing) . '.';
    if (class_exists('Logger')) { try { Logger::systemError(new \RuntimeException($msg)); } catch (\Throwable $_) {} }
    http_response_code(500);
    echo Templates::render('pages/error.php', ['message' => $msg]);
    exit;
}

// KEYS_DIR musí byť definovaný pre KeyManager (security-first)
if (!defined('KEYS_DIR') || !is_string(KEYS_DIR) || KEYS_DIR === '') {
    $msg = 'Interná chyba: KEYS_DIR nie je nastavený. Skontrolujte konfiguráciu kľúčov.';
    try { Logger::systemError(new \RuntimeException($msg)); } catch (\Throwable $_) {}
    http_response_code(500);
    echo Templates::render('pages/error.php', ['message' => $msg]);
    exit;
}

/**
 * Lokálny helper na Argon2 možnosti (rovnako ako v Auth)
 */
$argonOptions = static function(): array {
    $defaultMemory = 1 << 16; // 64 MiB
    $defaultTime   = 4;
    $defaultThreads= 2;

    $mem = isset($_ENV['ARGON_MEMORY_KIB']) ? (int)$_ENV['ARGON_MEMORY_KIB'] : $defaultMemory;
    $time = isset($_ENV['ARGON_TIME_COST']) ? (int)$_ENV['ARGON_TIME_COST'] : $defaultTime;
    $threads = isset($_ENV['ARGON_THREADS']) ? (int)$_ENV['ARGON_THREADS'] : $defaultThreads;

    $maxMemory = 1 << 20; // 1 GiB
    $maxTime = 10;
    $maxThreads = 8;

    $mem = max(1 << 12, min($mem, $maxMemory));
    $time = max(1, min($time, $maxTime));
    $threads = max(1, min($threads, $maxThreads));

    return [
        'memory_cost' => $mem,
        'time_cost'   => $time,
        'threads'     => $threads,
    ];
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $emailRaw   = (string)($_POST['email'] ?? '');
    $email      = strtolower(trim($emailRaw));
    $password   = (string)($_POST['password'] ?? '');
    $givenName  = trim((string)($_POST['given_name'] ?? ''));
    $familyName = trim((string)($_POST['family_name'] ?? ''));
    $csrfToken = $_POST['csrf'] ?? null;
    if (!CSRF::validate($csrfToken)) {
        http_response_code(400);
        echo Templates::render('pages/register.php', [
            'error' => 'Neplatný CSRF token.',
            'pref_given'  => $givenName,
            'pref_family' => $familyName,
            'pref_email'  => $emailRaw
    ]);
    exit;
    }
    $clientIp = Logger::getClientIp();
    // LOGIN LIMITER BLOCK CHECK
    if (LoginLimiter::isRegisterBlocked($clientIp)) {
        $seconds = LoginLimiter::getRegisterSecondsUntilUnblock($clientIp);
        echo Templates::render('pages/register.php', [
            'error' => "Príliš veľa neúspešných pokusov registrácie. Skúste o $seconds sekúnd.",
            'pref_given'  => $givenName,
            'pref_family' => $familyName,
            'pref_email'  => $emailRaw
        ]);
        exit;
    }

    // FAILURE LOG + VALIDACE
    if ($email === '' || !Validator::validateEmail($email)) {
        LoginLimiter::registerRegisterAttempt(false, null, $_SERVER['HTTP_USER_AGENT'] ?? null, ['reason' => 'invalid_email']);
        echo Templates::render('pages/register.php', [
            'error' => 'Neplatný e-mail.',
            'pref_given'  => $givenName,
            'pref_family' => $familyName,
            'pref_email'  => $emailRaw
        ]);
        exit;
    }

    if (!Validator::validatePasswordStrength($password, 12)) {
        LoginLimiter::registerRegisterAttempt(false, null, $_SERVER['HTTP_USER_AGENT'] ?? null, ['reason' => 'weak_password']);
        echo Templates::render('pages/register.php', [
            'error' => 'Heslo nie je dosť silné (minimálne 12 znakov, veľké/malé písmená, číslo, špeciálny znak).',
            'pref_given'  => $givenName,
            'pref_family' => $familyName,
            'pref_email'  => $emailRaw
        ]);
        exit;
    }

    // sanitizace jmen
    $givenName  = Validator::sanitizeString($givenName, 100);
    $familyName = Validator::sanitizeString($familyName, 150);

    // jestliže po sanitaci nic nezbylo, nastav null
    if ($givenName === '') $givenName = null;
    if ($familyName === '') $familyName = null;

    // získať PDO
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
    $emailHmacCandidates = KeyManager::deriveHmacCandidates('EMAIL_HASH_KEY', KEYS_DIR, 'email_hash_key', $email);
    // ---------- skontrolovať, či už e-mail nie je registrovaný ----------
    try {
        if (!empty($emailHmacCandidates) && is_array($emailHmacCandidates)) {
            $q = $pdo->prepare('SELECT id FROM pouzivatelia WHERE email_hash = :h LIMIT 1');
            foreach ($emailHmacCandidates as $cand) {
                if (!isset($cand['hash'])) continue;
                $q->bindValue(':h', $cand['hash'], \PDO::PARAM_LOB);
                $q->execute();
            if ($q->fetch(\PDO::FETCH_ASSOC)) {
                LoginLimiter::registerRegisterAttempt(false, null, $_SERVER['HTTP_USER_AGENT'] ?? null, ['reason' => 'email_exists']);
                echo Templates::render('pages/register.php', [
                    'error' => 'Účet s týmto e-mailom už existuje.',
                    'pref_given'  => $givenName,
                    'pref_family' => $familyName,
                    'pref_email'  => $emailRaw
                ]);
                exit;
            }
            }
        }
    } catch (\Throwable $e) {
        try { Logger::systemError($e); } catch (\Throwable $_) {}
        http_response_code(500);
        echo Templates::render('pages/error.php', ['message' => 'Interná chyba pri overovaní e-mailu.']);
        exit;
    }

    // ---------- získanie pepperu ----------
    $pepRaw = null;
    $pepVer = null;
    try {
        $pinfo = KeyManager::getPasswordPepperInfo(KEYS_DIR);
        if (empty($pinfo['raw']) || !is_string($pinfo['raw']) || strlen($pinfo['raw']) !== 32) {
            throw new \RuntimeException('PASSWORD_PEPPER nie je platný (očakávaných 32 bajtov).');
        }
        $pepRaw = $pinfo['raw'];
        $pepVer = $pinfo['version'] ?? null;
    } catch (\Throwable $e) {
        try { Logger::systemError($e); } catch (\Throwable $_) {}
        http_response_code(500);
        echo Templates::render('pages/error.php', ['message' => 'Interná chyba: chýba PASSWORD_PEPPER.']);
        exit;
    }

    // ---------- preproces hesla ----------
    try {
        $pwPre = hash_hmac('sha256', $password, $pepRaw, true);
        $opts = $argonOptions();
        $hash = password_hash($pwPre, PASSWORD_ARGON2ID, $opts);
        if ($hash === false) throw new \RuntimeException('password_hash failed');
        $algo = password_get_info($hash)['algoName'] ?? null;
    } catch (\Throwable $e) {
        try { Logger::systemError($e); } catch (\Throwable $_) {}
        http_response_code(500);
        echo Templates::render('pages/error.php', ['message' => 'Interná chyba pri spracovaní hesla.']);
        exit;
    } finally {
        unset($pwPre);
    }

    // ---------- email HMAC + voliteľné šifrovanie ----------
    $emailHashBin = null;
    $emailHashVer = null;
    $emailEnc = null;
    $emailEncKeyVer = null;
    try {
        $hinfo = KeyManager::deriveHmacWithLatest('EMAIL_HASH_KEY', KEYS_DIR, 'email_hash_key', $email);
        $emailHashBin = $hinfo['hash'] ?? null;
        $emailHashVer = $hinfo['version'] ?? null;
    } catch (\Throwable $e) {
        try { Logger::error('Derive email hash failed on register', null, ['exception' => (string)$e]); } catch (\Throwable $_) {}
    }

    try {
        if (class_exists('Crypto') && method_exists('Crypto', 'initFromKeyManager')) {
            Crypto::initFromKeyManager(KEYS_DIR);
            $emailEnc = Crypto::encrypt($email, 'binary');
            if (method_exists('KeyManager', 'locateLatestKeyFile')) {
                $info = KeyManager::locateLatestKeyFile(KEYS_DIR, 'email_key');
                $emailEncKeyVer = $info['version'] ?? null;
            }
            Crypto::clearKey();
        }
    } catch (\Throwable $e) {
        try { Logger::error('Email encryption failed on register', null, ['exception' => (string)$e]); } catch (\Throwable $_) {}
    }

    $existingNewsletter = false;
    try {
        if (!empty($emailHmacCandidates) && is_array($emailHmacCandidates)) {
            $q = $pdo->prepare('SELECT user_id FROM newsletter_subscribers WHERE email_hash = :h LIMIT 1');
            foreach ($emailHmacCandidates as $c) {
                if (!isset($c['hash'])) continue;
                $q->bindValue(':h', $c['hash'], \PDO::PARAM_LOB);
                $q->execute();
                $found = $q->fetch(\PDO::FETCH_ASSOC);
                if ($found) {
                    $existingNewsletter = true;
                    break;
                }
            }
        }
    } catch (\Throwable $e) {
        try { Logger::error('Failed to check newsletter subscription before register', null, ['exception' => (string)$e]); } catch (\Throwable $_) {}
    }

    // ---------- uloženie do DB v transakcii ----------
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("INSERT INTO pouzivatelia
            (email_enc, email_key_version, email_hash, email_hash_key_version, heslo_hash, heslo_algo, heslo_key_version, is_active, is_locked, failed_logins, must_change_password, created_at, updated_at, actor_type)
            VALUES (:email_enc, :email_key_version, :email_hash, :email_hash_key_version, :heslo_hash, :heslo_algo, :heslo_key_version, 0, 0, 0, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP(), 'zakaznik')");

        $stmt->bindValue(':email_enc', $emailEnc ?? null, $emailEnc !== null ? \PDO::PARAM_LOB : \PDO::PARAM_NULL);
        $stmt->bindValue(':email_key_version', $emailEncKeyVer, $emailEncKeyVer !== null ? \PDO::PARAM_STR : \PDO::PARAM_NULL);
        $stmt->bindValue(':email_hash', $emailHashBin ?? null, $emailHashBin !== null ? \PDO::PARAM_LOB : \PDO::PARAM_NULL);
        $stmt->bindValue(':email_hash_key_version', $emailHashVer, $emailHashVer !== null ? \PDO::PARAM_STR : \PDO::PARAM_NULL);
        $stmt->bindValue(':heslo_hash', $hash, \PDO::PARAM_STR);
        $stmt->bindValue(':heslo_algo', $algo, $algo !== null ? \PDO::PARAM_STR : \PDO::PARAM_NULL);
        $stmt->bindValue(':heslo_key_version', $pepVer, $pepVer !== null ? \PDO::PARAM_STR : \PDO::PARAM_NULL);

        $stmt->execute();
        $userId = (int)$pdo->lastInsertId();

        // --------- create encrypted profile JSON and store in user_profiles ---------
        try {
            // připravíme JSON profilu (meta + data)
            $profileArr = [
                'meta' => [
                    'format_ver' => 1,
                    'created_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM),
                ],
                'data' => [
                    'given_name'  => $givenName ?? null,
                    'family_name' => $familyName ?? null,
                ],
            ];

            // odstraníme prázdné položky
            foreach ($profileArr['data'] as $k => $v) {
                if ($v === null || $v === '') unset($profileArr['data'][$k]);
            }

            $json = json_encode($profileArr, JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                throw new \RuntimeException('Failed to json_encode profile');
            }

            // vyžadujeme libsodium
            KeyManager::requireSodium();

            // získejte raw profilový klíč a verzi (getProfileKeyInfo musí existovat v KeyManageru)
            if (method_exists('KeyManager', 'getProfileKeyInfo')) {
                $pinfo = KeyManager::getProfileKeyInfo(KEYS_DIR);
                $profileKeyRaw = $pinfo['raw'] ?? null;
                $profileKeyVer = $pinfo['version'] ?? null;
            } else {
                // fallback: použít defaultní crypto_key (méně preferováno)
                $info = KeyManager::locateLatestKeyFile(KEYS_DIR, 'crypto_key');
                if ($info === null) {
                    throw new \RuntimeException('Profile crypto key not found (getProfileKeyInfo absent and crypto_key missing)');
                }
                $profileKeyRaw = @file_get_contents($info['path']);
                $profileKeyVer = $info['version'] ?? null;
            }

            if (!is_string($profileKeyRaw) || strlen($profileKeyRaw) !== KeyManager::keyByteLen()) {
                throw new \RuntimeException('Invalid profile key material (wrong length)');
            }

            // AEAD XChaCha20-Poly1305: nonce + ciphertext
            $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
            $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($json, '', $nonce, $profileKeyRaw);
            $blob = $nonce . $ciphertext; // uložíme nonce prefixovaný

            // ulož do DB (profile_enc musí existovat ve struktuře DB)
            $stmt2 = $pdo->prepare("INSERT INTO user_profiles (user_id, profile_enc, key_version, updated_at) VALUES (:uid, :enc, :kver, UTC_TIMESTAMP(6))");
            $stmt2->bindValue(':uid', $userId, \PDO::PARAM_INT);
            $stmt2->bindValue(':enc', $blob, \PDO::PARAM_LOB);
            $stmt2->bindValue(':kver', $profileKeyVer, $profileKeyVer !== null ? \PDO::PARAM_STR : \PDO::PARAM_NULL);
            $stmt2->execute();

            // bezpečné vyčištění citlivých proměnných z paměti
            try { KeyManager::memzero($profileKeyRaw); } catch (\Throwable $_) {}
            try { KeyManager::memzero($json); } catch (\Throwable $_) {}

        } catch (\Throwable $e) {
            // rollback a log
            try { if ($pdo->inTransaction()) $pdo->rollBack(); } catch (\Throwable $_) {}
            try { Logger::systemError($e, $userId ?? null); } catch (\Throwable $_) {}
            http_response_code(500);
            echo Templates::render('pages/error.php', ['message' => 'Interná chyba pri spracovaní profilu.']);
            exit;
        }

        // --------- LINK newsletter_subscribers ak existovali ----------
    try {
        $subHashes = [];
        $seen = [];
        if (!empty($emailHmacCandidates) && is_array($emailHmacCandidates)) {
            foreach ($emailHmacCandidates as $c) {
                if (!isset($c['hash'])) continue;
                $hex = bin2hex($c['hash']);
                if (isset($seen[$hex])) continue;
                $seen[$hex] = true;
                $subHashes[] = $c['hash'];
            }
        }
        if (empty($subHashes)) $subHashes[] = hash('sha256', $email, true);

        $placeholders = [];
        $params = [':uid' => $userId];
        foreach ($subHashes as $i => $h) {
            $ph = ':hash' . $i;
            $placeholders[] = $ph;
            $params[$ph] = $h;
        }

        $updateSql = "UPDATE newsletter_subscribers
                    SET user_id = :uid, updated_at = UTC_TIMESTAMP(6)
                    WHERE email_hash IN (" . implode(',', $placeholders) . ") 
                        AND user_id IS NULL";

        $updStmt = $pdo->prepare($updateSql);
        // :uid musí být INT
        $updStmt->bindValue(':uid', $userId, \PDO::PARAM_INT);
        // ostatní placeholdery jsou binární hashe -> LOB
        foreach ($params as $k => $v) {
            if ($k === ':uid') continue;
            $updStmt->bindValue($k, $v, \PDO::PARAM_LOB);
        }
        $updStmt->execute();

        // bezpečné vymazání z paměti
        try { foreach ($subHashes as $h) KeyManager::memzero($h); } catch (\Throwable $_) {}
        } catch (\Throwable $se) {
            try { Logger::error('Failed to link newsletter_subscribers during register', $userId, ['exception' => (string)$se]); } catch (\Throwable $_) {}
        }

        // zjistit, jestli chce uživatel newsletter
        $wantsNewsletter = (int)($_POST['newsletter_subscribe'] ?? 0) === 1;

        // pokud chce newsletter a ještě není záznam v tabulce, vlož nový
        if ($wantsNewsletter && !$existingNewsletter) {
            try {
                // první dostupný email hash (binárně)
                $firstHash = $emailHmacCandidates[0]['hash'] ?? hash('sha256', $email, true);

                // unsubscribe token + hash (pokud KeyManager podporuje, použijeme ho, jinak HMAC s pepperem)
                $unsubscribeToken = random_bytes(32);
                $unsubscribeTokenHash = null;
                $unsubscribeTokenKeyVer = null;
                try {
                    if (method_exists('KeyManager', 'deriveHmacWithLatest')) {
                        $uinfo = KeyManager::deriveHmacWithLatest('UNSUBSCRIBE_KEY', KEYS_DIR, 'unsubscribe_key', $unsubscribeToken);
                        $unsubscribeTokenHash = $uinfo['hash'] ?? null;
                        $unsubscribeTokenKeyVer = $uinfo['version'] ?? null;
                    }
                } catch (\Throwable $_) {}
                if ($unsubscribeTokenHash === null) {
                    $unsubscribeTokenHash = hash_hmac('sha256', $unsubscribeToken, $pepRaw, true);
                    $unsubscribeTokenKeyVer = $pepVer;
                }

                // ip hash (pokud máme client ip)
                $ipHash = null;
                $ipHashKeyVer = null;
                try {
                    if (!empty($clientIp) && method_exists('KeyManager', 'deriveHmacWithLatest')) {
                        $ipinfo = KeyManager::deriveHmacWithLatest('IP_HASH_KEY', KEYS_DIR, 'ip_hash_key', $clientIp);
                        $ipHash = $ipinfo['hash'] ?? null;
                        $ipHashKeyVer = $ipinfo['version'] ?? null;
                    }
                } catch (\Throwable $_) {}
                if ($ipHash === null && !empty($clientIp)) {
                    $ipHash = hash_hmac('sha256', $clientIp, $pepRaw, true);
                    $ipHashKeyVer = $pepVer;
                }

                // meta JSON
                $metaArr = [
                    'origin' => 'registration',
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                ];
                $metaJson = json_encode($metaArr, JSON_UNESCAPED_UNICODE);
                if ($metaJson === false) $metaJson = null;

                $insStmt = $pdo->prepare(
                    "INSERT INTO newsletter_subscribers
                        (user_id, email_enc, email_key_version, email_hash, email_hash_key_version,
                        confirm_selector, confirm_validator_hash, confirm_key_version, confirm_expires, confirmed_at,
                        unsubscribe_token_hash, unsubscribe_token_key_version, origin, ip_hash, ip_hash_key_version, meta, created_at, updated_at)
                    VALUES
                        (:uid, :email_enc, :email_key_version, :email_hash, :email_hash_key_version,
                        NULL, NULL, NULL, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6),
                        :unsubscribe_token_hash, :unsubscribe_token_key_version, :origin, :ip_hash, :ip_hash_key_version, :meta, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))"
                );

                $insStmt->bindValue(':uid', $userId, \PDO::PARAM_INT);
                $insStmt->bindValue(':email_enc', $emailEnc ?? null, $emailEnc !== null ? \PDO::PARAM_LOB : \PDO::PARAM_NULL);
                $insStmt->bindValue(':email_key_version', $emailEncKeyVer ?? null, $emailEncKeyVer !== null ? \PDO::PARAM_STR : \PDO::PARAM_NULL);
                $insStmt->bindValue(':email_hash', $firstHash, \PDO::PARAM_LOB);
                $insStmt->bindValue(':email_hash_key_version', $emailHashVer ?? null, $emailHashVer !== null ? \PDO::PARAM_STR : \PDO::PARAM_NULL);

                $insStmt->bindValue(':unsubscribe_token_hash', $unsubscribeTokenHash ?? null, $unsubscribeTokenHash !== null ? \PDO::PARAM_LOB : \PDO::PARAM_NULL);
                $insStmt->bindValue(':unsubscribe_token_key_version', $unsubscribeTokenKeyVer ?? null, $unsubscribeTokenKeyVer !== null ? \PDO::PARAM_STR : \PDO::PARAM_NULL);

                $insStmt->bindValue(':origin', 'registration', \PDO::PARAM_STR);
                $insStmt->bindValue(':ip_hash', $ipHash ?? null, $ipHash !== null ? \PDO::PARAM_LOB : \PDO::PARAM_NULL);
                $insStmt->bindValue(':ip_hash_key_version', $ipHashKeyVer ?? null, $ipHashKeyVer !== null ? \PDO::PARAM_STR : \PDO::PARAM_NULL);
                $insStmt->bindValue(':meta', $metaJson ?? null, $metaJson !== null ? \PDO::PARAM_STR : \PDO::PARAM_NULL);

                $insStmt->execute();

                // bezpečné vyčištění citlivých proměnných
                try { KeyManager::memzero($unsubscribeToken); } catch (\Throwable $_) {}
                try { if (is_string($firstHash)) KeyManager::memzero($firstHash); } catch (\Throwable $_) {}
            } catch (\Throwable $ie) {
                try { Logger::error('Failed to insert new newsletter subscriber', $userId, ['exception' => (string)$ie]); } catch (\Throwable $_) {}
            }
        }

        // ---------- EMAIL VERIFICATION TOKEN ----------
        $selector = bin2hex(random_bytes(6));
        $validator = random_bytes(32);
        $validatorHex = bin2hex($validator);
        $tokenHashHex = hash('sha256', $validator);

        $validatorHashBin = null;
        $validatorKeyVer = null;
        try {
            if (method_exists('KeyManager', 'deriveHmacWithLatest')) {
                $vinfo = KeyManager::deriveHmacWithLatest('EMAIL_VERIFICATION_KEY', KEYS_DIR, 'email_verification_key', $validator);
                $validatorHashBin = $vinfo['hash'] ?? null;
                $validatorKeyVer = $vinfo['version'] ?? null;
            }

            if ($validatorHashBin === null && method_exists('KeyManager', 'getEmailVerificationKeyInfo')) {
                $ev = KeyManager::getEmailVerificationKeyInfo(KEYS_DIR);
                if (!empty($ev['raw']) && is_string($ev['raw']) && strlen($ev['raw']) === KeyManager::keyByteLen()) {
                    $validatorHashBin = hash_hmac('sha256', $validator, $ev['raw'], true);
                    $validatorKeyVer = $ev['version'] ?? null;
                    try { KeyManager::memzero($ev['raw']); } catch (\Throwable $_) {}
                }
            }

            if ($validatorHashBin === null) {
                $validatorHashBin = hash_hmac('sha256', $validator, $pepRaw, true);
                $validatorKeyVer = $pepVer;
            }
        } catch (\Throwable $e) {
            try { Logger::error('Validator HMAC derive failed', $userId ?? null, ['exception' => (string)$e]); } catch (\Throwable $_) {}
            $validatorHashBin = hash_hmac('sha256', $validator, $pepRaw, true);
            $validatorKeyVer = $pepVer;
        }

        $expiresAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d H:i:s');

        $ins = $pdo->prepare("INSERT INTO email_verifications
            (user_id, token_hash, selector, validator_hash, key_version, expires_at, created_at)
            VALUES (:uid, :token_hash, :selector, :validator_hash, :key_version, :expires_at, UTC_TIMESTAMP())");

        $ins->bindValue(':uid', $userId, \PDO::PARAM_INT);
        $ins->bindValue(':token_hash', $tokenHashHex, \PDO::PARAM_STR);
        $ins->bindValue(':selector', $selector, \PDO::PARAM_STR);
        $ins->bindValue(':validator_hash', $validatorHashBin, \PDO::PARAM_LOB);
        $ins->bindValue(':key_version', $validatorKeyVer, $validatorKeyVer !== null ? \PDO::PARAM_STR : \PDO::PARAM_NULL);
        $ins->bindValue(':expires_at', $expiresAt, \PDO::PARAM_STR);
        $ins->execute();

        $pdo->commit();

        // ---------- bezpečné vyčistenie ----------
        try { KeyManager::memzero($pepRaw); } catch (\Throwable $_) {}
        try { KeyManager::memzero($validator); } catch (\Throwable $_) {}
        unset($hash, $validator);

        // ---------- ENQUEUE verifikačného e-mailu (jediný výrazný log) ----------
        try {
            // audit registrace: zaznamenat, že uživatel byl vytvořen (register_events)
            try {
                // REGISTER SUCCESS
                LoginLimiter::registerRegisterAttempt(true, $userId, $_SERVER['HTTP_USER_AGENT'] ?? null, ['newsletter' => (int)($_POST['newsletter_subscribe'] ?? 0)]);
            } catch (\Throwable $_) {
                // logger nesmí padnout flow; ignore
            }

            if (!class_exists('Mailer') || !method_exists('Mailer', 'enqueue')) {
                // Mailer chybí — jedna varování (není kritické, registrace je hotova)
                try { Logger::warn('Mailer::enqueue not available', $userId); } catch (\Throwable $_) {}
                echo Templates::render('pages/register_success.php', ['email' => $email, 'warning' => 'Registrácia prebehla, ale overovací e-mail nebol naplánovaný (Mailer unavailable).']);
                exit;
            }

            // build verify url
            $base = rtrim((string)($_ENV['BASE_URL'] ?? ''), '/');
            $verifyUrl = $base . '/verify.php?selector=' . rawurlencode($selector) . '&validator=' . rawurlencode($validatorHex);

            $payloadArr = [
                'user_id' => $userId,
                'to' => $email,
                'subject' => 'Potvrdenie registrácie',
                'template' => 'register_verify',
                'vars' => [
                    'given_name' => $givenName ?? '',
                    'family_name' => $familyName ?? '',
                    'verify_url' => $verifyUrl,
                ],
                'meta' => [
                    'email_key_version' => $emailEncKeyVer ?? null,
                    'validator_key_version' => $validatorKeyVer ?? null,
                    'email_hash_key_version' => $emailHashVer ?? null,
                    'cipher_format' => 'aead_xchacha20poly1305_v1_binary'
                ],
            ];

            // pokus o enqueu — jediný velký log: notice pokud OK, error pokud selže
            try {
                $notifId = Mailer::enqueue($payloadArr);
                try {
                    Logger::systemMessage('notice', 'Verification email enqueued', $userId, ['notification_id' => $notifId]);
                } catch (\Throwable $_) {}
            } catch (\Throwable $e) {
                // když enqueue vyhodí chybu — logovat to jako error, ale registrace už proběhla
                try {
                    Logger::systemMessage('error', 'Mailer enqueue failed during register', $userId, ['exception' => (string)$e]);
                } catch (\Throwable $_) {}
                // Nevyhazujeme; uživateli ukážeme úspěch registrace s varováním
                echo Templates::render('pages/register_success.php', ['email' => $email, 'warning' => 'Registrácia prebehla, ale nepodarilo sa naplánovať overovací e-mail. Kontaktujte podporu.']);
                exit;
            }

            // vše OK
            echo Templates::render('pages/register_success.php', ['email' => $email]);
            unset($validatorHex, $verifyUrl);
            exit;

        } catch (\Throwable $e) {
            // fallback: neočekávaná chyba v enqueue-části (zachytit a vrátit uživateli info)
            try { Logger::systemError($e, $userId ?? null); } catch (\Throwable $_) {}
            echo Templates::render('pages/register_success.php', ['email' => $email, 'warning' => 'Registrácia prebehla, ale nastala neočakávaná chyba pri spracovaní. Kontaktujte podporu.']);
            exit;
        }

    } catch (\Throwable $e) {
        try { if ($pdo->inTransaction()) $pdo->rollBack(); } catch (\Throwable $_) {}
        try { Logger::systemError($e); } catch (\Throwable $_) {}
        http_response_code(500);
        echo Templates::render('pages/register.php', [
            'error' => 'Chyba pri registrácii (server). Skúste neskôr.',
            'pref_given'  => $givenName,
            'pref_family' => $familyName,
            'pref_email'  => $emailRaw
        ]);
    }
}

// GET -> formulár
echo Templates::render('pages/register.php', ['error' => null]);
<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';

/**
 * register.php (slovensky, production-ready, integrované s Mailer::enqueue)
 */

// overenie kritických závislostí (KeyManager & Logger sú požadované)
$required = ['KeyManager', 'Logger'];
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
    $emailRaw = (string)($_POST['email'] ?? '');
    $email = strtolower(trim($emailRaw));
    $password = (string)($_POST['password'] ?? '');
    $fullName = trim((string)($_POST['full_name'] ?? ''));

    // jednoduchá validácia
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo Templates::render('pages/register.php', ['error' => 'Neplatný e-mail.']);
        exit;
    }
    if (strlen($password) < 8) {
        echo Templates::render('pages/register.php', ['error' => 'Heslo musí mať aspoň 8 znakov.']);
        exit;
    }
    if (mb_strlen($fullName, 'UTF-8') > 255) {
        echo Templates::render('pages/register.php', ['error' => 'Meno je príliš dlhé.']);
        exit;
    }

    // získať PDO (Database::getInstance() môže vrátiť PDO alebo wrapper)
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

    // ---------- skontrolovať, či už e-mail nie je registrovaný (HMAC lookup s rotáciou) ----------
    try {
        if (!method_exists('KeyManager', 'deriveHmacCandidates') || !method_exists('KeyManager', 'deriveHmacWithLatest')) {
            throw new \RuntimeException('KeyManager nie je schopný odvodiť HMACy (deriveHmacCandidates/deriveHmacWithLatest missing).');
        }
        $keysDir = KEYS_DIR;
        $candidates = KeyManager::deriveHmacCandidates('EMAIL_HASH_KEY', $keysDir, 'email_hash_key', $email);
        if (!empty($candidates) && is_array($candidates)) {
            $q = $pdo->prepare('SELECT id FROM pouzivatelia WHERE email_hash = :h LIMIT 1');
            foreach ($candidates as $cand) {
                if (!isset($cand['hash'])) continue;
                $q->bindValue(':h', $cand['hash'], \PDO::PARAM_LOB);
                $q->execute();
                $found = $q->fetch(\PDO::FETCH_ASSOC);
                if ($found) {
                    echo Templates::render('pages/register.php', ['error' => 'Účet s týmto e-mailom už existuje.']);
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

    // ---------- získanie pepperu (povinné pre produkciu) ----------
    $pepRaw = null;
    $pepVer = null;
    try {
        if (!method_exists('KeyManager', 'getPasswordPepperInfo')) {
            throw new \RuntimeException('KeyManager::getPasswordPepperInfo chýba.');
        }
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

    // ---------- preproces hesla (HMAC-pepper) + Argon2 hash ----------
    try {
        $pwPre = hash_hmac('sha256', $password, $pepRaw, true); // raw binary
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
        try { unset($pwPre); } catch (\Throwable $_) {}
    }

    // ---------- email HMAC (latest) + voliteľne šifrovanie e-mailu ----------
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
        $emailHashBin = null;
        $emailHashVer = null;
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
        $emailEnc = null;
        $emailEncKeyVer = null;
    }

    // ---------- ukladanie do DB v transakcii ----------
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("INSERT INTO pouzivatelia
            (email_enc, email_key_version, email_hash, email_hash_key_version, heslo_hash, heslo_algo, heslo_key_version, is_active, is_locked, failed_logins, must_change_password, created_at, updated_at, actor_type)
            VALUES (:email_enc, :email_key_version, :email_hash, :email_hash_key_version, :heslo_hash, :heslo_algo, :heslo_key_version, 0, 0, 0, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP(), 'zakaznik')");

        // bind email_enc
        if ($emailEnc !== null) {
            $stmt->bindValue(':email_enc', $emailEnc, \PDO::PARAM_LOB);
        } else {
            $stmt->bindValue(':email_enc', null, \PDO::PARAM_NULL);
        }
        $stmt->bindValue(':email_key_version', $emailEncKeyVer, $emailEncKeyVer !== null ? \PDO::PARAM_STR : \PDO::PARAM_NULL);

        // bind email_hash (binary)
        if ($emailHashBin !== null) {
            $stmt->bindValue(':email_hash', $emailHashBin, \PDO::PARAM_LOB);
        } else {
            $stmt->bindValue(':email_hash', null, \PDO::PARAM_NULL);
        }
        $stmt->bindValue(':email_hash_key_version', $emailHashVer, $emailHashVer !== null ? \PDO::PARAM_STR : \PDO::PARAM_NULL);

        // bind password hash/meta
        $stmt->bindValue(':heslo_hash', $hash, \PDO::PARAM_STR);
        $stmt->bindValue(':heslo_algo', $algo, $algo !== null ? \PDO::PARAM_STR : \PDO::PARAM_NULL);
        $stmt->bindValue(':heslo_key_version', $pepVer, $pepVer !== null ? \PDO::PARAM_STR : \PDO::PARAM_NULL);

        $stmt->execute();
        $userId = (int)$pdo->lastInsertId();

        // user_profiles
        $stmt2 = $pdo->prepare("INSERT INTO user_profiles (user_id, full_name, updated_at) VALUES (:uid, :full_name, UTC_TIMESTAMP())");
        $stmt2->bindValue(':uid', $userId, \PDO::PARAM_INT);
        $stmt2->bindValue(':full_name', $fullName, \PDO::PARAM_STR);
        $stmt2->execute();

        // ---------- vytvorenie email verification tokenu ----------
        $selector = bin2hex(random_bytes(6)); // 12 hex chars
        $validator = random_bytes(32); // binary
        $validatorHex = bin2hex($validator); // pre URL posielanie

        // token_hash = sha256 hex (char(64))
        $tokenHashHex = hash('sha256', $validator); // hex string, 64 chars

        // validator_hash: pokúsime sa použiť KeyManager-based HMAC (ak existuje), inak fallback na HMAC s pepper
        $validatorHashBin = null;
        $validatorKeyVer = null;
        try {
            if (method_exists('KeyManager', 'deriveHmacWithLatest')) {
                $vinfo = KeyManager::deriveHmacWithLatest('EMAIL_VERIFICATION_KEY', KEYS_DIR, 'email_verification_key', $validator);
                if (!empty($vinfo['hash'])) {
                    $validatorHashBin = $vinfo['hash'];
                    $validatorKeyVer = $vinfo['version'] ?? null;
                } else {
                    $validatorHashBin = hash_hmac('sha256', $validator, $pepRaw, true);
                    $validatorKeyVer = $pepVer;
                }
            } else {
                $validatorHashBin = hash_hmac('sha256', $validator, $pepRaw, true);
                $validatorKeyVer = $pepVer;
            }
        } catch (\Throwable $e) {
            try { Logger::error('Validator HMAC derive failed', $userId ?? null, ['exception' => (string)$e]); } catch (\Throwable $_) {}
            $validatorHashBin = hash_hmac('sha256', $validator, $pepRaw, true);
            $validatorKeyVer = $pepVer;
        }

        // expires - 24 hodín od teraz (UTC)
        $expiresAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d H:i:s');

        $ins = $pdo->prepare("INSERT INTO email_verifications
            (user_id, token_hash, selector, validator_hash, key_version, key_id, expires_at, created_at)
            VALUES (:uid, :token_hash, :selector, :validator_hash, :key_version, NULL, :expires_at, UTC_TIMESTAMP())");

        $ins->bindValue(':uid', $userId, \PDO::PARAM_INT);
        $ins->bindValue(':token_hash', $tokenHashHex, \PDO::PARAM_STR);
        $ins->bindValue(':selector', $selector, \PDO::PARAM_STR);
        $ins->bindValue(':validator_hash', $validatorHashBin, \PDO::PARAM_LOB);
        $ins->bindValue(':key_version', $validatorKeyVer, $validatorKeyVer !== null ? \PDO::PARAM_STR : \PDO::PARAM_NULL);
        $ins->bindValue(':expires_at', $expiresAt, \PDO::PARAM_STR);
        $ins->execute();

        $pdo->commit();

        // vyčistiť citlivé premenné
        try {
            if (is_string($pepRaw) && function_exists('sodium_memzero')) {
                @sodium_memzero($pepRaw);
            } else {
                unset($pepRaw);
            }
            unset($hash, $validator);
        } catch (\Throwable $_) {}

        // ---------- ENQUEUE verifikačného e-mailu cez Mailer::enqueue (preferované) ----------
        try {
            if (class_exists('Mailer') && method_exists('Mailer', 'enqueue')) {
                $base = rtrim((string)($_ENV['BASE_URL'] ?? ''), '/');
                $verifyUrl = $base . '/verify.php?selector=' . rawurlencode($selector) . '&validator=' . rawurlencode($validatorHex);

                $payloadArr = [
                    'to' => $email,
                    'subject' => 'Potvrdenie registrácie',
                    // template name: prispôsobte podľa vašich EmailTemplates (tu "register_verify")
                    'template' => 'register_verify',
                    'vars' => [
                        'full_name' => $fullName,
                        'verify_url' => $verifyUrl,
                    ],
                ];

                // enqueue (Mailer zvaliduje payload a zabezpečí zašifrovanie + uloženie)
                $notifId = Mailer::enqueue($payloadArr);
                try { Logger::systemMessage('info', 'Verification email enqueued', $userId, ['notification_id' => $notifId]); } catch (\Throwable $_) {}
            } else {
                try { Logger::warn('Mailer::enqueue not available; verification email not scheduled', $userId); } catch (\Throwable $_) {}
                // informovať užívateľa, ale registrácia prebehla
                echo Templates::render('pages/register_success.php', ['email' => $email, 'warning' => 'Registrácia prebehla, ale overovací e-mail nebol naplánovaný (Mailer unavailable).']);
                exit;
            }
        } catch (\Throwable $e) {
            try { Logger::error('Mailer enqueue failed', $userId, ['exception' => (string)$e]); } catch (\Throwable $_) {}
            echo Templates::render('pages/register_success.php', ['email' => $email, 'warning' => 'Registrácia prebehla, ale nepodarilo sa naplánovať overovací e-mail. Kontaktujte podporu.']);
            exit;
        }

        // úspech
        echo Templates::render('pages/register_success.php', ['email' => $email]);
        exit;

    } catch (\Throwable $e) {
        try { if ($pdo->inTransaction()) $pdo->rollBack(); } catch (\Throwable $_) {}
        try { Logger::systemError($e); } catch (\Throwable $_) {}
        http_response_code(500);
        echo Templates::render('pages/register.php', ['error' => 'Chyba pri registrácii (server). Skúste neskôr.']);
        exit;
    }
}

// GET -> formulár
echo Templates::render('pages/register.php', ['error' => null]);
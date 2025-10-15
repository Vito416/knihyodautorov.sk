<?php
declare(strict_types=1);

/**
 * actions/register.php
 * Strict handler — používá výhradně předané shared proměnné.
 *
 * Required shared keys (frontcontroller MUST pass):
 *   - KeyManager, Logger, Validator, CSRF, db (PDO or wrapper), Mailer, MailHelper,
 *     LoginLimiter, Recaptcha, KEYS_DIR
 *
 * Response format: JSON only (respondJson)
 */

function respondJson(array $payload, int $status = 200): void {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    http_response_code($status);
    try { $payload['csrfToken'] = \BlackCat\Core\Security\CSRF::token();} catch (\Throwable $_) {}
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    // Ensure script stops after sending
    exit;
}

// --- require that the frontcontroller injected these variables (strict, no fallbacks) ---
$required = [
    'KeyManager','Logger','Validator','CSRF','db','Mailer','MailHelper','LoginLimiter','Recaptcha','KEYS_DIR'
];
$missing = [];
foreach ($required as $r) {
    if (!isset($$r)) $missing[] = $r;
}
if (!empty($missing)) {
    $msg = 'Interná konfigurácia chýba: ' . implode(', ', $missing) . '.';
    // Try to log via injected Logger if possible (best-effort)
    try {
        if (isset($Logger)) {
            if (is_string($Logger) && class_exists($Logger) && method_exists($Logger, 'systemError')) {
                forward_static_call_array([$Logger, 'systemError'], [new \RuntimeException($msg)]);
            } elseif (is_object($Logger) && method_exists($Logger, 'systemError')) {
                $Logger->systemError(new \RuntimeException($msg));
            }
        }
    } catch (\Throwable $_) {}
    respondJson(['success' => false, 'message' => $msg], 500);
}

// --- small helpers: call (class-string or object), resolveTarget, loggerInvoke, safe memzero ---
$call = function($target, string $method, array $args = []) {
    try {
        if (is_string($target) && class_exists($target) && method_exists($target, $method)) {
            return forward_static_call_array([$target, $method], $args);
        }
        if (is_object($target) && method_exists($target, $method)) {
            return call_user_func_array([$target, $method], $args);
        }
    } catch (\Throwable $_) {}
    return null;
};

$resolveTarget = function($injected, string $fallbackClass = null) {
    if (!empty($injected)) {
        if (is_string($injected) && class_exists($injected)) return $injected;
        if (is_object($injected)) return $injected;
    }
    return null;
};

$loggerInvoke = function(?string $method, string $msg, $userId = null, array $ctx = []) use (&$Logger, $call, $resolveTarget) {
    if (empty($Logger)) return;
    try {
        $target = $resolveTarget($Logger);
        if ($target === null) return;
        if ($method === 'systemMessage') {
            if (is_string($target) && method_exists($target, 'systemMessage')) {
                return $call($target, 'systemMessage', [$ctx['level'] ?? 'notice', $msg, $userId, $ctx]);
            }
            if (is_object($target) && method_exists($target, 'systemMessage')) {
                return $target->systemMessage($ctx['level'] ?? 'notice', $msg, $userId, $ctx);
            }
            return;
        }
        if (is_string($target) && method_exists($target, $method)) {
            return $call($target, $method, [$msg, $userId, $ctx]);
        }
        if (is_object($target) && method_exists($target, $method)) {
            return $target->{$method}($msg, $userId, $ctx);
        }
    } catch (\Throwable $_) {}
    return null;
};

$safeMemzero = function(&$buf) use (&$KeyManager, $call) : void {
    try {
        if ($buf === null) return;
        if (!empty($KeyManager)) {
            $km = $KeyManager;
            if (is_string($km) && class_exists($km) && method_exists($km, 'memzero')) {
                $call($km, 'memzero', [&$buf]);
                return;
            }
            if (is_object($km) && method_exists($km, 'memzero')) {
                $km->memzero($buf);
                return;
            }
        }
        if (function_exists('sodium_memzero')) {
            @sodium_memzero($buf);
            return;
        }
        if (is_string($buf)) {
            $buf = str_repeat("\0", strlen($buf));
        } elseif (is_array($buf)) {
            foreach ($buf as &$v) { $v = null; } unset($v);
        } else {
            $buf = null;
        }
    } catch (\Throwable $_) {}
};

// --- Argon2 options helper (unchanged) ---
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // Strict: require frontcontroller to inject plain token string $csrfToken
    if (!isset($csrfToken) || !is_string($csrfToken) || $csrfToken === '') {
        // konfigurace chybí -> vykreslíme chybovou stránku (server misconfig)
        $loggerInvoke('error', 'register: missing required csrfToken in trustedShared', null, []);
        return [
            'template' => 'pages/error.php',
            'vars' => ['message' => 'Interná chyba (CSRF token chýba).'],
            'status' => 500,
        ];
    }

    return [
        'template' => 'pages/register.php',
        'vars' => [
            'pageTitle' => 'Registrácia',
            'existingNewsletter' => $existingNewsletter ?? false,
            // strict token (plain string) passed to template
            'csrfToken' => $csrfToken,
        ],
    ];
}

// --- fetch POST params ---
$emailRaw   = (string)($_POST['email'] ?? '');
$email      = strtolower(trim($emailRaw));
$password   = (string)($_POST['password'] ?? '');
$givenName  = trim((string)($_POST['given_name'] ?? ''));
$familyName = trim((string)($_POST['family_name'] ?? ''));
$csrfToken  = $_POST['csrf'] ?? null;

// --- CSRF validation using injected CSRF ---
$csrfValid = $call($CSRF, 'validate', [$csrfToken]);
if ($csrfValid !== true) {
    respondJson([
        'success' => false,
        'errors'  => ['csrf' => 'Neplatný CSRF token.'],
        'pref'    => [
            'given_name'  => $givenName,
            'family_name' => $familyName,
            'email'       => $emailRaw
        ]
    ], 400);
}
// --- client ip via Logger (best-effort) ---
$clientIp = null;
try {
    $tmp = $call($Logger, 'getClientIp', []);
    if (!empty($tmp)) $clientIp = $tmp;
} catch (\Throwable $_) { $clientIp = null; }

// --- registration rate limiter ---
if ($call($LoginLimiter, 'isRegisterBlocked', [$clientIp]) === true) {
    $seconds = $call($LoginLimiter, 'getRegisterSecondsUntilUnblock', [$clientIp]);
    respondJson([
        'success' => false,
        'errors'  => ['blocked' => "Príliš veľa neúspešných pokusov registrácie. Skúste o {$seconds} sekúnd."],
        'pref'    => [
            'given_name'  => $givenName,
            'family_name' => $familyName,
            'email'       => $emailRaw
        ]
    ], 429);
}

// --- basic validation using injected Validator ---
if ($email === '' || $call($Validator, 'email', [$email]) !== true) {
    $call($LoginLimiter, 'registerRegisterAttempt', [false, null, $_SERVER['HTTP_USER_AGENT'] ?? null, ['reason'=>'invalid_email']]);
    respondJson([
        'success' => false,
        'errors'  => ['email' => 'Neplatný e-mail.'],
        'pref'    => [
            'given_name'  => $givenName,
            'family_name' => $familyName,
            'email'       => $emailRaw
        ]
    ], 400);
}

if ($call($Validator, 'passwordStrong', [$password, 12]) !== true) {
    $call($LoginLimiter, 'registerRegisterAttempt', [false, null, $_SERVER['HTTP_USER_AGENT'] ?? null, ['reason'=>'weak_password']]);
    respondJson([
        'success' => false,
        'errors'  => ['password' => 'Heslo nie je dosť silné (minimálne 12 znakov, veľké/malé písmená, číslo, špeciálny znak).'],
        'pref'    => [
            'given_name'  => $givenName,
            'family_name' => $familyName,
            'email'       => $emailRaw
        ]
    ], 400);
}

// sanitize names
$givenName  = $call($Validator, 'stringSanitized', [$givenName, 100]) ?? null;
$familyName = $call($Validator, 'stringSanitized', [$familyName, 150]) ?? null;
if ($givenName === '') $givenName = null;
if ($familyName === '') $familyName = null;

// --- obtain PDO from injected db/shared (strict) ---
$pdo = null;
if (isset($db)) {
    if ($db instanceof \PDO) {
        $pdo = $db;
    } elseif (is_object($db) && method_exists($db, 'getPdo')) {
        $maybe = $db->getPdo();
        if ($maybe instanceof \PDO) $pdo = $maybe;
    }
}
if (isset($database) && $pdo === null) {
    if ($database instanceof \PDO) {
        $pdo = $database;
    } elseif (is_object($database) && method_exists($database, 'getPdo')) {
        $maybe = $database->getPdo();
        if ($maybe instanceof \PDO) $pdo = $maybe;
    }
}
if (!($pdo instanceof \PDO)) {
    $loggerInvoke('error', 'register: PDO not available', null, []);
    respondJson(['success' => false, 'message' => 'Interná chyba (DB).'], 500);
}

// --- derive email-hmac candidates via injected KeyManager ---
$emailHmacCandidates = $call($KeyManager, 'deriveHmacCandidates', ['EMAIL_HASH_KEY', $KEYS_DIR, 'email_hash_key', $email]);

// check if email already exists
try {
    if (!empty($emailHmacCandidates) && is_array($emailHmacCandidates)) {
        $q = $pdo->prepare('SELECT id FROM pouzivatelia WHERE email_hash = :h LIMIT 1');
        foreach ($emailHmacCandidates as $cand) {
            if (!isset($cand['hash'])) continue;
            $q->bindValue(':h', $cand['hash'], \PDO::PARAM_LOB);
            $q->execute();
            if ($q->fetch(\PDO::FETCH_ASSOC)) {
                $call($LoginLimiter, 'registerRegisterAttempt', [false, null, $_SERVER['HTTP_USER_AGENT'] ?? null, ['reason'=>'email_exists']]);
                respondJson([
                    'success' => false,
                    'errors'  => ['email' => 'Účet s týmto e-mailom už existuje.'],
                    'pref'    => [
                        'given_name'  => $givenName,
                        'family_name' => $familyName,
                        'email'       => $emailRaw
                    ]
                ], 409);
            }
        }
    }
} catch (\Throwable $e) {
    $loggerInvoke('error', 'register: checking existing email failed', null, ['ex' => (string)$e]);
    respondJson(['success' => false, 'message' => 'Interná chyba pri overovaní e-mailu.'], 500);
}

// --- get password pepper (KeyManager) ---
$pepRaw = null;
$pepVer = null;
try {
    $pinfo = $call($KeyManager, 'getPasswordPepperInfo', [$KEYS_DIR]);
    if (empty($pinfo['raw']) || !is_string($pinfo['raw']) || strlen($pinfo['raw']) !== 32) {
        throw new \RuntimeException('PASSWORD_PEPPER invalid');
    }
    $pepRaw = $pinfo['raw'];
    $pepVer = $pinfo['version'] ?? null;
} catch (\Throwable $e) {
    $loggerInvoke('error', 'register: pepper missing', null, ['ex' => (string)$e]);
    respondJson(['success' => false, 'message' => 'Interná chyba: chýba PASSWORD_PEPPER.'], 500);
}

// --- preprocess password (pepper + argon2id) ---
try {
    $pwPre = hash_hmac('sha256', $password, $pepRaw, true);
    $opts = $argonOptions();
    $hash = password_hash($pwPre, PASSWORD_ARGON2ID, $opts);
    if ($hash === false) throw new \RuntimeException('password_hash failed');
    $algo = password_get_info($hash)['algoName'] ?? null;
} catch (\Throwable $e) {
    $loggerInvoke('error', 'register: password processing failed', null, ['ex' => (string)$e]);
    respondJson(['success' => false, 'message' => 'Interná chyba pri spracovaní hesla.'], 500);
} finally {
    if (isset($pwPre)) { $safeMemzero($pwPre); unset($pwPre); }
}

// --- derive email hash and optionally encrypt via injected Crypto ---
$emailHashBin = null;
$emailHashVer = null;
$emailEnc = null;
$emailEncKeyVer = null;
try {
    $hinfo = $call($KeyManager, 'deriveHmacWithLatest', ['EMAIL_HASH_KEY', $KEYS_DIR, 'email_hash_key', $email]);
    if (is_array($hinfo)) {
        $emailHashBin = $hinfo['hash'] ?? null;
        $emailHashVer = $hinfo['version'] ?? null;
    }
} catch (\Throwable $e) {
    $loggerInvoke('error', 'register: derive email hash failed', null, ['ex' => (string)$e]);
}

try {
    if (!empty($Crypto) && ($call($Crypto, 'initFromKeyManager', [$KEYS_DIR]) !== false)) {
        $emailEnc = $call($Crypto, 'encrypt', [$email, 'binary']);
        // try to get key version if available
        $info = $call($KeyManager, 'locateLatestKeyFile', [$KEYS_DIR, 'email_key']);
        if (is_array($info)) $emailEncKeyVer = $info['version'] ?? null;
        $call($Crypto, 'clearKey', []);
    }
} catch (\Throwable $e) {
    $loggerInvoke('error', 'register: email encryption failed', null, ['ex' => (string)$e]);
}

// --- check existing newsletter subscription via email hashes ---
$existingNewsletter = false;
try {
    if (!empty($emailHmacCandidates) && is_array($emailHmacCandidates)) {
        $q = $pdo->prepare('SELECT user_id FROM newsletter_subscribers WHERE email_hash = :h LIMIT 1');
        foreach ($emailHmacCandidates as $c) {
            if (!isset($c['hash'])) continue;
            $q->bindValue(':h', $c['hash'], \PDO::PARAM_LOB);
            $q->execute();
            $found = $q->fetch(\PDO::FETCH_ASSOC);
            if ($found) { $existingNewsletter = true; break; }
        }
    }
} catch (\Throwable $e) {
    $loggerInvoke('error', 'register: newsletter check failed', null, ['ex' => (string)$e]);
}

// --- store user in DB transactionally ---
try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("INSERT INTO pouzivatelia
        (email_enc, email_key_version, email_hash, email_hash_key_version, heslo_hash, heslo_algo, heslo_key_version, is_active, is_locked, failed_logins, must_change_password, created_at, updated_at, actor_type)
        VALUES (:email_enc, :email_key_version, :email_hash, :email_hash_key_version, :heslo_hash, :heslo_algo, :heslo_key_version, 0, 0, 0, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP(), 'zakaznik')");

    $stmt->bindValue(':email_enc', $emailEnc ?? null, $emailEnc !== null ? \PDO::PARAM_LOB : \PDO::PARAM_NULL);
    $stmt->bindValue(':email_key_version', $emailEncKeyVer ?? null, $emailEncKeyVer !== null ? \PDO::PARAM_STR : \PDO::PARAM_NULL);
    $stmt->bindValue(':email_hash', $emailHashBin ?? null, $emailHashBin !== null ? \PDO::PARAM_LOB : \PDO::PARAM_NULL);
    $stmt->bindValue(':email_hash_key_version', $emailHashVer ?? null, $emailHashVer !== null ? \PDO::PARAM_STR : \PDO::PARAM_NULL);
    $stmt->bindValue(':heslo_hash', $hash, \PDO::PARAM_STR);
    $stmt->bindValue(':heslo_algo', $algo, $algo !== null ? \PDO::PARAM_STR : \PDO::PARAM_NULL);
    $stmt->bindValue(':heslo_key_version', $pepVer, $pepVer !== null ? \PDO::PARAM_STR : \PDO::PARAM_NULL);

    $stmt->execute();
    $userId = (int)$pdo->lastInsertId();

    // --- profile JSON encryption + store (uses KeyManager for profile key) ---
    try {
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
        foreach ($profileArr['data'] as $k => $v) {
            if ($v === null || $v === '') unset($profileArr['data'][$k]);
        }
        $json = json_encode($profileArr, JSON_UNESCAPED_UNICODE);
        if ($json === false) throw new \RuntimeException('json encode profile failed');

        $call($KeyManager, 'requireSodium', []);
        $pinfo = $call($KeyManager, 'getProfileKeyInfo', [$KEYS_DIR]);
        if (empty($pinfo['raw']) || !is_string($pinfo['raw']) || strlen($pinfo['raw']) !== $call($KeyManager, 'keyByteLen', [])) {
            throw new \RuntimeException('Invalid profile key material');
        }
        $profileKeyRaw = $pinfo['raw'] ?? null;
        $profileKeyVer = $pinfo['version'] ?? null;

        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($json, '', $nonce, $profileKeyRaw);
        $blob = $nonce . $ciphertext;

        $stmt2 = $pdo->prepare("INSERT INTO user_profiles (user_id, profile_enc, key_version, updated_at) VALUES (:uid, :enc, :kver, UTC_TIMESTAMP(6))");
        $stmt2->bindValue(':uid', $userId, \PDO::PARAM_INT);
        $stmt2->bindValue(':enc', $blob, \PDO::PARAM_LOB);
        $stmt2->bindValue(':kver', $profileKeyVer ?? null, $profileKeyVer !== null ? \PDO::PARAM_STR : \PDO::PARAM_NULL);
        $stmt2->execute();

        // memzero
        $safeMemzero($profileKeyRaw);
        $safeMemzero($json);
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $loggerInvoke('error', 'register: profile store failed', $userId ?? null, ['ex' => (string)$e]);
        respondJson(['success' => false, 'message' => 'Interná chyba pri spracovaní profilu.'], 500);
    }

    // --- link existing newsletter_subscribers by email_hashes ---
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
        $updStmt->bindValue(':uid', $userId, \PDO::PARAM_INT);
        foreach ($params as $k => $v) {
            if ($k === ':uid') continue;
            $updStmt->bindValue($k, $v, \PDO::PARAM_LOB);
        }
        $updStmt->execute();
        foreach ($subHashes as $h) { $safeMemzero($h); }
    } catch (\Throwable $se) {
        $loggerInvoke('error', 'Failed to link newsletter_subscribers during register', $userId ?? null, ['ex' => (string)$se]);
    }

    // --- optionally insert newsletter_subscriber when user asked ---
    $wantsNewsletter = (int)($_POST['newsletter_subscribe'] ?? 0) === 1;
    if ($wantsNewsletter && !$existingNewsletter) {
        try {
            $firstHash = $emailHmacCandidates[0]['hash'] ?? hash('sha256', $email, true);

            $unsubscribeToken = random_bytes(32);
            $unsubscribeTokenHash = null;
            $unsubscribeTokenKeyVer = null;
            $uinfo = $call($KeyManager, 'deriveHmacWithLatest', ['UNSUBSCRIBE_KEY', $KEYS_DIR, 'unsubscribe_key', $unsubscribeToken]);
            if (is_array($uinfo) && isset($uinfo['hash'])) {
                $unsubscribeTokenHash = $uinfo['hash'];
                $unsubscribeTokenKeyVer = $uinfo['version'] ?? null;
            } else {
                $unsubscribeTokenHash = hash_hmac('sha256', $unsubscribeToken, $pepRaw, true);
                $unsubscribeTokenKeyVer = $pepVer;
            }

            $ipHash = null; $ipHashKeyVer = null;
            $ipinfo = null;
            if (!empty($clientIp)) {
                $ipinfo = $call($KeyManager, 'deriveHmacWithLatest', ['IP_HASH_KEY', $KEYS_DIR, 'ip_hash_key', $clientIp]);
                if (is_array($ipinfo) && isset($ipinfo['hash'])) {
                    $ipHash = $ipinfo['hash'];
                    $ipHashKeyVer = $ipinfo['version'] ?? null;
                } else {
                    $ipHash = hash_hmac('sha256', $clientIp, $pepRaw, true);
                    $ipHashKeyVer = $pepVer;
                }
            }

            $metaArr = ['origin' => 'registration','user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null];
            $metaJson = json_encode($metaArr, JSON_UNESCAPED_UNICODE) ?: null;

            $insStmt = $pdo->prepare(
                "INSERT INTO newsletter_subscribers
                    (user_id, email_enc, email_key_version, email_hash, email_hash_key_version,
                    confirm_selector, confirm_validator_hash, confirm_key_version, confirm_expires, confirmed_at,
                    unsubscribe_token_hash, unsubscribe_token_key_version, unsubscribed_at, origin, ip_hash, ip_hash_key_version, meta, created_at, updated_at)
                VALUES
                    (:uid, :email_enc, :email_key_version, :email_hash, :email_hash_key_version,
                    NULL, NULL, NULL, NULL, NULL,
                    :unsubscribe_token_hash, :unsubscribe_token_key_version, NULL, :origin, :ip_hash, :ip_hash_key_version, :meta, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))"
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

            $safeMemzero($unsubscribeToken);
            if (is_string($firstHash)) $safeMemzero($firstHash);
        } catch (\Throwable $ie) {
            $loggerInvoke('error', 'Failed to insert new newsletter subscriber', $userId, ['ex' => (string)$ie]);
        }
    }

    // --- EMAIL VERIFICATION TOKEN generation + store ---
    $selector = bin2hex(random_bytes(6));
    $validator = random_bytes(32);
    $validatorHex = bin2hex($validator);
    $tokenHashHex = hash('sha256', $validator);

    $validatorHashBin = null;
    $validatorKeyVer = null;
    try {
        $vinfo = $call($KeyManager, 'deriveHmacWithLatest', ['EMAIL_VERIFICATION_KEY', $KEYS_DIR, 'email_verification_key', $validator]);
        if (is_array($vinfo) && isset($vinfo['hash'])) {
            $validatorHashBin = $vinfo['hash'];
            $validatorKeyVer = $vinfo['version'] ?? null;
        } else {
            $ev = $call($KeyManager, 'getEmailVerificationKeyInfo', [$KEYS_DIR]);
            if (!empty($ev['raw']) && is_string($ev['raw'])) {
                $validatorHashBin = hash_hmac('sha256', $validator, $ev['raw'], true);
                $validatorKeyVer = $ev['version'] ?? null;
                $safeMemzero($ev['raw']);
            }
        }
        if ($validatorHashBin === null) {
            $validatorHashBin = hash_hmac('sha256', $validator, $pepRaw, true);
            $validatorKeyVer = $pepVer;
        }
    } catch (\Throwable $e) {
        $loggerInvoke('error', 'Validator HMAC derive failed', $userId ?? null, ['ex' => (string)$e]);
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

    // secure cleanup
    $safeMemzero($pepRaw);
    $safeMemzero($validator);
    unset($hash, $validator);

    // --- enqueue verification email via injected Mailer (best-effort) ---
    try {
        $call($LoginLimiter, 'registerRegisterAttempt', [true, $userId, $_SERVER['HTTP_USER_AGENT'] ?? null, ['newsletter' => (int)($_POST['newsletter_subscribe'] ?? 0)]]);

        // build verify url using config.APP_URL if present
        $base = rtrim((string)($_ENV['APP_URL'] ?? ''), '/');
        $verifyUrl = $base . '/verify?selector=' . rawurlencode($selector) . '&validator=' . rawurlencode($validatorHex);

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
            'attachments' => [
                [
                    'type' => 'inline_remote',
                    'src'  => 'https://knihyodautorov.sk/assets/logo.png',
                    'name' => 'logo.png',
                    'cid'  => 'logo'
                ]
            ],
            'meta' => [
                'email_key_version' => $emailEncKeyVer ?? null,
                'validator_key_version' => $validatorKeyVer ?? null,
                'email_hash_key_version' => $emailHashVer ?? null,
                'cipher_format' => 'aead_xchacha20poly1305_v1_binary'
            ],
        ];

        $notifId = $call($Mailer, 'enqueue', [$payloadArr]);
        if ($notifId === null) {
            $loggerInvoke('warn', 'Mailer::enqueue returned null', $userId, []);
            respondJson(['success' => true, 'email' => $email, 'warning' => 'Registrácia prebehla, ale overovací e-mail nebol naplánovaný (Mailer unavailable).'], 200);
        }

        $loggerInvoke('systemMessage', 'Verification email enqueued', $userId, ['notification_id' => $notifId, 'level' => 'notice']);
        $_SESSION['register_success'] = true;
        respondJson(['success' => true, 'email' => $email], 200);
    } catch (\Throwable $e) {
        $loggerInvoke('error', 'Mailer enqueue failed during register', $userId ?? null, ['ex' => (string)$e]);
        respondJson(['success' => true, 'email' => $email, 'warning' => 'Registrácia prebehla, ale nepodarilo sa naplánovať overovací e-mail. Kontaktujte podporu.'], 200);
    }

} catch (\Throwable $e) {
    try { if ($pdo && $pdo->inTransaction()) $pdo->rollBack(); } catch (\Throwable $_) {}
    $loggerInvoke('error', 'register exception', null, ['ex' => (string)$e]);
    respondJson([
        'success' => false,
        'message' => 'Chyba pri registrácii (server). Skúste neskôr.',
        'pref'    => [
            'given_name'  => $givenName,
            'family_name' => $familyName,
            'email'       => $emailRaw
        ]
    ], 500);
}

// If GET or other, return minimal form metadata (shouldn't happen because route methods restrict to POST)
respondJson([
    'success' => true,
    'form' => ['error' => null]
], 200);
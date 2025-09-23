<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';

/**
 * profile.php (opravené)
 *
 * - vyžaduje prihlásenie cez SessionManager
 * - načíta údaje z pouzivatelia + user_profiles
 * - umožňuje: zmeniť meno (user_profiles.full_name), zmeniť e-mail (vytvorí verification token a uloží email_enc/email_hash),
 *   zmeniť heslo (používa Auth::hashPassword ak dostupné)
 *
 * Poznámky:
 *  - Neukladáme plain email do DB.
 *  - E-mail verification je enqueue do Mailer (Mailer::enqueue).
 */

try {
    $db = Database::getInstance()->getPdo();
} catch (\Throwable $e) {
        if (class_exists('Logger')) { try { Logger::systemError($e); } catch (\Throwable $_) {} }
        http_response_code(500);
        echo 'Interná chyba (DB)';
        exit;
}

// valid session
try {
    $userId = SessionManager::validateSession($db);
} catch (\Throwable $e) {
    if (class_exists('Logger')) { try { Logger::systemError($e); } catch (\Throwable $_) {} }
    header('Location: login.php');
    exit;
}

if ($userId === null) {
    header('Location: login.php');
    exit;
}

// helper: prepare & execute (works s PDO aj s Database wrapper)
$prepareAndExecute = function(string $sql, array $params = []) use ($db) {
    if ($db instanceof \PDO) {
        $stmt = $db->prepare($sql);
        if ($stmt === false) throw new \RuntimeException('PDO prepare failed');
        foreach ($params as $k => $v) {
            $name = (strpos((string)$k, ':') === 0) ? $k : ':' . $k;
            if ($v === null) $stmt->bindValue($name, null, \PDO::PARAM_NULL);
            elseif (is_int($v)) $stmt->bindValue($name, $v, \PDO::PARAM_INT);
            elseif (is_bool($v)) $stmt->bindValue($name, $v ? 1 : 0, \PDO::PARAM_INT);
            else $stmt->bindValue($name, (string)$v, \PDO::PARAM_STR);
        }
        $stmt->execute();
        return $stmt;
    }

    // Database wrapper: predpokladáme kompatibilnú API ako v tvojom kóde
    if (is_object($db) && method_exists($db, 'prepare')) {
        $stmt = $db->prepare($sql);
        if ($stmt === false) throw new \RuntimeException('DB wrapper prepare failed');
        foreach ($params as $k => $v) {
            $name = (strpos((string)$k, ':') === 0) ? $k : ':' . $k;
            if ($v === null) $stmt->bindValue($name, null, \PDO::PARAM_NULL);
            elseif (is_int($v)) $stmt->bindValue($name, $v, \PDO::PARAM_INT);
            elseif (is_bool($v)) $stmt->bindValue($name, $v ? 1 : 0, \PDO::PARAM_INT);
            else $stmt->bindValue($name, (string)$v, \PDO::PARAM_STR);
        }
        $stmt->execute();
        return $stmt;
    }

    throw new \RuntimeException('Unsupported DB instance');
};

// načítaj základné údaje o userovi + profile
try {
    $stmt = $prepareAndExecute("SELECT id, is_active, is_locked, actor_type, email_enc, email_key_version, email_hash, email_hash_key_version FROM pouzivatelia WHERE id = :id LIMIT 1", [':id' => $userId]);
    $user = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$user) {
        // session stale invalid
        SessionManager::destroySession($db);
        header('Location: login.php');
        exit;
    }

    // profile
    $stmt2 = $prepareAndExecute("SELECT user_id, full_name FROM user_profiles WHERE user_id = :id LIMIT 1", [':id' => $userId]);
    $profile = $stmt2->fetch(\PDO::FETCH_ASSOC);
    $displayName = $profile['full_name'] ?? '';
} catch (\Throwable $e) {
    if (class_exists('Logger')) { try { Logger::systemError($e, $userId); } catch (\Throwable $_) {} }
    http_response_code(500);
    echo 'Interná chyba';
    exit;
}

$status = null;
$messages = [];

/* -------------------------
 * UPDATE NAME (user_profiles.full_name)
 * ------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_name') {
    $displayNameNew = trim((string)($_POST['display_name'] ?? ''));
    if ($displayNameNew === '') {
        $messages[] = 'Meno nesmie byť prázdne.';
    } else {
        try {
            // pokus update, ak nič nebolo zmenené -> insert
            $upd = $prepareAndExecute("UPDATE user_profiles SET full_name = :fn, updated_at = NOW() WHERE user_id = :uid", [':fn' => $displayNameNew, ':uid' => $userId]);
            $affected = ($upd instanceof \PDOStatement) ? $upd->rowCount() : null;
            if ($affected === 0) {
                // insert
                $prepareAndExecute("INSERT INTO user_profiles (user_id, full_name, updated_at) VALUES (:uid, :fn, NOW())", [':uid' => $userId, ':fn' => $displayNameNew]);
            }
            $displayName = $displayNameNew;
            $status = 'name_updated';
            $messages[] = 'Meno úspešne aktualizované.';
            if (class_exists('Logger')) { try { Logger::systemMessage('info','profile_name_changed',$userId,['full_name'=>$displayName]); } catch (\Throwable $_) {} }
        } catch (\Throwable $e) {
            if (class_exists('Logger')) { try { Logger::systemError($e, $userId); } catch (\Throwable $_) {} }
            $messages[] = 'Nepodarilo sa uložiť meno.';
        }
    }
}

/* -------------------------
 * UPDATE EMAIL (secure: email_enc + email_hash + create verification token)
 * ------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_email') {
    $emailInput = strtolower(trim((string)($_POST['email'] ?? '')));

    if (!filter_var($emailInput, FILTER_VALIDATE_EMAIL)) {
        $messages[] = 'Neplatný e-mail.';
    } else {
        // normalizácia
        $emailNorm = mb_strtolower($emailInput, 'UTF-8');
        $emailEncPayload = null;
        $emailEncKeyVer = null;
        $emailHashBin = null;
        $emailHashVer = null;

        // derive email_hash (KeyManager)
        try {
            if (class_exists('KeyManager') && method_exists('KeyManager', 'deriveHmacWithLatest')) {
                $keysDir = defined('KEYS_DIR') ? KEYS_DIR : ($GLOBALS['config']['paths']['keys'] ?? null);
                $hinfo = KeyManager::deriveHmacWithLatest('EMAIL_HASH_KEY', $keysDir, 'email_hash_key', $emailNorm);
                $emailHashBin = $hinfo['hash'] ?? null; // binary
                $emailHashVer = $hinfo['version'] ?? null;
            }
        } catch (\Throwable $e) {
            if (class_exists('Logger')) { try { Logger::systemMessage('warning','profile_email_hash_fail',$userId, ['err'=>$e->getMessage()]); } catch (\Throwable $_) {} }
            // pokračujeme bez hash (uložíme len enc ak je)
            $emailHashBin = null;
            $emailHashVer = null;
        }

        // optional email encryption
        try {
            if (class_exists('Crypto') && class_exists('KeyManager') && function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
                Crypto::initFromKeyManager($GLOBALS['config']['paths']['keys'] ?? (defined('KEYS_DIR') ? KEYS_DIR : null));
                $emailEncPayload = Crypto::encrypt($emailNorm, 'binary');
                $info = KeyManager::locateLatestKeyFile($GLOBALS['config']['paths']['keys'] ?? (defined('KEYS_DIR') ? KEYS_DIR : null), 'email_key');
                $emailEncKeyVer = $info['version'] ?? null;
                Crypto::clearKey();
            }
        } catch (\Throwable $e) {
            if (class_exists('Logger')) { try { Logger::systemMessage('warning','profile_email_encrypt_fail',$userId, ['err'=>$e->getMessage()]); } catch (\Throwable $_) {} }
            $emailEncPayload = null;
            $emailEncKeyVer = null;
        }

        // create verification token (selector + validator)
        try {
            $selector = bin2hex(random_bytes(6));
            $validator = random_bytes(32);
            // token_hash stored as hex sha256 of validator (so we can index by selector separately if needed)
            $tokenHashHex = hash('sha256', $validator);
            // validator_hash: HMAC-SHA256 with pepper if available (binary)
            $validatorHashBin = null;
            try {
                // try to obtain pepper via KeyManager (like register.php)
                if (class_exists('KeyManager') && method_exists('KeyManager', 'getRawKeyBytes')) {
                    $keysDir = defined('KEYS_DIR') ? KEYS_DIR : ($GLOBALS['config']['paths']['keys'] ?? null);
                    $pinfo = KeyManager::getRawKeyBytes('PASSWORD_PEPPER', $keysDir, 'password_pepper', false, 32);
                    $pepRaw = $pinfo['raw'] ?? null;
                    if (is_string($pepRaw) && strlen($pepRaw) === 32) {
                        $validatorHashBin = hash_hmac('sha256', $validator, $pepRaw, true);
                        // zero pepper raw if memzero exists
                        try { KeyManager::memzero($pepRaw); } catch (\Throwable $_) {}
                    } else {
                        // fallback: plain HMAC with empty key (less ideal)
                        $validatorHashBin = hash_hmac('sha256', $validator, '', true);
                    }
                } else {
                    $validatorHashBin = hash_hmac('sha256', $validator, '', true);
                }
            } catch (\Throwable $_) {
                $validatorHashBin = hash_hmac('sha256', $validator, '', true);
            }

            // uloženie do DB v transakcii
            if ($db instanceof \PDO && $db->inTransaction() === false) $db->beginTransaction();
            elseif (is_object($db) && method_exists($db, 'beginTransaction')) {
                try { $db->beginTransaction(); } catch (\Throwable $_) {}
            }

            // update pouzivatelia: email_enc/email_key_version/email_hash/email_hash_key_version, is_active=0
            $updSql = "UPDATE pouzivatelia SET
                        email_enc = :email_enc,
                        email_key_version = :email_key_version,
                        email_hash = :email_hash,
                        email_hash_key_version = :email_hash_key_version,
                        is_active = 0,
                        updated_at = NOW()
                       WHERE id = :id";
            $stmtUpd = $prepareAndExecute($updSql, [
                ':email_enc' => $emailEncPayload,
                ':email_key_version' => $emailEncKeyVer,
                ':email_hash' => $emailHashBin,
                ':email_hash_key_version' => $emailHashVer,
                ':id' => $userId,
            ]);

            // insert email_verifications
            $expiresAt = (new \DateTimeImmutable('+1 day'))->format('Y-m-d H:i:s.u');
            $insSql = "INSERT INTO email_verifications
                (user_id, token_hash, selector, validator_hash, key_version, expires_at, created_at)
                VALUES (:uid, :token_hash, :selector, :validator_hash, :kv, :expires_at, NOW())";
            $prepareAndExecute($insSql, [
                ':uid' => $userId,
                ':token_hash' => $tokenHashHex,
                ':selector' => $selector,
                ':validator_hash' => $validatorHashBin,
                ':kv' => $emailEncKeyVer ?? null,
                ':expires_at' => $expiresAt,
            ]);

            // commit
            if ($db instanceof \PDO && $db->inTransaction()) $db->commit();
            elseif (is_object($db) && method_exists($db, 'commit')) {
                try { $db->commit(); } catch (\Throwable $_) {}
            }

            // enqueue verification email (Mailer::enqueue expects payload array)
            try {
                if (class_exists('Mailer')) {
                    $verifyUrl = rtrim((string)($_ENV['BASE_URL'] ?? ($GLOBALS['config']['app_url'] ?? '')), '/') . "/verify.php?selector={$selector}&validator=" . bin2hex($validator);
                    $payload = [
                        'to' => $emailInput,
                        'subject' => 'Overenie novej e-mailovej adresy',
                        'template' => 'emails/register_verify.php', // worker's EmailTemplates::renderWithText expects a template name; adjust ak máš iné
                        'vars' => [
                            'full_name' => $displayName,
                            'verify_url' => $verifyUrl,
                        ],
                    ];
                    // init Mailer if needed (best-effort): Mailer::init($config, $pdo) — predpokladáme init už bežal v bootstrap
                    try {
                        Mailer::enqueue($payload);
                    } catch (\Throwable $me) {
                        if (class_exists('Logger')) { try { Logger::systemMessage('warning','mailer_enqueue_failed',$userId,['err'=>$me->getMessage()]); } catch (\Throwable $_) {} }
                        $messages[] = 'E-mailové overenie bolo vytvorené, ale doručenie sa momentálne zaškatuľkuje (worker failed).';
                    }
                }
            } catch (\Throwable $_) {
                // swallow — neblokujeme používateľa, overenie je v DB
            }

            $status = 'email_updated';
            $messages[] = 'E-mail aktualizovaný. Skontrolujte novú adresu pre potvrdenie.';
            if (class_exists('Logger')) { try { Logger::systemMessage('info','profile_email_change_requested',$userId,['email_hash_key'=>$emailHashVer]); } catch (\Throwable $_) {} }

        } catch (\Throwable $e) {
            // rollback
            try {
                if ($db instanceof \PDO && $db->inTransaction()) $db->rollBack();
                elseif (is_object($db) && method_exists($db, 'rollback')) { try { $db->rollback(); } catch (\Throwable $_) {} }
            } catch (\Throwable $_) {}
            if (class_exists('Logger')) { try { Logger::systemError($e, $userId); } catch (\Throwable $_) {} }
            $messages[] = 'Nepodarilo sa aktualizovať e-mail.';
        }
    }
}

/* -------------------------
 * UPDATE PASSWORD
 * ------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_password') {
    $password = (string)($_POST['password'] ?? '');
    $password2 = (string)($_POST['password2'] ?? '');

    if ($password === '' || $password !== $password2) {
        $messages[] = 'Heslá nesmú byť prázdne a musia sa zhodovať.';
    } elseif (strlen($password) < 8) {
        $messages[] = 'Heslo musí mať aspoň 8 znakov.';
    } else {
        try {
            if (class_exists('Auth') && method_exists('Auth', 'hashPassword')) {
                $newHash = Auth::hashPassword($password);
                $algoMeta = Auth::buildHesloAlgoMetadata($newHash);
                $pepVer = null;
                try { $pepVer = Auth::getPepperVersionForStorage(); } catch (\Throwable $_) { $pepVer = null; }
            } else {
                // fallback: simple password_hash without pepper (less ideal)
                $newHash = password_hash($password, PASSWORD_ARGON2ID);
                $info = password_get_info($newHash);
                $algoMeta = $info['algoName'] ?? null;
                $pepVer = null;
            }

            $prepareAndExecute("UPDATE pouzivatelia SET heslo_hash = :h, heslo_algo = :algo, heslo_key_version = :pep, updated_at = NOW() WHERE id = :id", [
                ':h' => $newHash,
                ':algo' => $algoMeta,
                ':pep' => $pepVer,
                ':id' => $userId,
            ]);
            $status = 'password_updated';
            $messages[] = 'Heslo úspešne zmenené.';
            if (class_exists('Logger')) { try { Logger::systemMessage('info','profile_password_changed',$userId,[]); } catch (\Throwable $_) {} }
        } catch (\Throwable $e) {
            if (class_exists('Logger')) { try { Logger::systemError($e, $userId); } catch (\Throwable $_) {} }
            $messages[] = 'Nepodarilo sa zmeniť heslo.';
        }
    }
}

// Priprav vystupné údaje pre šablónu
$uiUser = [
    'id' => (int)$user['id'],
    'is_active' => (int)$user['is_active'],
    'is_locked' => (int)$user['is_locked'],
    'actor_type' => $user['actor_type'] ?? null,
    // indikátor či máme email uložený (bez odhaľovania plain textu)
    'email_present' => (!empty($user['email_hash']) || !empty($user['email_enc']) ? true : false),
];

echo Templates::render('pages/profile.php', [
    'user' => $uiUser,
    'display_name' => $displayName,
    'status' => $status,
    'messages' => $messages,
]);
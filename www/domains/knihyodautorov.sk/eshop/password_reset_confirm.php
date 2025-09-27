<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';

/**
 * password_reset_confirm.php
 *
 * Krok 2: Uživatel přijde s odkazem (selector + validator).
 * - zkontroluje se token (s podporou KeyManager rotace HMAC)
 * - pokud OK, zobrazí formulář pro nové heslo
 * - po POSTu ověří heslo (Validator::validatePasswordStrength), předhashuje s pepperom (pokud dostupný),
 *   uloží nové heslo, zneplatní token a (volitelně) přihlásí uživatele
 *
 * Očekává GET nebo POST parametry:
 *   selector (hex), validator (hex 64 chars)
 */

// input
$selector = (string)($_GET['selector'] ?? $_POST['selector'] ?? '');
$validatorHex = (string)($_GET['validator'] ?? $_POST['validator'] ?? '');

if ($selector === '' || !ctype_xdigit($selector)) {
    echo Templates::render('pages/password_reset_confirm.php', ['status' => 'invalid']);
    exit;
}
if ($validatorHex === '' || !ctype_xdigit($validatorHex) || strlen($validatorHex) !== 64) {
    echo Templates::render('pages/password_reset_confirm.php', ['status' => 'invalid']);
    exit;
}

$validator = @hex2bin($validatorHex);
if ($validator === false || strlen($validator) !== 32) {
    echo Templates::render('pages/password_reset_confirm.php', ['status' => 'invalid']);
    exit;
}

// get PDO robustly (same pattern as other files)
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
    if (class_exists('Logger')) { try { Logger::systemError($e); } catch (\Throwable $_) {} }
    http_response_code(500);
    echo Templates::render('pages/password_reset_confirm.php', ['status' => 'error']);
    exit;
}

try {
    // načti záznam podle selector
    $sql = "SELECT ev.id AS ev_id, ev.user_id, ev.validator_hash, ev.expires_at, ev.used_at, u.is_locked, u.is_active
            FROM email_verifications ev
            JOIN pouzivatelia u ON u.id = ev.user_id
            WHERE ev.selector = :selector
            LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':selector', $selector, \PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);

    if (!$row) {
        echo Templates::render('pages/password_reset_confirm.php', ['status' => 'invalid']);
        exit;
    }

    // token již použit
    if (!empty($row['used_at'])) {
        echo Templates::render('pages/password_reset_confirm.php', ['status' => 'already_used']);
        exit;
    }

    // expirace
    $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    try {
        $exp = new \DateTimeImmutable($row['expires_at'], new \DateTimeZone('UTC'));
        if ($exp < $now) {
            echo Templates::render('pages/password_reset_confirm.php', ['status' => 'expired']);
            exit;
        }
    } catch (\Throwable $_) {
        if (class_exists('Logger')) { try { Logger::systemError(new \RuntimeException('Invalid expires_at format for email_verifications id=' . ($row['ev_id'] ?? ''))); } catch (\Throwable $_) {} }
        echo Templates::render('pages/password_reset_confirm.php', ['status' => 'error']);
        exit;
    }

    // Ověření validatoru: podpora KeyManager::deriveHmacCandidates (rotace). Pokud v DB není validator_hash, fallback na sha256.
    $dbValidatorHash = $row['validator_hash'] ?? null;
    $validatorOk = false;
    if (empty($dbValidatorHash)) {
        // fallback: server ukládal jen token_hash (sha256 hex) -> also ensure token_hash matches
        // But here we expect validator_hash present; if absent, consider invalid for safety
        if (class_exists('Logger')) {
            try { Logger::error('Password reset token missing validator_hash in DB', (int)$row['user_id'], ['ev_id' => $row['ev_id']]); } catch (\Throwable $_) {}
        }
        echo Templates::render('pages/password_reset_confirm.php', ['status' => 'invalid']);
        exit;
    } else {
        // compare using KeyManager candidates (binary compare)
        try {
            if (class_exists('KeyManager') && method_exists('KeyManager', 'deriveHmacCandidates')) {
                $keysDir = defined('KEYS_DIR') ? KEYS_DIR : ($_ENV['KEYS_DIR'] ?? null);
                $cands = KeyManager::deriveHmacCandidates('EMAIL_VERIFICATION_KEY', $keysDir, 'email_verification_key', $validator);
                if (!empty($cands) && is_array($cands)) {
                    foreach ($cands as $c) {
                        if (!isset($c['hash'])) continue;
                        // both binary -> use hash_equals
                        if (is_string($dbValidatorHash) && is_string($c['hash']) && hash_equals($dbValidatorHash, $c['hash'])) {
                            $validatorOk = true;
                            break;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            if (class_exists('Logger')) { try { Logger::error('KeyManager deriveHmacCandidates failed on password reset confirm', (int)$row['user_id'], ['exception' => (string)$e]); } catch (\Throwable $_) {} }
        }

        // fallback: if KeyManager not available or didn't match, try direct sha256(compare) — some older flows store sha256(validator) binary
        if (!$validatorOk) {
            try {
                $calc = hash('sha256', $validator, true);
                if (is_string($dbValidatorHash) && hash_equals($dbValidatorHash, $calc)) {
                    $validatorOk = true;
                }
            } catch (\Throwable $_) { /* ignore */ }
        }
    }

    if (!$validatorOk) {
        if (class_exists('Logger')) {
            try { Logger::verify('password_reset_invalid', (int)$row['user_id'], ['selector' => $selector]); } catch (\Throwable $_) {}
        }
        echo Templates::render('pages/password_reset_confirm.php', ['status' => 'invalid']);
        exit;
    }

    // pokud je POST -> zpracuj nové heslo
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // CSRF pokud máte (volitelné)
        $password = (string)($_POST['password'] ?? '');
        $password2 = (string)($_POST['password2'] ?? '');

        if ($password === '' || $password !== $password2) {
            echo Templates::render('pages/password_reset_confirm.php', [
                'status' => 'form_error',
                'selector' => $selector,
                'validator' => $validatorHex,
                'error' => 'Hesla se neshodují nebo jsou prázdná.'
            ]);
            exit;
        }

        // validate strength (pokud máte Validator)
        if (class_exists('Validator') && !Validator::validatePasswordStrength($password, 10)) {
            echo Templates::render('pages/password_reset_confirm.php', [
                'status' => 'form_error',
                'selector' => $selector,
                'validator' => $validatorHex,
                'error' => 'Heslo nie je dostatočne silné (min. 10 znakov).'
            ]);
            exit;
        }

        // optional: získat pepper pro HMAC pre-processing (pokud používáte)
        $pepRaw = null;
        $pepVer = null;
        try {
            if (class_exists('KeyManager') && method_exists('KeyManager', 'getPasswordPepperInfo')) {
                $pinfo = KeyManager::getPasswordPepperInfo(KEYS_DIR);
                if (!empty($pinfo['raw']) && is_string($pinfo['raw'])) {
                    $pepRaw = $pinfo['raw'];
                    $pepVer = $pinfo['version'] ?? null;
                }
            }
        } catch (\Throwable $_) {
            // pokud pepper není dostupný, pokračujeme bez něj
            $pepRaw = null;
        }

        // prepare final password hash (pre-hash with pepper if available)
        try {
            if ($pepRaw !== null && is_string($pepRaw)) {
                $pwPre = hash_hmac('sha256', $password, $pepRaw, true);
            } else {
                $pwPre = $password;
            }

            // použij Argon2id pokud dostupné (nebo PASSWORD_DEFAULT)
            $algo = PASSWORD_ARGON2ID;
            $hash = false;
            if (defined('PASSWORD_ARGON2ID')) {
                // small sensible defaults if Validator/register provides different options
                $opts = [
                    'memory_cost' => (1 << 16), // 64 MiB
                    'time_cost' => 4,
                    'threads' => 2,
                ];
                $hash = password_hash($pwPre, PASSWORD_ARGON2ID, $opts);
            }
            if ($hash === false) {
                $hash = password_hash($pwPre, PASSWORD_DEFAULT);
            }
        } catch (\Throwable $e) {
            if (class_exists('Logger')) { try { Logger::systemError($e, (int)$row['user_id']); } catch (\Throwable $_) {} }
            echo Templates::render('pages/password_reset_confirm.php', ['status' => 'error']);
            exit;
        } finally {
            // memzero prehash
            if (isset($pwPre) && is_string($pwPre)) {
                try { if (class_exists('KeyManager')) KeyManager::memzero($pwPre); } catch (\Throwable $_) {}
            }
            // memzero pepper raw
            try { if (isset($pepRaw) && is_string($pepRaw) && class_exists('KeyManager')) KeyManager::memzero($pepRaw); } catch (\Throwable $_) {}
        }

        // uložení v transakci: update hesla + zneplatnit token
        try {
            $pdo->beginTransaction();

            $updUser = $pdo->prepare("UPDATE pouzivatelia
                                     SET heslo_hash = :hash, heslo_key_version = :key_ver, must_change_password = 0, updated_at = UTC_TIMESTAMP(6)
                                     WHERE id = :uid");
            $updUser->bindValue(':hash', $hash, \PDO::PARAM_STR);
            $updUser->bindValue(':key_ver', $pepVer, \PDO::PARAM_STR);
            $updUser->bindValue(':uid', (int)$row['user_id'], \PDO::PARAM_INT);
            $updUser->execute();

            $updToken = $pdo->prepare("UPDATE email_verifications
                                      SET used_at = UTC_TIMESTAMP(6), token_hash = NULL, validator_hash = NULL, key_version = NULL
                                      WHERE id = :id");
            $updToken->bindValue(':id', (int)$row['ev_id'], \PDO::PARAM_INT);
            $updToken->execute();

            $pdo->commit();

            if (class_exists('Logger')) {
                try { Logger::systemMessage('notice', 'password_reset_success', (int)$row['user_id'], ['ev_id' => $row['ev_id']]); } catch (\Throwable $_) {}
            }

            // autologin (pokud máte SessionManager)
            try {
                if (class_exists('SessionManager') && method_exists('SessionManager', 'createSession')) {
                    SessionManager::createSession($pdo, (int)$row['user_id']);
                }
            } catch (\Throwable $_) {
                // ignore session creation failures (nechceme zrušit změnu hesla kvůli session chybě)
            }

            // success page
            echo Templates::render('pages/password_reset_confirm.php', ['status' => 'success']);
            exit;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) { try { $pdo->rollBack(); } catch (\Throwable $_) {} }
            if (class_exists('Logger')) { try { Logger::systemError($e, (int)$row['user_id']); } catch (\Throwable $_) {} }
            echo Templates::render('pages/password_reset_confirm.php', ['status' => 'error']);
            exit;
        } finally {
            // memzero validator binary (sensitive) — dobrý zvyk
            try { if (class_exists('KeyManager')) KeyManager::memzero($validator); } catch (\Throwable $_) {}
        }
    }

    // GET -> zobrazit formulář
    echo Templates::render('pages/password_reset_confirm.php', [
        'status' => 'form',
        'selector' => $selector,
        'validator' => $validatorHex,
    ]);
    exit;

} catch (\Throwable $e) {
    if (isset($pdo) && $pdo instanceof \PDO && $pdo->inTransaction()) {
        try { $pdo->rollBack(); } catch (\Throwable $_) {}
    }
    if (class_exists('Logger')) { try { Logger::systemError($e); } catch (\Throwable $_) {} }
    echo Templates::render('pages/password_reset_confirm.php', ['status' => 'error']);
    exit;
}
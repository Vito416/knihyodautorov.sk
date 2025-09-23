<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';

/**
 * verify.php
 * Overenie e-mailu pomocou selector + validator
 *
 * Očakáva sa:
 *  - v email_verifications uložené:
 *      selector (hex), token_hash (sha256 hex), validator_hash (binary HMAC), expires_at, user_id
 *  - KeyManager (pre voliteľné HMAC porovnanie), Logger, Database/PDO
 */

// jednoduché validácie vstupu
$selector = (string)($_GET['selector'] ?? '');
$validatorHex = (string)($_GET['validator'] ?? '');
// selector: očakávame hex (bin2hex(random_bytes(6)) => 12 hex znakov), ale ak chcete povoliť iné dĺžky, upravte
if ($selector === '' || !ctype_xdigit($selector)) {
    echo Templates::render('pages/verify.php', ['status' => 'invalid']);
    exit;
}
if ($validatorHex === '' || !ctype_xdigit($validatorHex) || strlen($validatorHex) !== 64) {
    // validator je 32 bajtov => 64 hex znakov
    echo Templates::render('pages/verify.php', ['status' => 'invalid']);
    exit;
}

$validator = @hex2bin($validatorHex);
if ($validator === false || strlen($validator) !== 32) {
    echo Templates::render('pages/verify.php', ['status' => 'invalid']);
    exit;
}

// získať PDO rovnako robustne ako v iných súboroch
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
    if (!($pdo instanceof \PDO)) {
        throw new \RuntimeException('Databázové pripojenie nie je dostupné vo forme PDO.');
    }
} catch (\Throwable $e) {
    if (class_exists('Logger')) { try { Logger::systemError($e); } catch (\Throwable $_) {} }
    http_response_code(500);
    echo Templates::render('pages/verify.php', ['status' => 'error']);
    exit;
}

try {
    // načítame záznam podľa selector (limit 1)
    $sql = "SELECT ev.id AS ev_id, ev.user_id, ev.token_hash, ev.validator_hash, ev.expires_at, u.is_active, x.confirm_selector, x.confirm_validator_hash, x.confirm_key_version, x.confirm_expires, x.confirmed_at, x.unsubscribed_at
            FROM email_verifications ev
            JOIN pouzivatelia u ON u.id = ev.user_id
            LEFT JOIN newsletter_subscribers x ON x.user_id = ev.user_id
            WHERE ev.selector = :selector
            LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':selector', $selector, \PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);

    if (!$row) {
        echo Templates::render('pages/verify.php', ['status' => 'not_found']);
        exit;
    }

    // kontrola expiracie (UTC)
    $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    try {
        $exp = new \DateTimeImmutable($row['expires_at'], new \DateTimeZone('UTC'));
        if ($exp < $now) {
            // (voliteľne) označíme token ako used/expired alebo necháme pre audit
            echo Templates::render('pages/verify.php', ['status' => 'expired']);
            exit;
        }
    } catch (\Throwable $_) {
        // ak je formát expires_at zlý, logujeme a odmietneme
        if (class_exists('Logger')) { try { Logger::systemError(new \RuntimeException('Invalid expires_at format for email_verifications id=' . ($row['ev_id'] ?? ''))); } catch (\Throwable $_) {} }
        echo Templates::render('pages/verify.php', ['status' => 'error']);
        exit;
    }

    // 1) základné overenie token_hash: hash('sha256', validator) => hex string
    $calcTokenHex = hash('sha256', $validator);
    $dbTokenHash = (string)($row['token_hash'] ?? '');

    if (!hash_equals($dbTokenHash, $calcTokenHex)) {
        // nezhoda -> invalid
        if (class_exists('Logger')) {
            if (method_exists('Logger', 'verify')) {
                try { Logger::verify('verify_failure', (int)$row['user_id'], ['selector' => $selector, 'reason' => 'email_verify_token_mismatch']); } catch (\Throwable $_) {}
            }
        }
        echo Templates::render('pages/verify.php', ['status' => 'invalid']);
        exit;
    }

    // 2) voliteľné ďalšie overenie validator_hash (HMAC) ak je v DB uložené
    $dbValidatorHash = $row['validator_hash'] ?? null;
    $validatorHashOk = true; // predpokladáme OK ak DB neobsahuje hodnotu
    if (!empty($dbValidatorHash)) {
        $validatorHashOk = false;
        // Pokúsime sa overiť cez KeyManager (podpora rotácie): deriveHmacCandidates
        try {
            if (class_exists('KeyManager') && method_exists('KeyManager', 'deriveHmacCandidates')) {
                $keysDir = defined('KEYS_DIR') ? KEYS_DIR : ($_ENV['KEYS_DIR'] ?? null);
                $cands = KeyManager::deriveHmacCandidates('EMAIL_VERIFICATION_KEY', $keysDir, 'email_verification_key', $validator);
                if (!empty($cands) && is_array($cands)) {
                    foreach ($cands as $cand) {
                        if (!isset($cand['hash'])) continue;
                        // both are binary strings — timing-safe compare
                        if (is_string($dbValidatorHash) && is_string($cand['hash']) && hash_equals($dbValidatorHash, $cand['hash'])) {
                            $validatorHashOk = true;
                            break;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // log, ale neskončíme hneď — pokúsime fallback na pepper
            if (class_exists('Logger')) { try { Logger::error('KeyManager deriveHmacCandidates failed during verification', $row['user_id'] ?? null, ['exception' => (string)$e]); } catch (\Throwable $_) {} }
        }

        // ak po pokusoch neprešiel validátor, považujeme to za neplatný token
        if (!$validatorHashOk) {
            if (class_exists('Logger')) {
                if (method_exists('Logger', 'verify')) {
                try { Logger::verify('verify_failure', (int)$row['user_id'], ['selector' => $selector, 'reason' => 'email_verify_validator_mismatch']); } catch (\Throwable $_) {}
                }
            }
            echo Templates::render('pages/verify.php', ['status' => 'invalid']);
            exit;
        }
    }
    
    $newsletter = 0;
    // --- všetky kontroly prešli: aktivovať účet a zneplatniť token v jednom kroku ---
    try {
        $pdo->beginTransaction();

        // Ak už je účet aktívny, stále zneplatníme token a ukončíme s "already_active" alebo "success"
        $updUser = $pdo->prepare('UPDATE pouzivatelia SET is_active = 1, updated_at = UTC_TIMESTAMP() WHERE id = :uid');
        $updUser->bindValue(':uid', (int)$row['user_id'], \PDO::PARAM_INT);
        $updUser->execute();

        // zneplatniť token: nastaviť used_at a vymazať citlivé údaje (token_hash, validator_hash)
        $updToken = $pdo->prepare('UPDATE email_verifications
                                  SET used_at = UTC_TIMESTAMP(), token_hash = NULL, validator_hash = NULL, key_version = NULL, used_at = UTC_TIMESTAMP()
                                  WHERE id = :id');
        $updToken->bindValue(':id', (int)$row['ev_id'], \PDO::PARAM_INT);
        $updToken->execute();

        // --- pokud existuje záznam newsletter_subscribers a ještě nebyl potvrzen ---
        $updNewsletter = $pdo->prepare('
            UPDATE newsletter_subscribers
            SET confirm_selector = NULL,
                confirm_validator_hash = NULL,
                confirm_key_version = NULL,
                confirm_expires = NULL,
                confirmed_at = UTC_TIMESTAMP(6)
            WHERE user_id = :uid
            AND confirmed_at IS NULL
            AND unsubscribed_at IS NULL
        ');
        $updNewsletter->bindValue(':uid', (int)$row['user_id'], \PDO::PARAM_INT);
        $updNewsletter->execute();
        if ($updNewsletter->rowCount() > 0) {
            $newsletter = 1;
        }

        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) { try { $pdo->rollBack(); } catch (\Throwable $_) {} }
        if (class_exists('Logger')) { try { Logger::systemError($e, $row['user_id'] ?? null); } catch (\Throwable $_) {} }
        echo Templates::render('pages/verify.php', [
            'status' => 'error',
            'newsletter' => (int)$newsletter,
        ]);
        exit;
    }

    // logging success
    if (class_exists('Logger')) {
        try {
            if (method_exists('Logger', 'verify')) {
                try { Logger::verify('verify_success', (int)$row['user_id'], ['selector' => $selector]); } catch (\Throwable $_) {}
            }
        } catch (\Throwable $_) {}
    }

    echo Templates::render('pages/verify.php', [
        'status' => 'success',
        'newsletter' => (int)$newsletter,
    ]);
    exit;

} catch (\Throwable $e) {
    if (isset($pdo) && $pdo instanceof \PDO && $pdo->inTransaction()) {
        try { $pdo->rollBack(); } catch (\Throwable $_) {}
    }
    if (class_exists('Logger')) { try { Logger::systemError($e); } catch (\Throwable $_) {} }
    echo Templates::render('pages/verify.php', ['status' => 'error']);
    exit;
}
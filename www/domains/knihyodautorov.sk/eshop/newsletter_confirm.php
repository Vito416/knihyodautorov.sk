<?php
declare(strict_types=1);
//TODO: check flow exist, already subscribed, rotation keys before confirm (duplicity), ... 
/**
 * newsletter_confirm.php
 *
 * Double-opt-in confirmation handler — included by frontcontroller.
 * Returns: ['template'=>'pages/newsletter_confirm.php','vars'=>[...], 'status'=>int?]
 *
 * Expects selected shared vars injected by frontcontroller (via TrustedShared::prepareForHandler),
 * e.g. $pdo or $db/$database (PDO or wrapper), $KEYS_DIR or constant KEYS_DIR, $KeyManager (FQCN|string or object),
 * optional $Logger (FQCN|string or object), $Mailer (FQCN|string or object), $Crypto (FQCN|string or object).
 */

$makeResp = function(array $vars = [], int $status = 200, ?string $template = 'pages/newsletter_confirm.php') {
    $ret = ['template' => $template, 'vars' => $vars];
    if ($status !== 200) $ret['status'] = $status;
    return $ret;
};

/** call method whether $target is class name or object; returns null on failure */
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

/** resolve injected target or fallback to global class if available */
$resolveTarget = function($injected, string $fallbackClass) {
    if (!empty($injected)) {
        if (is_string($injected) && class_exists($injected)) return $injected;
        if (is_object($injected)) return $injected;
    }
    if (class_exists($fallbackClass)) return $fallbackClass;
    return null;
};

/** safe logger invoker: accepts class-name (static) or object with methods */
$loggerInvoke = function(?string $method, string $msg, $userId = null, array $ctx = []) use (&$Logger, $call, $resolveTarget) {
    if (empty($Logger)) return;
    try {
        $target = $resolveTarget($Logger, \BlackCat\Core\Log\Logger::class ?? 'BlackCat\Core\Log\Logger');
        if ($target === null) return;
        if ($method === 'systemMessage') {
            // systemMessage signature: (level, message, userId, ctx)
            if (is_string($target)) {
                if (method_exists($target, 'systemMessage')) {
                    return $call($target, 'systemMessage', [$ctx['level'] ?? 'notice', $msg, $userId, $ctx]);
                }
            } else {
                if (method_exists($target, 'systemMessage')) {
                    return $target->systemMessage($ctx['level'] ?? 'notice', $msg, $userId, $ctx);
                }
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

/** safe memzero helper */
$safeMemzero = function($buf) use (&$KeyManager, $call) : void {
    try {
        if ($buf === null) return;
        // pokud KeyManager má memzero, použij ho (pokud je injectnutý jako class-name nebo objekt)
        if (!empty($KeyManager)) {
            $km = $KeyManager;
            if (is_string($km) && class_exists($km) && method_exists($km, 'memzero')) {
                $call($km, 'memzero', [$buf]);
                return;
            }
            if (is_object($km) && method_exists($km, 'memzero')) {
                $km->memzero($buf);
                return;
            }
        }
        // fallback sodium_memzero
        if (function_exists('sodium_memzero')) {
            @sodium_memzero($buf);
            return;
        }
        // final best-effort: overwrite string
        if (is_string($buf)) {
            $len = strlen($buf);
            $buf = str_repeat("\0", $len);
        }
    } catch (\Throwable $_) {
        // intentionally silent
    }
};

// DB: try to get PDO from injected $db or fallback via Database::getInstance()
$pdo = null;
try {
    if (isset($db) && $db !== null) {
        // $db might be PDO or wrapper with getPdo()
        if ($db instanceof \PDO) {
            $pdo = $db;
        } elseif (is_object($db) && method_exists($db, 'getPdo')) {
            $maybe = $db->getPdo();
            if ($maybe instanceof \PDO) $pdo = $maybe;
        }
    }
    // fallback to 'database' wrapper or Database singleton
    if ($pdo === null) {
        if (!empty($database)) {
            if ($database instanceof \PDO) {
                $pdo = $database;
            } elseif (is_object($database) && method_exists($database, 'getPdo')) {
                $maybe = $database->getPdo();
                if ($maybe instanceof \PDO) $pdo = $maybe;
            }
        }
    }
    if ($pdo === null && class_exists(\BlackCat\Core\Database::class, true) && method_exists(\BlackCat\Core\Database::class, 'getInstance')) {
        $dbInst = \BlackCat\Core\Database::getInstance();
        if ($dbInst instanceof \PDO) $pdo = $dbInst;
        elseif (is_object($dbInst) && method_exists($dbInst, 'getPdo')) {
            $maybe = $dbInst->getPdo();
            if ($maybe instanceof \PDO) $pdo = $maybe;
        }
    }

    if (!($pdo instanceof \PDO)) {
        $loggerInvoke('error', 'newsletter_confirm: PDO not available', null, []);
        return $makeResp(['error' => 'Interná chyba (DB).'], 500);
    }
} catch (\Throwable $e) {
    $loggerInvoke('error', 'newsletter_confirm: DB getPdo failed', null, ['ex' => (string)$e]);
    return $makeResp(['error' => 'Interná chyba (DB).'], 500);
}

// --- KEYS_DIR + KeyManager detection -------------------------------------
$keysDir = $KEYS_DIR ?? (defined('KEYS_DIR') ? KEYS_DIR : null);
if (!($keysDir && is_string($keysDir))) {
    $loggerInvoke('error', 'newsletter_confirm: KEYS_DIR missing', null, []);
    return $makeResp(['error' => 'Interná chyba: kľúče nie sú nakonfigurované.'], 500);
}
$KeyManager = $KeyManager ?? (\BlackCat\Core\Security\KeyManager::class ?? null);
if (!($KeyManager && (is_string($KeyManager) ? class_exists($KeyManager) : is_object($KeyManager)))) {
    $loggerInvoke('error', 'newsletter_confirm: KeyManager missing', null, []);
    return $makeResp(['error' => 'Interná chyba: KeyManager nie je dostupný.'], 500);
}

// Resolve Mailer and Crypto (prefer injected, fallback to global classes)
$MailerTarget = $resolveTarget($Mailer ?? null, 'Mailer');
$CryptoTarget  = $resolveTarget($Crypto ?? null, 'Crypto');

// --- read GET params ------------------------------------------------------
$selector = isset($_GET['selector']) ? trim((string)$_GET['selector']) : '';
$validatorHex = isset($_GET['validator']) ? trim((string)$_GET['validator']) : '';
if ($selector === '' || $validatorHex === '') {
    return $makeResp(['error' => 'Chýbajú potrebné parametre.'], 400);
}
if (!preg_match('/^[0-9a-fA-F]{12}$/', $selector) || !preg_match('/^[0-9a-fA-F]{64}$/', $validatorHex)) {
    return $makeResp(['error' => 'Neplatný token.'], 400);
}
$validatorBin = @hex2bin($validatorHex);
if ($validatorBin === false) {
    return $makeResp(['error' => 'Neplatný validator (hex).'], 400);
}

// --- transactional validation & confirm ----------------------------------
try {
    $pdo->beginTransaction();

    $q = $pdo->prepare('SELECT * FROM newsletter_subscribers WHERE confirm_selector = :sel LIMIT 1 FOR UPDATE');
    $q->bindValue(':sel', $selector, \PDO::PARAM_STR);
    $q->execute();
    $row = $q->fetch(\PDO::FETCH_ASSOC);

    if (!$row) {
        $pdo->rollBack();
        return $makeResp(['error' => 'Neplatný alebo už použitý token.'], 404);
    }

    // expiry check
    $nowUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    $expiresAt = null;
    if (!empty($row['confirm_expires'])) {
        $expiresAt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $row['confirm_expires'], new \DateTimeZone('UTC'));
        if (!$expiresAt) {
            $expiresAt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $row['confirm_expires'], new \DateTimeZone('UTC'));
        }
    }
    if ($expiresAt !== null && $nowUtc > $expiresAt) {
        $pdo->rollBack();
        return $makeResp(['error' => 'Potvrdzovací token expiroval. Požiadajte o nový potvrzovací e-mail.'], 410);
    }

    $storedValidatorHash = $row['confirm_validator_hash'] ?? null;
    if (empty($storedValidatorHash)) {
        $pdo->rollBack();
        return $makeResp(['error' => 'Neplatný token (chýba uložený hash).'], 400);
    }

    // derive candidate hashes (rotation aware) - CLEAR PATH (no password pepper, no generic sha256)
    $candidates = [];
    try {
        // preferred: derive multiple candidates (supports rotation)
        $v = $call($KeyManager, 'deriveHmacCandidates', ['EMAIL_VERIFICATION_KEY', $keysDir, 'email_verification_key', $validatorBin]);
        if (is_array($v) && !empty($v)) {
            $candidates = $v;
        } else {
            // try single-latest API
            $v2 = $call($KeyManager, 'deriveHmacWithLatest', ['EMAIL_VERIFICATION_KEY', $keysDir, 'email_verification_key', $validatorBin]);
            if (is_array($v2) && !empty($v2['hash'])) $candidates[] = $v2;
        }
    } catch (\Throwable $e) {
        $loggerInvoke('error', 'deriveHmacCandidates/withLatest failed on confirm', $row['user_id'] ?? null, ['ex' => (string)$e, 'row_id' => $row['id'] ?? null]);
    }

    // fallback: allow a direct "email verification key" raw lookup only (explicit)
    if (empty($candidates)) {
        try {
            $ev = $call($KeyManager, 'getEmailVerificationKeyInfo', [$keysDir]);
            if (!empty($ev) && !empty($ev['raw']) && is_string($ev['raw'])) {
                $h = hash_hmac('sha256', $validatorBin, $ev['raw'], true);
                $candidates[] = ['hash' => $h, 'version' => $ev['version'] ?? null];
                // zero raw asap
                $call($KeyManager, 'memzero', [$ev['raw']]);
            }
        } catch (\Throwable $e) {
            $loggerInvoke('error', 'getEmailVerificationKeyInfo failed on confirm', $row['user_id'] ?? null, ['ex' => (string)$e, 'row_id' => $row['id'] ?? null]);
        }
    }

    // if still empty -> configuration error: keys for email verification are not available
    if (empty($candidates)) {
        // defensive: memzero validatorBin and return 500
        $safeMemzero($validatorBin);
        $loggerInvoke('error', 'No email verification key available (no deriveHmacCandidates/withLatest/getEmailVerificationKeyInfo)', $row['user_id'] ?? null, ['subscriber_id' => $row['id'] ?? null]);
        $pdo->rollBack();
        return $makeResp(['error' => 'Interná chyba: kľúče pre validáciu tokenu nie sú dostupné.'], 500);
    }

    // constant-time compare
    $matched = false;
    $stored = (string)$storedValidatorHash;
    foreach ($candidates as $cand) {
        if (!isset($cand['hash'])) continue;
        $candHash = (string)$cand['hash'];
        if (strlen($stored) !== strlen($candHash)) continue;
        if (hash_equals($stored, $candHash)) { $matched = true; break; }
    }

    if (!$matched) {
        $pdo->rollBack();
        $call($KeyManager, 'memzero', [$validatorBin]);
        foreach ($candidates as $c) { if (isset($c['hash'])) $call($KeyManager, 'memzero', [$c['hash']]); }
        return $makeResp(['error' => 'Neplatný token alebo bol už použitý.'], 400);
    }

    // update DB: mark confirmed
    $upd = $pdo->prepare("UPDATE newsletter_subscribers
        SET confirmed_at = UTC_TIMESTAMP(6),
            confirm_validator_hash = NULL,
            confirm_key_version = NULL,
            confirm_expires = NULL,
            confirm_selector = NULL,
            updated_at = UTC_TIMESTAMP(6)
        WHERE id = :id");
    $upd->bindValue(':id', (int)$row['id'], \PDO::PARAM_INT);
    $upd->execute();

    $pdo->commit();

    // log success
    $loggerInvoke('systemMessage', 'Newsletter subscription confirmed', $row['user_id'] ?? null, ['subscriber_id' => (int)$row['id'], 'level' => 'notice']);

    // zero sensitive buffers
    try {
        if (function_exists('sodium_memzero')) {
            @sodium_memzero($validatorBin);
        } else {
            $validatorBin = str_repeat("\0", strlen($validatorBin));
        }
        unset($validatorBin);
    } catch (\Throwable $_) {}
    foreach ($candidates as $c) { if (isset($c['hash'])) $call($KeyManager, 'memzero', [$c['hash']]); }

// enqueue optional welcome mail (best-effort) — use MailHelper for payload composition
try {
    $decryptedEmail = null;

    if (!empty($row['email_enc']) && $CryptoTarget !== null) {
        $inited = false;
        try {
            // call init; some implementations return bool, some are void -> treat non-false as success
            $res = $call($CryptoTarget, 'initFromKeyManager', [$keysDir]);
            if ($res === false) {
                $inited = false;
            } else {
                // $res === null (void) or true -> treat as success
                $inited = true;
            }
        } catch (\Throwable $e) {
            $inited = false;
            $loggerInvoke('error', 'Crypto initFromKeyManager threw', $row['user_id'] ?? null, ['ex' => (string)$e, 'subscriber_id' => $row['id'] ?? null]);
        }

        if ($inited) {
            try {
                $decryptedEmail = $call($CryptoTarget, 'decrypt', [$row['email_enc']]);
            } catch (\Throwable $e) {
                $loggerInvoke('error', 'Crypto::decrypt threw', $row['user_id'] ?? null, ['ex' => (string)$e, 'subscriber_id' => $row['id'] ?? null]);
                $decryptedEmail = null;
            } finally {
                try { $call($CryptoTarget, 'clearKey', []); } catch (\Throwable $_) {}
            }
        } else {
            $loggerInvoke('warn', 'Crypto initFromKeyManager returned false or failed', $row['user_id'] ?? null, ['subscriber_id' => $row['id'] ?? null]);
        }
    }

    if ($decryptedEmail !== null && filter_var($decryptedEmail, FILTER_VALIDATE_EMAIL)) {
        // build payload via MailHelper if available
        if (class_exists(\BlackCat\Core\Helpers\MailHelper::class)) {
            $payload = \BlackCat\Core\Helpers\MailHelper::buildSubscribeNotificationPayload([
                'to' => $decryptedEmail,
                'subject' => 'Vitajte — potvrdenie odberu noviniek',
                'template' => 'newsletter_welcome',
                'vars' => [],
                'attachments' => [
                    [
                        'type' => 'inline_remote',
                        'src'  => 'https://knihyodautorov.sk/assets/logo.png',
                        'name' => 'logo.png',
                        'cid'  => 'logo'
                    ]
                ],
                'meta' => [
                    'subscriber_id' => (int)$row['id'],
                    'user_id' => $row['user_id'] ?? null,
                    'source' => 'newsletter_confirm_handler',
                ],
            ]);
        } else {
            $payload = [
                'to' => $decryptedEmail,
                'subject' => 'Vitajte — potvrdenie odberu noviniek',
                'template' => 'newsletter_welcome',
                'vars' => [],
                'attachments' => [
                    [
                        'type' => 'inline_remote',
                        'src'  => 'https://knihyodautorov.sk/assets/logo.png',
                        'name' => 'logo.png',
                        'cid'  => 'logo'
                    ]
                ],
                'meta' => ['subscriber_id' => (int)$row['id']],
            ];
        }

        if ($MailerTarget !== null) {
            try {
                $notifId = $call($MailerTarget, 'enqueue', [$payload]);
                if ($notifId === null) {
                    $loggerInvoke('warn', 'Mailer::enqueue returned null (no id)', $row['user_id'] ?? null, ['subscriber_id' => (int)$row['id']]);
                }
            } catch (\Throwable $e) {
                $loggerInvoke('error', 'Mailer::enqueue failed for welcome', $row['user_id'] ?? null, ['ex' => (string)$e, 'subscriber_id' => $row['id'] ?? null]);
            }
        } else {
            $loggerInvoke('warn', 'MailerTarget not available, welcome mail not enqueued', $row['user_id'] ?? null, ['subscriber_id' => (int)$row['id']]);
        }
    } else {
        $loggerInvoke('notice', 'Welcome mail not enqueued - no decrypted email or invalid email', $row['user_id'] ?? null, ['subscriber_id' => (int)$row['id']]);
    }
} catch (\Throwable $_) {
    // swallow — best-effort
}

    return $makeResp(['status' => 'success'], 200);

} catch (\Throwable $e) {
    try { if ($pdo && $pdo->inTransaction()) $pdo->rollBack(); } catch (\Throwable $_) {}
    $loggerInvoke('error', 'newsletter_confirm exception', null, ['ex' => (string)$e]);
    return $makeResp(['error' => 'Interná chyba.'], 500);
}
<?php
declare(strict_types=1);

/**
 * actions/verify.php
 *
 * Strict handler — používá výhradně předané shared proměnné (frontcontroller MUST pass).
 *
 * Required shared keys (frontcontroller MUST pass):
 *   - KeyManager, Logger, CSRF, db (PDO or wrapper), KEYS_DIR
 *
 * Response format: JSON only (respondJson)
 */

function respondJson(array $payload, int $status = 200): void {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    http_response_code($status);
    // keep same behaviour as register.php (adds CSRF token via static call)
    try { $payload['csrfToken'] = \BlackCat\Core\Security\CSRF::token();} catch (\Throwable $_) {}
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

// --- require that the frontcontroller injected these variables (strict, no fallbacks) ---
$required = ['KeyManager','Logger','CSRF','db','KEYS_DIR'];
$missing = [];
foreach ($required as $r) {
    if (!isset($$r)) $missing[] = $r;
}
if (!empty($missing)) {
    $msg = 'Interná konfigurácia chýba: ' . implode(', ', $missing) . '.';
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

$resolveTarget = function($injected) {
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

// --- obtain PDO from injected db (strict, NO fallbacks) ---
$pdo = null;
if (isset($db)) {
    if ($db instanceof \PDO) {
        $pdo = $db;
    } elseif (is_object($db) && method_exists($db, 'getPdo')) {
        $maybe = $db->getPdo();
        if ($maybe instanceof \PDO) $pdo = $maybe;
    }
}
if (!($pdo instanceof \PDO)) {
    $loggerInvoke('error', 'verify: PDO not available', null, []);
    respondJson(['success' => false, 'message' => 'Interná chyba (DB).'], 500);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method !== 'POST') {
    $selector = (string)($_GET['selector'] ?? '');
    $validatorHex = (string)($_GET['validator'] ?? '');

    // základní validace formátu (bez hex2bin)
    if ($selector === '' || !ctype_xdigit($selector)) {
        $viewData = ['status'=>'invalid','message'=>'Invalid selector.','selector'=>$selector,'csrfToken'=>$csrfToken];
        return ['template'=>'pages/verify.php','vars'=>$viewData];
    }

    // SELECT z DB (read-only) — stejný SQL jako v POST, ale bez UPDATE
    $sql = "SELECT ev.id AS ev_id, ev.user_id, ev.expires_at, u.is_active
            FROM email_verifications ev
            JOIN pouzivatelia u ON u.id = ev.user_id
            WHERE ev.selector = :selector
                AND ev.validator_hash IS NOT NULL
            LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':selector', $selector, \PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);

    if (!$row) {
        $viewData = ['status'=>'not_found_or_used','message'=>'Verification token not found or already used.','selector'=>$selector,'csrfToken'=>$csrfToken];
        return ['template'=>'pages/verify.php','vars'=>$viewData];
    }

    // expiry check (read-only)
    try {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $exp = new \DateTimeImmutable($row['expires_at'], new \DateTimeZone('UTC'));
        if ($exp < $now) {
            $viewData = ['status'=>'expired','message'=>'Verification token expired.','selector'=>$selector,'expires_at'=>$row['expires_at'],'csrfToken'=>$csrfToken];
            return ['template'=>'pages/verify.php','vars'=>$viewData];
        }
    } catch (\Throwable $_) {
        $viewData = ['status'=>'error','message'=>'Internal server error.','selector'=>$selector,'csrfToken'=>$csrfToken];
        return ['template'=>'pages/verify.php','vars'=>$viewData];
    }
    if ($validatorHex !== '' && ctype_xdigit($validatorHex) && strlen($validatorHex) === 64) {
    // ulož token do session, indexovaný podle selector
    if ($selector !== '') {
        $_SESSION['email_verification'] = $_SESSION['email_verification'] ?? [];
        // krátkodobě uložit spolu s timestampem (pro případné čištění)
        $_SESSION['email_verification'][$selector] = [
            'validator_hex' => $validatorHex,
            'ts' => time(),
        ];
    }
    }
    // připrav data pro šablonu (NEposílat validator!)
    $viewData = [
        'status'     => 'ready',
        'selector'   => $selector,
        'is_active'  => (int)($row['is_active'] ?? 0),
        'expires_at' => $row['expires_at'] ?? null,
        'csrfToken'  => $csrfToken,
    ];

    return ['template' => 'pages/verify.php', 'vars' => $viewData];
} else {
    // POST: načteme tokeny z těla požadavku
    $selector = (string)($_POST['selector'] ?? '');
    if (!empty($_SESSION['email_verification'][$selector]['validator_hex'])) {
        $validatorHex = (string)$_SESSION['email_verification'][$selector]['validator_hex'];
        // jednorázové použití: vymažeme z session
        unset($_SESSION['email_verification'][$selector]);
        // optionally: remove container if empty
        if (empty($_SESSION['email_verification'])) {
            unset($_SESSION['email_verification']);
        }
    }
}
// POST path: validate CSRF and run full verification + DB updates -> respondJson(...)
$csrfTokenFromPost = $_POST['csrf'] ?? null;
$csrfValid = $call($CSRF, 'validate', [$csrfTokenFromPost]);
if ($csrfValid !== true) {
    respondJson(['success'=>false,'errors'=>['csrf'=>'Neplatný CSRF token.']], 400);
}

if ($selector === '' || !ctype_xdigit($selector)) {
    respondJson(['success' => false, 'status' => 'invalid', 'message' => 'Invalid selector/validator.'], 400);
}
if ($validatorHex === '' || !ctype_xdigit($validatorHex) || strlen($validatorHex) !== 64) {
    respondJson(['success' => false, 'status' => 'invalid', 'message' => 'Invalid selector/validator.'], 400);
}

$validator = @hex2bin($validatorHex);
if ($validator === false || strlen($validator) !== 32) {
    respondJson(['success' => false, 'status' => 'invalid', 'message' => 'Invalid selector/validator.'], 400);
}

// --- main verification flow ---
try {
    $sql = "SELECT ev.id AS ev_id, ev.user_id, ev.token_hash, ev.validator_hash, ev.expires_at,
                   u.is_active, x.confirm_selector, x.confirm_validator_hash, x.confirm_key_version,
                   x.confirm_expires, x.confirmed_at, x.unsubscribed_at
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
    respondJson(['success' => false, 'status' => 'not_found', 'message' => 'Verification token not found.'], 404);
    }
    if ((int)($row['is_active'] ?? 0) === 1) {
    $loggerInvoke('verify', 'verify_failure', (int)$row['user_id'], ['reason' => 'already_active', 'selector' => $selector]);
    respondJson(['success' => true, 'status'  => 'already_active', 'message' => 'Account already active.'], 200);
    }
    // check expiry (UTC)
    $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    try {
        $exp = new \DateTimeImmutable($row['expires_at'], new \DateTimeZone('UTC'));
        if ($exp < $now) {
            $loggerInvoke('verify', 'verify_failure', (int)$row['user_id'], ['reason' => 'token_expired', 'selector' => $selector]);
            respondJson(['success' => false, 'status' => 'expired', 'message' => 'Verification token expired.'], 410);
        }
    } catch (\Throwable $_) {
        $loggerInvoke('error', 'Invalid expires_at format for email_verifications id=' . ($row['ev_id'] ?? ''), $row['user_id'] ?? null, []);
        respondJson(['success' => false, 'status' => 'error', 'message' => 'Internal server error.'], 500);
    }

    // 1) token hash check: hash('sha256', validator) => hex string
    $calcTokenHex = hash('sha256', $validator);
    $dbTokenHash = (string)($row['token_hash'] ?? '');

    if (!hash_equals($dbTokenHash, $calcTokenHex)) {
        // mismatch -> invalid
        $loggerInvoke('verify', 'verify_failure', (int)$row['user_id'], ['reason' => 'token_mismatch', 'selector' => $selector]);
        respondJson(['success' => false, 'status' => 'invalid', 'message' => 'Invalid verification token.'], 400);
    }

    // 2) optional validator_hash (HMAC) check (if DB contains it)
    $dbValidatorHash = $row['validator_hash'] ?? null;
    $validatorHashOk = true;
    if (!empty($dbValidatorHash)) {
        $validatorHashOk = false;
        try {
            // use injected KeyManager to derive HMAC candidates (rotation-aware)
            $cands = $call($KeyManager, 'deriveHmacCandidates', ['EMAIL_VERIFICATION_KEY', $KEYS_DIR, 'email_verification_key', $validator]);
            if (!empty($cands) && is_array($cands)) {
                foreach ($cands as $cand) {
                    if (!isset($cand['hash'])) continue;
                    if (is_string($dbValidatorHash) && is_string($cand['hash']) && hash_equals($dbValidatorHash, $cand['hash'])) {
                        $validatorHashOk = true;
                        break;
                    }
                }
            }
        } catch (\Throwable $e) {
            $loggerInvoke('error', 'KeyManager deriveHmacCandidates failed during verification', $row['user_id'] ?? null, ['ex' => (string)$e]);
        }

        if (!$validatorHashOk) {
            $loggerInvoke('verify', 'verify_failure', (int)$row['user_id'], ['reason' => 'validator_mismatch', 'selector' => $selector]);
            respondJson(['success' => false, 'status' => 'invalid', 'message' => 'Validator HMAC mismatch.'], 400);
        }
    }

    $newsletter = 0;

    // all checks passed: activate account and invalidate token in single transaction
    try {
        $pdo->beginTransaction();

        $updUser = $pdo->prepare('UPDATE pouzivatelia SET is_active = 1, updated_at = UTC_TIMESTAMP() WHERE id = :uid');
        $updUser->bindValue(':uid', (int)$row['user_id'], \PDO::PARAM_INT);
        $updUser->execute();

        // invalidate token: set used_at and clear sensitive fields
        $updToken = $pdo->prepare('UPDATE email_verifications
                                  SET used_at = UTC_TIMESTAMP(), token_hash = NULL, validator_hash = NULL, key_version = NULL
                                  WHERE id = :id');
        $updToken->bindValue(':id', (int)$row['ev_id'], \PDO::PARAM_INT);
        $updToken->execute();

        // if newsletter_subscribers record exists and was unconfirmed, mark confirmed
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
        $loggerInvoke('systemError', 'Failed to update account during verification', $row['user_id'] ?? null, ['ex' => (string)$e]);
        respondJson(['success' => false, 'status' => 'error', 'message' => 'Internal server error while updating account.', 'newsletter' => (int)$newsletter], 500);
    }

    // logging success
    $_SESSION['verify_success'] = true;
    $loggerInvoke('verify', 'verify_success', (int)$row['user_id'], ['selector' => $selector]);
    respondJson(['success' => true, 'status' => 'success', 'message' => 'Email verified successfully.', 'newsletter' => (int)$newsletter], 200);

} catch (\Throwable $e) {
    if (isset($pdo) && $pdo instanceof \PDO && $pdo->inTransaction()) {
        try { $pdo->rollBack(); } catch (\Throwable $_) {}
    }
    $loggerInvoke('systemError', 'verify exception', null, ['ex' => (string)$e]);
    respondJson(['success' => false, 'status' => 'error', 'message' => 'Internal server error.'], 500);
}
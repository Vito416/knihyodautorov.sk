<?php
declare(strict_types=1);

/**
 * actions/password_reset_confirm.php
 *
 * Strict JSON handler — dependency-injected.
 *
 * Required injected variables (frontcontroller MUST pass):
 *   - KeyManager, Logger, CSRF, db (PDO or wrapper), Validator, KEYS_DIR
 *
 * Response: JSON only via respondJson()
 */

function respondJson(array $payload, int $status = 200): void {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    http_response_code($status);
    // add refreshed CSRF token same way as verify.php
    try { $payload['csrfToken'] = \BlackCat\Core\Security\CSRF::token(); } catch (\Throwable $_) {}
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

// --- require injected dependencies (strict, no fallbacks) ---
$required = ['KeyManager','Logger','CSRF','db','Validator','KEYS_DIR'];
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

// helpers (same pattern as verify.php)
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
    $loggerInvoke('error', 'password_reset_confirm: PDO not available', null, []);
    respondJson(['success' => false, 'message' => 'Interná chyba (DB).'], 500);
}

// METHOD and inputs
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$selector = (string)($_GET['selector'] ?? $_POST['selector'] ?? '');
$validatorHex = (string)($_GET['validator'] ?? $_POST['validator'] ?? '');

// format validation (no hex2bin yet)
if ($selector === '' || !ctype_xdigit($selector)) {
    respondJson(['success'=>false,'status'=>'invalid','message'=>'Invalid selector.'], 400);
}
if ($validatorHex !== '' && (!ctype_xdigit($validatorHex) || strlen($validatorHex) !== 64)) {
    // allow empty validatorHex on GET but if present must be valid
    respondJson(['success'=>false,'status'=>'invalid','message'=>'Invalid validator.'], 400);
}

// GET: readonly checks (do not allow POST if token invalid)
// use same SELECT pattern as verify (require validator_hash NOT NULL so we don't proceed when DB missing)
try {
    if ($method !== 'POST') {
        $sql = "SELECT ev.id AS ev_id, ev.user_id, ev.expires_at, ev.used_at, u.is_active, u.is_locked
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
            respondJson(['success'=>false,'status'=>'not_found_or_used','message'=>'Token not found or already used.'], 404);
        }

        // expiry check (read-only)
        try {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $exp = new \DateTimeImmutable($row['expires_at'], new \DateTimeZone('UTC'));
            if ($exp < $now) {
                respondJson(['success'=>false,'status'=>'expired','message'=>'Token expired.','expires_at'=>$row['expires_at']], 410);
            }
        } catch (\Throwable $_) {
            $loggerInvoke('error', 'Invalid expires_at format for email_verifications id=' . ($row['ev_id'] ?? ''), $row['user_id'] ?? null, []);
            respondJson(['success'=>false,'status'=>'error','message'=>'Internal server error.'], 500);
        }

        // store validator_hex in session if provided (one-time)
        if ($validatorHex !== '' && ctype_xdigit($validatorHex) && strlen($validatorHex) === 64) {
            $_SESSION['email_verification'] = $_SESSION['email_verification'] ?? [];
            $_SESSION['email_verification'][$selector] = [
                'validator_hex' => $validatorHex,
                'ts' => time(),
            ];
        }

        // ready to POST confirm (but do not return validator)
        respondJson([
            'success' => true,
            'status' => 'ready',
            'selector' => $selector,
            'is_active' => (int)($row['is_active'] ?? 0),
            'is_locked' => (int)($row['is_locked'] ?? 0),
            'expires_at' => $row['expires_at'] ?? null,
        ], 200);
    }

    // ---------------- POST path ----------------
    // read selector (strict)
    $selector = (string)($_POST['selector'] ?? '');
    if ($selector === '' || !ctype_xdigit($selector)) {
        respondJson(['success'=>false,'status'=>'invalid','message'=>'Invalid selector.'], 400);
    }

    // try fetch validator_hex from session (preferred), otherwise require POST to include it
    if (!empty($_SESSION['email_verification'][$selector]['validator_hex'])) {
        $validatorHex = (string)$_SESSION['email_verification'][$selector]['validator_hex'];
        // one-time use: unset
        unset($_SESSION['email_verification'][$selector]);
        if (empty($_SESSION['email_verification'])) unset($_SESSION['email_verification']);
    } else {
        $validatorHex = (string)($_POST['validator'] ?? '');
    }

    if ($validatorHex === '' || !ctype_xdigit($validatorHex) || strlen($validatorHex) !== 64) {
        respondJson(['success'=>false,'status'=>'invalid','message'=>'Missing or invalid validator.'], 400);
    }

    $validator = @hex2bin($validatorHex);
    if ($validator === false || strlen($validator) !== 32) {
        respondJson(['success'=>false,'status'=>'invalid','message'=>'Invalid validator format.'], 400);
    }

    // CSRF validate
    $csrfTokenFromPost = $_POST['csrf'] ?? null;
    $csrfValid = $call($CSRF, 'validate', [$csrfTokenFromPost]);
    if ($csrfValid !== true) {
        respondJson(['success'=>false,'errors'=>['csrf'=>'Neplatný CSRF token.']], 400);
    }

    // fetch DB record (full checks)
    $sql = "SELECT ev.id AS ev_id, ev.user_id, ev.token_hash, ev.validator_hash, ev.expires_at, ev.used_at,
                   u.is_active, u.is_locked
            FROM email_verifications ev
            JOIN pouzivatelia u ON u.id = ev.user_id
            WHERE ev.selector = :selector
            LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':selector', $selector, \PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);

    if (!$row) {
        respondJson(['success'=>false,'status'=>'not_found','message'=>'Token not found.'], 404);
    }

    if (!empty($row['used_at'])) {
        respondJson(['success'=>false,'status'=>'already_used','message'=>'Token already used.'], 400);
    }

    // expiry check
    $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    try {
        $exp = new \DateTimeImmutable($row['expires_at'], new \DateTimeZone('UTC'));
        if ($exp < $now) {
            respondJson(['success'=>false,'status'=>'expired','message'=>'Token expired.'], 410);
        }
    } catch (\Throwable $_) {
        $loggerInvoke('error', 'Invalid expires_at format for email_verifications id=' . ($row['ev_id'] ?? ''), $row['user_id'] ?? null, []);
        respondJson(['success'=>false,'status'=>'error','message'=>'Internal server error.'], 500);
    }

    // 1) token_hash check: hex sha256(validator) equals token_hash in DB
    $calcTokenHex = hash('sha256', $validator);
    $dbTokenHash = (string)($row['token_hash'] ?? '');
    if (!hash_equals($dbTokenHash, $calcTokenHex)) {
        $loggerInvoke('verify', 'password_reset_confirm: token mismatch', (int)$row['user_id'], ['selector' => $selector]);
        respondJson(['success'=>false,'status'=>'invalid','message'=>'Invalid token.'], 400);
    }

    // 2) validator_hash (HMAC) check — require KeyManager candidates to match (no fallback)
    $dbValidatorHash = $row['validator_hash'] ?? null;
    if (empty($dbValidatorHash)) {
        // for safety, require validator_hash to exist; if not, reject (consistent with verify SELECT)
        $loggerInvoke('error', 'password_reset_confirm: validator_hash missing in DB', (int)$row['user_id'], ['ev_id' => $row['ev_id'] ?? null]);
        respondJson(['success'=>false,'status'=>'invalid','message'=>'Invalid token.'], 400);
    }

    $validatorHashOk = false;
    try {
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
        $loggerInvoke('error', 'KeyManager deriveHmacCandidates failed during password_reset_confirm', (int)$row['user_id'], ['ex' => (string)$e]);
    }

    if (!$validatorHashOk) {
        $loggerInvoke('verify', 'password_reset_confirm: validator HMAC mismatch', (int)$row['user_id'], ['selector' => $selector]);
        respondJson(['success'=>false,'status'=>'invalid','message'=>'Validator HMAC mismatch.'], 400);
    }

    // token ok. Now: if POST does not include password fields -> instruct client to show password form (ready_for_password)
    $password = isset($_POST['password']) ? (string)$_POST['password'] : '';
    $password2 = isset($_POST['password2']) ? (string)$_POST['password2'] : '';

    if ($password === '' && $password2 === '') {
        respondJson(['success'=>true,'status'=>'ready_for_password','selector'=>$selector], 200);
    }

    // process password change
    if ($password === '' || $password !== $password2) {
        respondJson(['success'=>false,'status'=>'form_error','message'=>'Hesla se neshodují nebo jsou prázdná.'], 400);
    }

    // validate strength via injected Validator (method passwordStrong)
    $pwOk = $call($Validator, 'passwordStrong', [$password, 10]);
    if ($pwOk !== true) {
        respondJson(['success'=>false,'status'=>'form_error','message'=>'Heslo nie je dostatočne silné (min. 10 znakov).'], 400);
    }

    // ------------------ REQUIRE pepper (FAIL FAST) ------------------
    $pepRaw = null;
    $pepVer = null;
    try {
        $pinfo = $call($KeyManager, 'getPasswordPepperInfo', [$KEYS_DIR]);
        if (!is_array($pinfo) || empty($pinfo['raw']) || !is_string($pinfo['raw']) || strlen($pinfo['raw']) !== 32) {
            throw new \RuntimeException('PASSWORD_PEPPER missing or invalid');
        }
        $pepRaw = $pinfo['raw'];
        $pepVer = $pinfo['version'] ?? null;
    } catch (\Throwable $e) {
        // fatal: do not continue without pepper
        $loggerInvoke('systemError', 'password_reset_confirm: PASSWORD_PEPPER missing/invalid', (int)$row['user_id'], ['ex' => (string)$e]);
        respondJson(['success'=>false,'status'=>'error','message'=>'Interná chyba.'], 500);
    }

    // pre-hash and Argon2id (pepper guaranteed present)
    try {
        $pwPre = hash_hmac('sha256', $password, $pepRaw, true);

        $hash = false;
        if (defined('PASSWORD_ARGON2ID')) {
            $opts = [
                'memory_cost' => (1 << 16),
                'time_cost' => 4,
                'threads' => 2,
            ];
            $hash = password_hash($pwPre, PASSWORD_ARGON2ID, $opts);
        }
        if ($hash === false) {
            $hash = password_hash($pwPre, PASSWORD_DEFAULT);
        }
    } catch (\Throwable $e) {
        $loggerInvoke('systemError', $e instanceof \Throwable ? (string)$e : 'password hash failed', (int)$row['user_id'], []);
        respondJson(['success'=>false,'status'=>'error','message'=>'Internal server error.'], 500);
    } finally {
        if (isset($pwPre)) { try { $safeMemzero($pwPre); } catch (\Throwable $_) {} }
        if (isset($pepRaw) && is_string($pepRaw)) { try { $call($KeyManager, 'memzero', [&$pepRaw]); } catch (\Throwable $_) {} }
    }

    // write in transaction: update user password + invalidate token
    try {
        $pdo->beginTransaction();

        $updUser = $pdo->prepare("UPDATE pouzivatelia
                                 SET heslo_hash = :hash, heslo_key_version = :key_ver, must_change_password = 0, updated_at = UTC_TIMESTAMP(6)
                                 WHERE id = :uid");
        $updUser->bindValue(':hash', $hash, \PDO::PARAM_STR);
        $updUser->bindValue(':key_ver', $pepVer, $pepVer !== null ? \PDO::PARAM_STR : \PDO::PARAM_NULL);
        $updUser->bindValue(':uid', (int)$row['user_id'], \PDO::PARAM_INT);
        $updUser->execute();

        $updToken = $pdo->prepare("UPDATE email_verifications
                                  SET used_at = UTC_TIMESTAMP(6), token_hash = NULL, validator_hash = NULL, key_version = NULL
                                  WHERE id = :id");
        $updToken->bindValue(':id', (int)$row['ev_id'], \PDO::PARAM_INT);
        $updToken->execute();

        $pdo->commit();

        // log and flag session
        $_SESSION['password_reset_success'] = true;
        $loggerInvoke('systemMessage', 'password_reset_success', (int)$row['user_id'], ['ev_id' => $row['ev_id'] ?? null]);

        // memzero validator
        try { $safeMemzero($validator); } catch (\Throwable $_) {}

        respondJson(['success'=>true,'status'=>'success','message'=>'Password reset successful.'], 200);
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) { try { $pdo->rollBack(); } catch (\Throwable $_) {} }
        $loggerInvoke('systemError', 'Failed to update password during reset', (int)$row['user_id'], ['ex' => (string)$e]);
        respondJson(['success'=>false,'status'=>'error','message'=>'Internal server error.'], 500);
    }

} catch (\Throwable $e) {
    if (isset($pdo) && $pdo instanceof \PDO && $pdo->inTransaction()) {
        try { $pdo->rollBack(); } catch (\Throwable $_) {}
    }
    $loggerInvoke('systemError', 'password_reset_confirm exception', null, ['ex' => (string)$e]);
    respondJson(['success'=>false,'status'=>'error','message'=>'Internal server error.'], 500);
}
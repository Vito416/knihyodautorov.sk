<?php
declare(strict_types=1);

/**
 * actions/password_reset.php
 *
 * Strict JSON handler — dependency-injected.
 *
 * Required injected variables (frontcontroller MUST pass):
 *   - KeyManager, Logger, Validator, CSRF, db (PDO or wrapper), Mailer, MailHelper, KEYS_DIR
 *
 * Response: JSON only via respondJson()
 */

function respondJson(array $payload, int $status = 200): void {
    if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
    http_response_code($status);
    // keep same behaviour as other actions: try adding CSRF token if global static exists
    try { $payload['csrfToken'] = \BlackCat\Core\Security\CSRF::token(); } catch (\Throwable $_) {}
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

// --- require injected dependencies (strict) ---
$required = ['KeyManager','Logger','Validator','CSRF','db','Mailer','MailHelper','KEYS_DIR'];
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

// helpers (same style as register/verify)
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

// helper: anonymize email for logs
$logEmailHint = static function(string $e): string {
    $e = trim($e);
    if ($e === '') return '';
    $p = explode('@', $e);
    if (count($p) !== 2) return '***';
    $local = $p[0];
    $domain = $p[1];
    $shown = mb_substr($local, 0, 1, 'UTF-8') ?: '*';
    return $shown . str_repeat('*', max(0, min(6, mb_strlen($local, 'UTF-8') - 1))) . '@' . $domain;
};

$genericUserMessage = 'Pokud e-mail existuje, poslali jsme odkaz na jeho obnovu.';

// obtain PDO strictly from injected db
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
    $loggerInvoke('error', 'password_reset: PDO not available', null, []);
    // still respond neutrally so attacker learns nothing
    respondJson(['success' => true, 'message' => $genericUserMessage], 200);
}

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
        'template' => 'pages/password_reset.php',
        'vars' => [
            'pageTitle' => 'Obnovenie hesla',
            // strict token (plain string) passed to template
            'csrfToken' => $csrfToken,
        ],
    ];
}

// POST path
$emailRaw = (string)($_POST['email'] ?? '');
$email = trim(mb_strtolower($emailRaw, 'UTF-8'));

// sanitize via injected Validator (strict)
$sanitized = $call($Validator, 'stringSanitized', [$email, 512]);
if (!is_string($sanitized)) {
    $loggerInvoke('warn', 'password_reset: Validator::sanitizeString missing/failed', null, ['email_hint' => $logEmailHint($email)]);
    respondJson(['success' => true, 'message' => $genericUserMessage], 200);
}
$email = $sanitized;

// CSRF validation (strict)
$csrfTokenFromPost = $_POST['csrf'] ?? null;
$csrfValid = $call($CSRF, 'validate', [$csrfTokenFromPost]);
if ($csrfValid !== true) {
    respondJson(['success' => false, 'errors' => ['csrf' => 'Neplatný CSRF token.']], 400);
}

// validate email format
$validEmail = $call($Validator, 'email', [$email]);
if ($validEmail !== true) {
    // neutral response
    respondJson(['success' => true, 'message' => $genericUserMessage], 200);
}

// derive HMAC candidates (rotation-aware) via KeyManager (strict — no global fallbacks)
$emailHashes = [];
try {
    $candidates = $call($KeyManager, 'deriveHmacCandidates', ['EMAIL_HASH_KEY', $KEYS_DIR, 'email_hash_key', $email]);
    if (is_array($candidates) && !empty($candidates)) {
        $seen = [];
        foreach ($candidates as $c) {
            if (!isset($c['hash'])) continue;
            $hex = bin2hex($c['hash']);
            if (isset($seen[$hex])) continue;
            $seen[$hex] = true;
            $emailHashes[] = $c['hash'];
        }
    } else {
        $loggerInvoke('warn', 'password_reset: KeyManager::deriveHmacCandidates returned no candidates', null, ['email_hint' => $logEmailHint($email)]);
    }
} catch (\Throwable $e) {
    $loggerInvoke('error', 'deriveHmacCandidates failed (password_reset)', null, ['exception' => (string)$e, 'email_hint' => $logEmailHint($email)]);
}

// lookup user only if we have candidate hashes
$user = null;
if (!empty($emailHashes)) {
    try {
        $placeholders = implode(',', array_fill(0, count($emailHashes), '?'));
        $sql = 'SELECT id, is_active, is_locked FROM pouzivatelia WHERE email_hash IN (' . $placeholders . ') LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $i = 1;
        foreach ($emailHashes as $h) {
            $stmt->bindValue($i++, $h, \PDO::PARAM_LOB);
        }
        $stmt->execute();
        $user = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    } catch (\Throwable $e) {
        $loggerInvoke('error', 'User lookup failed (password_reset)', null, ['exception' => (string)$e, 'email_hint' => $logEmailHint($email)]);
        $user = null;
    }
}

// if user found and active/unlocked => create reset token, store and enqueue
if ($user && (int)$user['is_active'] === 1 && (int)$user['is_locked'] === 0) {
    $uid = (int)$user['id'];
    try {
        $selector = bin2hex(random_bytes(6));
        $validator = random_bytes(32);
        $validatorHex = bin2hex($validator);
        $tokenHashHex = hash('sha256', $validator);

        // try derive validator_hash via KeyManager (prefer HMAC). Local fallback to binary sha256 only if derivation fails.
        $validatorHashBin = null;
        $validatorKeyVer = null;
        try {
            $vinfo = $call($KeyManager, 'deriveHmacWithLatest', ['EMAIL_VERIFICATION_KEY', $KEYS_DIR, 'email_verification_key', $validator]);
            if (is_array($vinfo) && !empty($vinfo['hash'])) {
                $validatorHashBin = $vinfo['hash'];
                $validatorKeyVer = $vinfo['version'] ?? null;
            } else {
                $ev = $call($KeyManager, 'getEmailVerificationKeyInfo', [$KEYS_DIR]);
                if (is_array($ev) && !empty($ev['raw']) && is_string($ev['raw'])) {
                    $validatorHashBin = hash_hmac('sha256', $validator, $ev['raw'], true);
                    $validatorKeyVer = $ev['version'] ?? null;
                    try { $call($KeyManager, 'memzero', [&$ev['raw']]); } catch (\Throwable $_) {}
                }
            }
        } catch (\Throwable $e) {
            $loggerInvoke('error', 'derive validator hash failed (password_reset)', $uid, ['exception' => (string)$e]);
            // local fallback to binary sha256 to ensure usability (keeps leak resistance)
            $validatorHashBin = hash('sha256', $validator, true);
            $validatorKeyVer = null;
        }

        $expiresAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+1 hour')->format('Y-m-d H:i:s.u');

        // insert token
        $ins = $pdo->prepare("INSERT INTO email_verifications
            (user_id, token_hash, selector, validator_hash, key_version, expires_at, created_at)
            VALUES (:uid, :token_hash, :selector, :validator_hash, :key_version, :expires_at, UTC_TIMESTAMP(6))");
        $ins->bindValue(':uid', $uid, \PDO::PARAM_INT);
        $ins->bindValue(':token_hash', $tokenHashHex, \PDO::PARAM_STR);
        $ins->bindValue(':selector', $selector, \PDO::PARAM_STR);
        $ins->bindValue(':validator_hash', $validatorHashBin, \PDO::PARAM_LOB);
        $ins->bindValue(':key_version', $validatorKeyVer, $validatorKeyVer !== null ? \PDO::PARAM_STR : \PDO::PARAM_NULL);
        $ins->bindValue(':expires_at', $expiresAt, \PDO::PARAM_STR);
        $ins->execute();
    } catch (\Throwable $e) {
        $loggerInvoke('systemError', 'Failed to store password reset token', $uid ?? null, ['ex' => (string)$e]);
        // swallow and continue to generic response
    }

    // build enqueue payload — prefer MailHelper if it provides a builder method
    try {
        $payload = null;
        $mhTarget = $resolveTarget($MailHelper);
        if ($mhTarget !== null && (method_exists($mhTarget, 'passwordResetPayload') || method_exists($mhTarget, 'buildPasswordResetPayload'))) {
            // try common names; allow static or object
            if (method_exists($mhTarget, 'passwordResetPayload')) {
                $payload = $call($MailHelper, 'passwordResetPayload', [
                    'user_id' => $uid,
                    'to' => $email,
                    'selector' => $selector,
                    'validator_hex' => $validatorHex,
                ]);
            } else {
                $payload = $call($MailHelper, 'buildPasswordResetPayload', [
                    'user_id' => $uid,
                    'to' => $email,
                    'selector' => $selector,
                    'validator_hex' => $validatorHex,
                ]);
            }
        }

        if (!is_array($payload)) {
            // fallback: build minimal payload
            $base = rtrim((string)($_ENV['APP_URL'] ?? ($_ENV['BASE_URL'] ?? '')), '/');
            $resetUrl = $base . '/password_reset_confirm?selector=' . rawurlencode($selector) . '&validator=' . rawurlencode($validatorHex);
            $payload = [
                'user_id' => $uid,
                'to' => $email,
                'subject' => 'Obnovenie hesla',
                'template' => 'password_reset',
                'vars' => [
                    'reset_url' => $resetUrl,
                    'site' => $_SERVER['SERVER_NAME'] ?? null,
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
                    'email_hash_key_version' => $candidates[0]['version'] ?? null,
                    'validator_key_version' => $validatorKeyVer ?? null,
                    'source' => 'password_reset',
                    'remote_ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                ],
            ];
        }

        $notifId = $call($Mailer, 'enqueue', [$payload]);
    } catch (\Throwable $e) {
        $loggerInvoke('error', 'Mailer enqueue failed (password_reset)', $uid ?? null, ['exception' => (string)$e, 'email_hint' => $logEmailHint($email)]);
        // swallow
    }

    // memzero sensitive buffers
    try { $safeMemzero($validator); } catch (\Throwable $_) {}
    try { $safeMemzero($validatorHashBin); } catch (\Throwable $_) {}
    try { foreach ($emailHashes as $h) { $safeMemzero($h); } } catch (\Throwable $_) {}
}

// Always return neutral message
respondJson(['success' => true, 'message' => $genericUserMessage], 200);
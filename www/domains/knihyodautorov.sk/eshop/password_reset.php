<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';

/**
 * password_reset.php
 *
 * Krok 1: Užívateľ zadá e-mail. Bezpečnost: vždy generická odpoveď.
 *
 * - Validuje email přes Validator
 * - Hledá uživatele podle email_hash (KeyManager derive HMAC candidates)
 * - Pokud existuje a je aktívny/není zablokovaný => vytvoří token, uloží do email_verifications a naplánuje e-mail přes Mailer::enqueue
 * - Citlivé buffery jsou memzero'd
 */

// kritické závislosti
$required = ['KeyManager', 'Logger', 'Validator'];
$missing = [];
foreach ($required as $c) {
    if (!class_exists($c)) $missing[] = $c;
}
if (!empty($missing)) {
    $msg = 'Interná chyba: chýbajú knižnice: ' . implode(', ', $missing) . '.';
    if (class_exists('Logger')) { try { Logger::systemError(new \RuntimeException($msg)); } catch (\Throwable $_) {} }
    http_response_code(500);
    echo Templates::render('pages/error.php', ['message' => 'Interná chyba. Kontaktujte administrátora.']);
    exit;
}

if (!defined('KEYS_DIR') || !is_string(KEYS_DIR) || KEYS_DIR === '') {
    $msg = 'Interná chyba: KEYS_DIR nie je nastavený. Skontrolujte konfiguráciu kľúčov.';
    try { Logger::systemError(new \RuntimeException($msg)); } catch (\Throwable $_) {}
    http_response_code(500);
    echo Templates::render('pages/error.php', ['message' => 'Interná chyba. Kontaktujte administrátora.']);
    exit;
}

// pomocný anonymizační hint pro logy
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

// POST handling
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $emailRaw = (string)($_POST['email'] ?? '');
    $email = trim(mb_strtolower($emailRaw, 'UTF-8'));
    // basic sanitize via Validator if available
    $email = Validator::sanitizeString($email, 512);

    // Always show the same message to the user; only proceed internally if email is valid
    if (!Validator::validateEmail($email)) {
        // render form with neutral message (not exposing whether email exists)
        echo Templates::render('pages/password_reset_sent.php', ['email' => $emailRaw, 'message' => $genericUserMessage]);
        exit;
    }

    // obtain PDO
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
        try { Logger::systemError($e); } catch (\Throwable $_) {}
        // still show generic message
        echo Templates::render('pages/password_reset_sent.php', ['email' => $emailRaw, 'message' => $genericUserMessage]);
        exit;
    }

    // derive HMAC candidates for email (support key rotation)
    $candidates = [];
    try {
        if (method_exists('KeyManager', 'deriveHmacCandidates')) {
            $candidates = KeyManager::deriveHmacCandidates('EMAIL_HASH_KEY', KEYS_DIR, 'email_hash_key', $email);
        } else {
            // best-effort single latest
            if (method_exists('KeyManager', 'deriveHmacWithLatest')) {
                $v = KeyManager::deriveHmacWithLatest('EMAIL_HASH_KEY', KEYS_DIR, 'email_hash_key', $email);
                if (!empty($v['hash'])) $candidates[] = $v;
            }
        }
    } catch (\Throwable $e) {
        try { Logger::error('deriveHmacCandidates failed (password_reset)', null, ['exception' => (string)$e, 'email_hint' => $logEmailHint($email)]); } catch (\Throwable $_) {}
        // proceed — fallback to SHA256 hash as last resort (non-ideal, but allows lookup if app used that)
        $candidates = [];
    }

    // produce array of binary hashes (dedup by hex)
    $emailHashes = [];
    $seen = [];
    foreach ($candidates as $c) {
        if (!isset($c['hash'])) continue;
        $hex = bin2hex($c['hash']);
        if (isset($seen[$hex])) continue;
        $seen[$hex] = true;
        $emailHashes[] = $c['hash'];
    }

    // fallback: if no candidates, try sha256 binary (best-effort)
    if (empty($emailHashes)) {
        try {
            $emailHashes[] = hash('sha256', $email, true);
            try { Logger::warn('password_reset: using sha256 fallback for email lookup', null, ['email_hint' => $logEmailHint($email)]); } catch (\Throwable $_) {}
        } catch (\Throwable $_) {
            // ignore
        }
    }

    // lookup user via IN(...)
    $user = null;
    try {
        if (!empty($emailHashes)) {
            $placeholders = implode(',', array_fill(0, count($emailHashes), '?'));
            $sql = 'SELECT id, is_active, is_locked FROM pouzivatelia WHERE email_hash IN (' . $placeholders . ') LIMIT 1';
            $stmt = $pdo->prepare($sql);
            $i = 1;
            foreach ($emailHashes as $h) {
                $stmt->bindValue($i++, $h, \PDO::PARAM_LOB);
            }
            $stmt->execute();
            $user = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        }
    } catch (\Throwable $e) {
        try { Logger::error('User lookup failed (password_reset)', null, ['exception' => (string)$e, 'email_hint' => $logEmailHint($email)]); } catch (\Throwable $_) {}
        $user = null;
    }

    // if user found and account is active & not locked -> create a reset token and enqueue email
    if ($user && (int)$user['is_active'] === 1 && (int)$user['is_locked'] === 0) {
        $uid = (int)$user['id'];

        // generate tokens
        $selector = bin2hex(random_bytes(6)); // 12 hex
        $validator = random_bytes(32); // binary
        $validatorHex = bin2hex($validator);
        // compute token hash for storage (hex) and validator_hash (binary HMAC via key or sha256)
        $tokenHashHex = hash('sha256', $validator);

        // try to derive validator_hash with EMAIL_VERIFICATION_KEY (prefer HMAC)
        $validatorHashBin = null;
        $validatorKeyVer = null;
        try {
            if (method_exists('KeyManager', 'deriveHmacWithLatest')) {
                $vinfo = KeyManager::deriveHmacWithLatest('EMAIL_VERIFICATION_KEY', KEYS_DIR, 'email_verification_key', $validator);
                if (!empty($vinfo['hash'])) {
                    $validatorHashBin = $vinfo['hash'];
                    $validatorKeyVer = $vinfo['version'] ?? null;
                }
            }
            if ($validatorHashBin === null && method_exists('KeyManager', 'getEmailVerificationKeyInfo')) {
                $ev = KeyManager::getEmailVerificationKeyInfo(KEYS_DIR);
                if (!empty($ev['raw']) && is_string($ev['raw'])) {
                    $validatorHashBin = hash_hmac('sha256', $validator, $ev['raw'], true);
                    $validatorKeyVer = $ev['version'] ?? null;
                    try { KeyManager::memzero($ev['raw']); } catch (\Throwable $_) {}
                }
            }
        } catch (\Throwable $e) {
            try { Logger::error('derive validator hash failed (password_reset)', $uid, ['exception' => (string)$e]); } catch (\Throwable $_) {}
            // fallback to sha256 binary
            $validatorHashBin = hash('sha256', $validator, true);
            $validatorKeyVer = null;
        }

        // expiry
        $expiresAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+1 hour')->format('Y-m-d H:i:s.u');

        // store into email_verifications (reuse table)
        try {
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
            try { Logger::systemError($e, $uid ?? null); } catch (\Throwable $_) {}
            // fallback: continue but don't fail user flow
        }

        // enqueue e-mail
        try {
            if (!class_exists('Mailer') || !method_exists('Mailer', 'enqueue')) {
                try { Logger::warn('Mailer::enqueue not available (password_reset)', $uid, ['email_hint' => $logEmailHint($email)]); } catch (\Throwable $_) {}
            } else {
                $base = rtrim((string)($_ENV['APP_URL'] ?? ($_ENV['BASE_URL'] ?? '')), '/');
                $resetUrl = $base . '/password_reset_confirm.php?selector=' . rawurlencode($selector) . '&validator=' . rawurlencode($validatorHex);

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
                        'cipher_format' => null,
                        'source' => 'password_reset',
                        'remote_ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                    ],
                ];

                $notifId = Mailer::enqueue($payload);
                try { Logger::systemMessage('notice', 'Password reset enqueued', $uid, ['notification_id' => $notifId, 'selector' => $selector]); } catch (\Throwable $_) {}
            }
        } catch (\Throwable $e) {
            try { Logger::error('Mailer enqueue failed (password_reset)', $uid, ['exception' => (string)$e, 'email_hint' => $logEmailHint($email)]); } catch (\Throwable $_) {}
            // swallow — still return generic response
        }

        // memzero sensitive buffers
        try { KeyManager::memzero($validator); } catch (\Throwable $_) {}
        try { KeyManager::memzero($validatorHashBin); } catch (\Throwable $_) {}
        // candidate email hashes
        try { foreach ($emailHashes as $h) { if (is_string($h)) KeyManager::memzero($h); } } catch (\Throwable $_) {}
    }

    // Always render the generic "sent" page regardless of outcome
    echo Templates::render('pages/password_reset_sent.php', ['email' => $emailRaw, 'message' => $genericUserMessage]);
    exit;
}

// GET -> render form
echo Templates::render('pages/password_reset.php', ['message' => null]);
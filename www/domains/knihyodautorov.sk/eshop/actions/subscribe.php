<?php
declare(strict_types=1);

// actions/subscribe.php
// PURE handler: vrací ['status'=>int,'json'=>array] pro front controller.
// Očekává vyextrahované proměnné:
//   $config, $Logger (class-name or null), $MailHelper, $Mailer, $Recaptcha, $KEYS_DIR, $CSRF, $csrf, $db

// fallbacky (trustedShared.prepareForHandler by měl předat většinu)
if (!isset($config) || !is_array($config)) $config = [];
$Logger = $Logger ?? null;
$MailHelper = $MailHelper ?? \BlackCat\Core\Helpers\MailHelper::class;
$Mailer = $Mailer ?? \BlackCat\Core\Mail\Mailer::class;
$RecaptchaClass = $Recaptcha ?? \BlackCat\Core\Security\Recaptcha::class;
$KEYS_DIR = $KEYS_DIR ?? (defined('KEYS_DIR') ? KEYS_DIR : ($config['paths']['keys'] ?? null));

// helper: uniform JSON response
$resp = function(int $status, array $json = [], array $headers = []) {
    return ['status' => $status, 'headers' => $headers, 'json' => $json];
};

// logging helpers
$logError = function(string $msg, array $ctx = []) use ($Logger) {
    if (is_string($Logger) && class_exists($Logger)) {
        try { $Logger::error($msg, $ctx['user_id'] ?? null, $ctx); } catch (\Throwable $_) {}
    } else {
        error_log($msg . ' ' . json_encode($ctx));
    }
};
$logWarn = function(string $msg, array $ctx = []) use ($Logger) {
    if (is_string($Logger) && class_exists($Logger)) {
        try { $Logger::warn($msg, $ctx['user_id'] ?? null, $ctx); } catch (\Throwable $_) {}
    } else {
        error_log($msg . ' ' . json_encode($ctx));
    }
};

// Ensure POST
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    return $resp(405, ['success'=>false,'message'=>'Method Not Allowed']);
}

// read inputs
$emailRaw = (string)($_POST['email'] ?? '');
$honeypot = trim((string)($_POST['website'] ?? ''));
$recaptchaToken = (string)($_POST['g-recaptcha-response'] ?? '');
$origin = trim((string)($_POST['origin'] ?? $_SERVER['HTTP_REFERER'] ?? ''));

// honeypot
if ($honeypot !== '') {
    return $resp(200, ['success'=>false,'message'=>'OK']);
}

$postedCsrf = (string)($_POST['csrf'] ?? '');

// defaultně nevalidní
$csrfOk = false;

if (!empty($CSRF)) {
    // $CSRF může být FQCN (string) nebo objekt — normalizuj na class name
    $csrfClass = is_string($CSRF) ? $CSRF : (is_object($CSRF) ? get_class($CSRF) : null);
    if ($csrfClass && class_exists($csrfClass)) {
        // prefer moderní metoda validate(), fallback na verify() pro legacy
        if (method_exists($csrfClass, 'validate')) {
            try {
                $csrfOk = $csrfClass::validate($postedCsrf);
            } catch (\Throwable $_) {
                $csrfOk = false;
            }
        } elseif (method_exists($csrfClass, 'verify')) {
            try {
                $csrfOk = $csrfClass::verify($postedCsrf);
            } catch (\Throwable $_) {
                $csrfOk = false;
            }
        } else {
            // class provided, ale žádná validační metoda — považuj za nevalidní
            $csrfOk = false;
        }
    }
} elseif (isset($csrf) && $csrf !== null) {
    // legacy shared single-token fallback
    $csrfOk = ($postedCsrf !== '' && $postedCsrf === $csrf);
}

// pokud CSRF selže, hned blokovat
if (!$csrfOk) {
    return $resp(400, ['success'=>false,'message'=>'CSRF token invalid']);
}

// normalize & validate email using existing Validator if present
if (!class_exists(\BlackCat\Core\Validation\Validator::class, true)) {
    $logError('Validator class missing', ['stage'=>'validation']);
    return $resp(500, ['success'=>false,'message'=>'Server error']);
}
$normalizeEmail = static function(string $e): string { return mb_strtolower(trim($e), 'UTF-8'); };
$email = $normalizeEmail($emailRaw);
$email = \BlackCat\Core\Validation\Validator::stringSanitized($email, 512);
if (!\BlackCat\Core\Validation\Validator::Email($email) || mb_strlen($email, 'UTF-8') > 512) {
    return $resp(400, ['success'=>false,'message'=>'Neplatný e-mail']);
}

// DB: try to get PDO from injected $db or fallback via Database::getInstance()
$pdo = null;
try {
    if (isset($db) && $db !== null) {
        // $db might be PDO or wrapper with getPdo()
        if ($db instanceof \PDO) {
            $pdo = $db;
        } elseif (is_object($db) && method_exists($db, 'getPdo')) {
            $pdo = $db->getPdo();
        }
    }
    if ($pdo === null && class_exists(\BlackCat\Core\Database::class, true) && method_exists(\BlackCat\Core\Database::class, 'getInstance')) {
        $dbInst = \BlackCat\Core\Database::getInstance();
        if ($dbInst instanceof \PDO) $pdo = $dbInst;
        elseif (is_object($dbInst) && method_exists($dbInst, 'getPdo')) $pdo = $dbInst->getPdo();
    }
    if (!($pdo instanceof \PDO)) {
        $logError('PDO not available for subscribe', []);
        return $resp(500, ['success'=>false,'message'=>'Interná chyba (DB).']);
    }
} catch (\Throwable $e) {
    try { if (is_string($Logger) && class_exists($Logger)) $Logger::error('DB getPdo failed', null, ['ex'=>(string)$e]); } catch (\Throwable $_) {}
    return $resp(500, ['success'=>false,'message'=>'Interná chyba (DB).']);
}

// recaptcha: require token + secret
if ($recaptchaToken === '') return $resp(400, ['success'=>false,'message'=>'reCAPTCHA nebola poskytnutá.']);
$recaptchaSecret = $config['capchav3']['secret_key'] ?? $_ENV['CAPCHA_SECRET_KEY'] ?? '';
$recaptchaMinScore = (float)($config['capchav3']['min_score'] ?? $_ENV['CAPCHA_MIN_SCORE'] ?? 0.4);
if ($recaptchaMinScore < 0 || $recaptchaMinScore > 1) $recaptchaMinScore = 0.4;
if ($recaptchaSecret === '') {
    $logError('reCAPTCHA secret missing', []);
    return $resp(500, ['success'=>false,'message'=>'Interná chyba (reCAPTCHA).']);
}

try {
    $rec = new $RecaptchaClass($recaptchaSecret, $recaptchaMinScore, ['logger' => is_string($Logger) ? $Logger : null]);
    $recResult = $rec->verify($recaptchaToken, $_SERVER['REMOTE_ADDR'] ?? '');
} catch (\Throwable $e) {
    $logError('Recaptcha verify exception', ['ex'=>(string)$e,'email_hint'=>substr($email,0,1).'***']);
    return $resp(500, ['success'=>false,'message'=>'Nepodarilo sa overiť reCAPTCHA.']);
}
if (!is_array($recResult) || empty($recResult['ok'])) {
    $logWarn('reCAPTCHA failed', ['rec' => $recResult ?? null, 'email_hint'=>substr($email,0,1).'***']);
    return $resp(400, ['success'=>false,'message'=>'Nepodarilo sa overiť reCAPTCHA.']);
}

// REQUIRED: KeyManager exists and derives HMAC candidates
if (!class_exists(\BlackCat\Core\Security\KeyManager::class, true)) {
    $logError('KeyManager missing', []);
    return $resp(500, ['success'=>false,'message'=>'Služba nedostupná. Kontaktujte administrátora.']);
}
$KeyManager = \BlackCat\Core\Security\KeyManager::class;

// derive HMAC candidates (single call)
try {
    if (!method_exists($KeyManager, 'deriveHmacCandidates')) {
        throw new \RuntimeException('KeyManager::deriveHmacCandidates not available');
    }
    $emailCandidates = $KeyManager::deriveHmacCandidates('EMAIL_HASH_KEY', $KEYS_DIR, 'email_hash_key', $email);
    if (empty($emailCandidates)) throw new \RuntimeException('No HMAC candidates');
} catch (\Throwable $e) {
    $logError('deriveHmacCandidates failed', ['ex'=>(string)$e, 'email_hint'=>substr($email,0,1).'***']);
    return $resp(500, ['success'=>false,'message'=>'Interná chyba (kľúč). Kontaktujte administrátora.']);
}

// dedupe hashes
$emailHashes = [];
$seen = [];
foreach ($emailCandidates as $c) {
    if (!isset($c['hash'])) continue;
    $hex = bin2hex($c['hash']);
    if (isset($seen[$hex])) continue;
    $seen[$hex] = true;
    $emailHashes[] = $c['hash'];
}

// find userId by email_hash IN (...)
$userId = null;
try {
    if (!empty($emailHashes)) {
        $placeholders = implode(',', array_fill(0, count($emailHashes), '?'));
        $sql = 'SELECT id FROM pouzivatelia WHERE email_hash IN (' . $placeholders . ') LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $i = 1;
        foreach ($emailHashes as $h) {
            $stmt->bindValue($i++, $h, \PDO::PARAM_LOB);
        }
        $stmt->execute();
        $found = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($found && isset($found['id'])) $userId = (int)$found['id'];
    }
} catch (\Throwable $e) {
    $logWarn('User lookup failed', ['ex'=>(string)$e,'email_hint'=>substr($email,0,1).'***']);
    $userId = null;
}

// memzero candidates
try {
    foreach ($emailCandidates as $c) {
        if (isset($c['hash']) && is_string($c['hash']) && method_exists($KeyManager, 'memzero')) $KeyManager::memzero($c['hash']);
    }
    unset($emailCandidates);
} catch (\Throwable $_) {}

// find existing subscriber
$existing = null;
try {
    if (!empty($emailHashes)) {
        $placeholders = implode(',', array_fill(0, count($emailHashes), '?'));
        $sql = 'SELECT * FROM newsletter_subscribers WHERE email_hash IN (' . $placeholders . ') LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $i = 1;
        foreach ($emailHashes as $h) {
            $stmt->bindValue($i++, $h, \PDO::PARAM_LOB);
        }
        $stmt->execute();
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row) $existing = $row;
    }
} catch (\Throwable $e) {
    $logWarn('Newsletter lookup failed', ['ex'=>(string)$e,'email_hint'=>substr($email,0,1).'***']);
    $existing = null;
}

// if already confirmed and not unsubscribed => done
if ($existing && !empty($existing['confirmed_at']) && empty($existing['unsubscribed_at'])) {
    try { foreach ($emailHashes as $h) { if (is_string($h) && method_exists($KeyManager, 'memzero')) $KeyManager::memzero($h); } } catch (\Throwable $_) {}
    return $resp(200, ['success'=>true,'message'=>'E-mail je už prihlásený na odber.']);
}

// --- prepare email crypto via MailHelper::prepareEmailCrypto (same as původní) ---
try {
    $keysDir = $config['paths']['keys'] ?? $KEYS_DIR ?? null;
    $cryptoInfo = $MailHelper::prepareEmailCrypto([
        'email' => $email,
        'keysDir' => $keysDir,
    ]);
    $emailHashBin = $cryptoInfo['email_hash_bin'] ?? null;
    $emailHashVer = $cryptoInfo['email_hash_version'] ?? null;
    $emailEnc = $cryptoInfo['email_enc'] ?? null;
    $emailEncKeyVer = $cryptoInfo['email_enc_key_version'] ?? null;
} catch (\Throwable $e) {
    $logError('MailHelper::prepareEmailCrypto failed', ['ex'=>(string)$e,'email_hint'=>substr($email,0,1).'***']);
    return $resp(500, ['success'=>false,'message'=>'Interná chyba pri spracovaní e-mailu.']);
}

// create tokens, derive confirm + unsubscribe hashes (same logic as původní; zkracuji drobně)
$selector = bin2hex(random_bytes(6));
$validator = random_bytes(32);
$validatorHex = bin2hex($validator);

// confirm validator hash
$confirmValidatorHash = null;
$confirmValidatorKeyVer = null;
try {
    if (method_exists($KeyManager, 'deriveHmacWithLatest')) {
        $vinfo = $KeyManager::deriveHmacWithLatest('EMAIL_VERIFICATION_KEY', $KEYS_DIR, 'email_verification_key', $validator);
        if (!empty($vinfo['hash'])) {
            $confirmValidatorHash = $vinfo['hash'];
            $confirmValidatorKeyVer = $vinfo['version'] ?? null;
        }
    }
    if ($confirmValidatorHash === null && method_exists($KeyManager, 'getEmailVerificationKeyInfo')) {
        $ev = $KeyManager::getEmailVerificationKeyInfo($KEYS_DIR);
        if (!empty($ev['raw']) && is_string($ev['raw']) && strlen($ev['raw']) === $KeyManager::keyByteLen()) {
            $confirmValidatorHash = hash_hmac('sha256', $validator, $ev['raw'], true);
            $confirmValidatorKeyVer = $ev['version'] ?? null;
            if (method_exists($KeyManager, 'memzero')) {
                try { $KeyManager::memzero($ev['raw']); } catch (\Throwable $_) {}
            }
        }
    }
} catch (\Throwable $e) {
    $logError('Confirm validator derive failed', ['ex'=>(string)$e,'email_hint'=>substr($email,0,1).'***']);
}

// enforce presence
if ($confirmValidatorHash === null) {
    try { if (method_exists($KeyManager, 'memzero')) \BlackCat\Core\Security\KeyManager::memzero($validator); } catch (\Throwable $_) {}
    try { foreach ($emailHashes as $h) { if (is_string($h) && method_exists($KeyManager, 'memzero')) \BlackCat\Core\Security\KeyManager::memzero($h); } } catch (\Throwable $_) {}
    return $resp(500, ['success'=>false,'message'=>'Interná chyba: overovací kľúč nie je k dispozícii.']);
}

// unsubscribe token + derive
$unsubscribeTokenBin = random_bytes(32);
$unsubscribeToken = bin2hex($unsubscribeTokenBin);
$unsubscribeTokenHash = null;
$unsubscribeTokenKeyVer = null;
try {
    if (method_exists($KeyManager, 'deriveHmacWithLatest')) {
        $uinfo = $KeyManager::deriveHmacWithLatest('UNSUBSCRIBE_KEY', $KEYS_DIR, 'unsubscribe_key', $unsubscribeTokenBin);
        if (!empty($uinfo['hash'])) {
            $unsubscribeTokenHash = $uinfo['hash'];
            $unsubscribeTokenKeyVer = $uinfo['version'] ?? null;
        }
    }
    if ($unsubscribeTokenHash === null && method_exists($KeyManager, 'getUnsubscribeKeyInfo')) {
        $uv = $KeyManager::getUnsubscribeKeyInfo($KEYS_DIR);
        if (!empty($uv['raw']) && is_string($uv['raw']) && strlen($uv['raw']) === $KeyManager::keyByteLen()) {
            $unsubscribeTokenHash = hash_hmac('sha256', $unsubscribeTokenBin, $uv['raw'], true);
            $unsubscribeTokenKeyVer = $uv['version'] ?? null;
            if (method_exists($KeyManager, 'memzero')) {
                try { $KeyManager::memzero($uv['raw']); } catch (\Throwable $_) {}
            }
        }
    }
} catch (\Throwable $e) {
    $logError('Unsubscribe derive failed', ['ex'=>(string)$e,'email_hint'=>substr($email,0,1).'***']);
}

if ($unsubscribeTokenHash === null) {
    try { if (isset($validator) && method_exists($KeyManager, 'memzero')) $KeyManager::memzero($validator); } catch (\Throwable $_) {}
    try { if (isset($unsubscribeTokenBin) && method_exists($KeyManager, 'memzero')) $KeyManager::memzero($unsubscribeTokenBin); } catch (\Throwable $_) {}
    try { foreach ($emailHashes as $h) { if (is_string($h) && method_exists($KeyManager, 'memzero')) \BlackCat\Core\Security\KeyManager::memzero($h); } } catch (\Throwable $_) {}
    return $resp(500, ['success'=>false,'message'=>'Interná chyba: odhlasovací kľúč nie je k dispozícii.']);
}

// confirm_expires
$expiresAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+48 hours')->format('Y-m-d H:i:s.u');

// meta + origin
$origin = $origin !== '' ? mb_substr($origin, 0, 200) : null;
$meta = [
    'origin' => $origin,
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
    'created_via' => 'subscribe',
];
$metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE);
if ($metaJson === false) $metaJson = json_encode(new \stdClass());

// DB insert/update in transaction
try {
    $pdo->beginTransaction();

    if ($existing) {
        $upd = $pdo->prepare("UPDATE newsletter_subscribers SET
            user_id = :user_id,
            email_enc = :email_enc,
            email_key_version = :email_key_version,
            email_hash = :email_hash,
            email_hash_key_version = :email_hash_key_version,
            confirm_selector = :confirm_selector,
            confirm_validator_hash = :confirm_validator_hash,
            confirm_key_version = :confirm_key_version,
            confirm_expires = :confirm_expires,
            confirmed_at = NULL,
            unsubscribe_token_hash = :unsubscribe_token_hash,
            unsubscribe_token_key_version = :unsubscribe_token_key_version,
            origin = :origin,
            ip_hash = :ip_hash,
            ip_hash_key_version = :ip_hash_key_version,
            meta = :meta,
            updated_at = UTC_TIMESTAMP(6),
            unsubscribed_at = NULL
            WHERE id = :id");
        $upd->bindValue(':user_id', $userId !== null ? $userId : null, $userId !== null ? \PDO::PARAM_INT : \PDO::PARAM_NULL);
        if ($emailEnc !== null) $upd->bindValue(':email_enc', $emailEnc, \PDO::PARAM_LOB); else $upd->bindValue(':email_enc', null, \PDO::PARAM_NULL);
        $upd->bindValue(':email_key_version', $emailEncKeyVer, $emailEncKeyVer !== null ? \PDO::PARAM_STR : \PDO::PARAM_NULL);
        $upd->bindValue(':email_hash', $emailHashBin, $emailHashBin !== null ? \PDO::PARAM_LOB : \PDO::PARAM_NULL);
        $upd->bindValue(':email_hash_key_version', $emailHashVer, $emailHashVer !== null ? \PDO::PARAM_STR : \PDO::PARAM_NULL);
        $upd->bindValue(':confirm_selector', $selector, \PDO::PARAM_STR);
        $upd->bindValue(':confirm_validator_hash', $confirmValidatorHash, \PDO::PARAM_LOB);
        $upd->bindValue(':confirm_key_version', $confirmValidatorKeyVer, $confirmValidatorKeyVer !== null ? \PDO::PARAM_STR : \PDO::PARAM_NULL);
        $upd->bindValue(':confirm_expires', $expiresAt, \PDO::PARAM_STR);
        $upd->bindValue(':unsubscribe_token_hash', $unsubscribeTokenHash, \PDO::PARAM_LOB);
        $upd->bindValue(':unsubscribe_token_key_version', $unsubscribeTokenKeyVer, $unsubscribeTokenKeyVer !== null ? \PDO::PARAM_STR : \PDO::PARAM_NULL);
        $upd->bindValue(':origin', $origin !== null ? $origin : null, $origin !== null ? \PDO::PARAM_STR : \PDO::PARAM_NULL);
        if (isset($ipHashBin) && $ipHashBin !== null) $upd->bindValue(':ip_hash', $ipHashBin, \PDO::PARAM_LOB); else $upd->bindValue(':ip_hash', null, \PDO::PARAM_NULL);
        $upd->bindValue(':ip_hash_key_version', $ipHashKeyVer, $ipHashKeyVer !== null ? \PDO::PARAM_STR : \PDO::PARAM_NULL);
        $upd->bindValue(':meta', $metaJson, \PDO::PARAM_STR);
        $upd->bindValue(':id', (int)$existing['id'], \PDO::PARAM_INT);
        $upd->execute();
        $nsId = (int)$existing['id'];
    } else {
        $ins = $pdo->prepare("INSERT INTO newsletter_subscribers
            (user_id, email_enc, email_key_version, email_hash, email_hash_key_version,
            confirm_selector, confirm_validator_hash, confirm_key_version, confirm_expires,
            unsubscribe_token_hash, unsubscribe_token_key_version, origin, ip_hash, ip_hash_key_version, meta, created_at, updated_at, unsubscribed_at)
            VALUES (:user_id, :email_enc, :email_key_version, :email_hash, :email_hash_key_version,
                    :confirm_selector, :confirm_validator_hash, :confirm_key_version, :confirm_expires,
                    :unsubscribe_token_hash, :unsubscribe_token_key_version, :origin, :ip_hash, :ip_hash_key_version, :meta, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), NULL)");
        $ins->bindValue(':user_id', $userId !== null ? $userId : null, $userId !== null ? \PDO::PARAM_INT : \PDO::PARAM_NULL);
        if ($emailEnc !== null) $ins->bindValue(':email_enc', $emailEnc, \PDO::PARAM_LOB); else $ins->bindValue(':email_enc', null, \PDO::PARAM_NULL);
        $ins->bindValue(':email_key_version', $emailEncKeyVer, $emailEncKeyVer !== null ? \PDO::PARAM_STR : \PDO::PARAM_NULL);
        $ins->bindValue(':email_hash', $emailHashBin, $emailHashBin !== null ? \PDO::PARAM_LOB : \PDO::PARAM_NULL);
        $ins->bindValue(':email_hash_key_version', $emailHashVer, $emailHashVer !== null ? \PDO::PARAM_STR : \PDO::PARAM_NULL);
        $ins->bindValue(':confirm_selector', $selector, \PDO::PARAM_STR);
        $ins->bindValue(':confirm_validator_hash', $confirmValidatorHash, \PDO::PARAM_LOB);
        $ins->bindValue(':confirm_key_version', $confirmValidatorKeyVer, $confirmValidatorKeyVer !== null ? \PDO::PARAM_STR : \PDO::PARAM_NULL);
        $ins->bindValue(':confirm_expires', $expiresAt, \PDO::PARAM_STR);
        $ins->bindValue(':unsubscribe_token_hash', $unsubscribeTokenHash, \PDO::PARAM_LOB);
        $ins->bindValue(':unsubscribe_token_key_version', $unsubscribeTokenKeyVer, $unsubscribeTokenKeyVer !== null ? \PDO::PARAM_STR : \PDO::PARAM_NULL);
        $ins->bindValue(':origin', $origin !== null ? $origin : null, $origin !== null ? \PDO::PARAM_STR : \PDO::PARAM_NULL);
        if (isset($ipHashBin) && $ipHashBin !== null) $ins->bindValue(':ip_hash', $ipHashBin, \PDO::PARAM_LOB); else $ins->bindValue(':ip_hash', null, \PDO::PARAM_NULL);
        $ins->bindValue(':ip_hash_key_version', $ipHashKeyVer, $ipHashKeyVer !== null ? \PDO::PARAM_STR : \PDO::PARAM_NULL);
        $ins->bindValue(':meta', $metaJson, \PDO::PARAM_STR);
        $ins->execute();
        $nsId = (int)$pdo->lastInsertId();
    }

    $pdo->commit();
} catch (\Throwable $e) {
    try { if ($pdo->inTransaction()) $pdo->rollBack(); } catch (\Throwable $_) {}
    // cleanup sensitive
    try { if (isset($validator) && method_exists($KeyManager,'memzero')) $KeyManager::memzero($validator); } catch (\Throwable $_) {}
    try { if (isset($unsubscribeTokenBin) && method_exists($KeyManager,'memzero')) $KeyManager::memzero($unsubscribeTokenBin); } catch (\Throwable $_) {}
    try { foreach ($emailHashes as $h) { if (is_string($h) && method_exists($KeyManager,'memzero')) $KeyManager::memzero($h); } } catch (\Throwable $_) {}
    try { if (isset($confirmValidatorHash) && method_exists($KeyManager,'memzero')) $KeyManager::memzero($confirmValidatorHash); } catch (\Throwable $_) {}
    try { if (isset($unsubscribeTokenHash) && method_exists($KeyManager,'memzero')) $KeyManager::memzero($unsubscribeTokenHash); } catch (\Throwable $_) {}
    try { if (isset($emailHashBin) && method_exists($KeyManager,'memzero')) $KeyManager::memzero($emailHashBin); } catch (\Throwable $_) {}

    $logError('DB insert/update failed (subscribe)', ['ex'=>(string)$e,'email_hint'=>substr($email,0,1).'***']);
    return $resp(500, ['success'=>false,'message'=>'Chyba pri spracovaní (server). Skúste neskôr.']);
}

// cleanup sensitive variables
try { if (isset($validator) && method_exists($KeyManager,'memzero')) $KeyManager::memzero($validator); unset($validator); } catch (\Throwable $_) {}
try { if (isset($unsubscribeTokenBin) && method_exists($KeyManager,'memzero')) $KeyManager::memzero($unsubscribeTokenBin); unset($unsubscribeTokenBin); } catch (\Throwable $_) {}
try { foreach ($emailHashes as $h) { if (is_string($h) && method_exists($KeyManager,'memzero')) $KeyManager::memzero($h); } } catch (\Throwable $_) {}
try { if (isset($confirmValidatorHash) && method_exists($KeyManager,'memzero')) $KeyManager::memzero($confirmValidatorHash); unset($confirmValidatorHash); } catch (\Throwable $_) {}
try { if (isset($unsubscribeTokenHash) && method_exists($KeyManager,'memzero')) $KeyManager::memzero($unsubscribeTokenHash); unset($unsubscribeTokenHash); } catch (\Throwable $_) {}
try { if (isset($emailHashBin) && method_exists($KeyManager,'memzero')) $KeyManager::memzero($emailHashBin); unset($emailHashBin); } catch (\Throwable $_) {}
try { if (isset($emailEnc) && is_string($emailEnc) && method_exists($KeyManager,'memzero')) $KeyManager::memzero($emailEnc); unset($emailEnc); } catch (\Throwable $_) {}

// enqueue confirmation email via Mailer::enqueue (outside transaction)
try {
    if (class_exists($Mailer, true) && method_exists($Mailer, 'enqueue')) {
        $base = rtrim((string)($_ENV['APP_URL'] ?? ''), '/');
        $confirmUrl = $base . '/newsletter_confirm?selector=' . rawurlencode($selector) . '&validator=' . rawurlencode($validatorHex);
        $unsubscribeUrl = $base . '/newsletter_unsubscribe?token=' . rawurlencode($unsubscribeToken);

        $payloadArr = \BlackCat\Core\Helpers\MailHelper::buildSubscribeNotificationPayload([
            'subscriber_id' => $nsId,
            'to' => $email,
            'subject' => 'Potvrďte prihlásenie na odber noviniek',
            'template' => 'newsletter_subscribe_confirm',
            'vars' => [
                'confirm_url' => $confirmUrl,
                'unsubscribe_url' => $unsubscribeUrl,
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
                'confirm_key_version' => $confirmValidatorKeyVer ?? null,
                'unsubscribe_key_version' => $unsubscribeTokenKeyVer ?? null,
                'email_hash_key_version' => $emailHashVer ?? null,
                'ip_hash_key_version' => $ipHashKeyVer ?? null,
                'cipher_format' => 'aead_xchacha20poly1305_v1_binary',
                'user_id' => $userId ?? null,
            ],
        ]);

        // validate payload if Validator available
        $payloadForValidation = [
            'to' => $payloadArr['to'],
            'subject' => $payloadArr['subject'],
            'template' => $payloadArr['template'],
            'vars' => $payloadArr['vars'],
        ];
        $payloadJson = json_encode($payloadForValidation, JSON_UNESCAPED_UNICODE);
        if ($payloadJson === false || !\BlackCat\Core\Validation\Validator::NotificationPayload($payloadJson, $payloadArr['template'])) {
            try { if (is_string($Logger) && class_exists($Logger)) $Logger::error('Notification payload validation failed (subscribe)', $userId ?? null, ['subscriber_id'=>$nsId]); } catch (\Throwable $_) {}
            return $resp(200, ['success'=>true,'message'=>'Záznam uložený, ale overovací e-mail nebol naplánovaný (payload invalid).']);
        }

        $notifId = $Mailer::enqueue($payloadArr);
        try { if (is_string($Logger) && class_exists($Logger) && method_exists($Logger,'systemMessage')) $Logger::systemMessage('notice','Newsletter confirm enqueued',$userId ?? null,['subscriber_id'=>$nsId,'notification_id'=>$notifId]); } catch (\Throwable $_) {}
    } else {
        try { if (is_string($Logger) && class_exists($Logger)) $Logger::warn('Mailer::enqueue not available; confirmation email not scheduled', $userId ?? null); } catch (\Throwable $_) {}
        return $resp(200, ['success'=>true,'message'=>'Záznam uložený. Overovací e-mail nebol naplánovaný (Mailer unavailable).']);
    }
} catch (\Throwable $e) {
    // log and return but record success (resource created). 202 fits.
    try { if (is_string($Logger) && class_exists($Logger)) $Logger::error('Mailer enqueue failed (subscribe)', $userId ?? null, ['ex'=>(string)$e]); } catch (\Throwable $_) {}
    return $resp(202, ['success'=>true,'message'=>'Záznam uložený, ale nepodarilo sa naplánovať overovací e-mail. Kontaktujte podporu.']);
}

// success
return $resp(200, ['success'=>true,'message'=>'Potvrďte odber kliknutím v e-maile.']);
<?php

declare(strict_types=1);

use BlackCat\Core\Database;
use BlackCat\Core\Log\Logger;
use BlackCat\Core\Helpers\MailHelper;
use BlackCat\Core\Security\Recaptcha;
use BlackCat\Core\Security\KeyManager;
use BlackCat\Core\Validation\Validator;
use BlackCat\Core\Mail\Mailer;

$bootstrapPath = realpath(dirname(__DIR__, 1) . '/eshop/inc/bootstrap.php');
if ($bootstrapPath && is_file($bootstrapPath)) {
    require_once $bootstrapPath;
} else {
    throw new \RuntimeException('Bootstrap not found at expected path: ' . (string)($bootstrapPath ?: dirname(__DIR__, 1) . '/eshop/inc/bootstrap.php'));
}

if (!isset($config) || !is_array($config)) {
    $config = [];
}

/**
 * subscribe.php (production-ready, dedup HMAC candidates, memzero via KeyManager,
 *                     uses Validator::validateEmail and Validator::validateNotificationPayload)
 *
 * Double-opt-in subscribe action:
 * - POST['email']
 * - normalize + validate via Validator
 * - compute HMAC candidates once (KeyManager::deriveHmacCandidates)
 * - single IN(...) lookup for pouzivatelia and newsletter_subscribers
 * - insert/update newsletter_subscribers in transaction
 * - memzero sensitive buffers using KeyManager::memzero()
 * - enqueue via Mailer::enqueue with payload.meta containing key versions (validated)
 */

// anonymizace emailu pro logy (neukládej plaintext email do logu)
function emailHint(string $email): string {
    $email = trim($email);
    if ($email === '') return '';
    $parts = explode('@', $email);
    if (count($parts) !== 2) return '***';
    [$local, $domain] = $parts;
    $localShown = mb_substr($local, 0, 1, 'UTF-8') ?: '*';
    return $localShown . str_repeat('*', max(0, min(6, mb_strlen($local, 'UTF-8') - 1))) . '@' . $domain;
}

/**
 * Loguje (pokud je $e tak systemError, jinak error/notice) a odešle
 * uživateli bezpečnou zprávu JSON + HTTP status.
 *
 * $logCtx - doplňující kontext pro log (nesmí obsahovat raw sensitive values!)
 */
function logAndRespond(string $userMsg, int $httpCode = 200, ?\Throwable $e = null, array $logCtx = []) : void {
    // zkresli citliviny v ctx - uživatelský e-mail by měl být anonymizován dříve
    if (class_exists(Logger::class, true)) {
        try {
            if ($e !== null && method_exists(Logger::class, 'systemError')) {
                // systemError očekává Throwable (pokud má tvůj Logger jinou signaturu, uprav)
                Logger::systemError($e, $logCtx['user_id'] ?? null, (string)$logCtx);
            } else {
                Logger::error($userMsg, $logCtx['user_id'] ?? null, $logCtx);
            }
        } catch (\Throwable $_) {
            // ignore logger failures
        }
    }
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => $userMsg], JSON_UNESCAPED_UNICODE);
    exit;
}

function subscribeFeedback(string $msg, bool $success = false): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => $success, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}
// kritické závislosti
$required = [KeyManager::class, Logger::class, Validator::class];
$missing = [];
foreach ($required as $c) {
    if (!class_exists($c, true)) $missing[] = $c;
}
if (!empty($missing)) {
$msg = 'Interná chyba: chýbajú knižnice: ' . implode(', ', $missing) . '.';
    if (class_exists(Logger::class, true)) {
        try { Logger::systemError(new \RuntimeException($msg)); } catch (\Throwable $_) {}
    }
    logAndRespond('Služba momentálne nedostupná. Kontaktujte administrátora.', 503, null, ['missing' => $missing]);
}

if (!defined('KEYS_DIR') || !is_string(KEYS_DIR) || KEYS_DIR === '') {
    $msg = 'Interná chyba: KEYS_DIR nie je nastavený. Skontrolujte konfiguráciu kľúčov.';
    try { Logger::systemError(new \RuntimeException($msg)); } catch (\Throwable $_) {}
    logAndRespond('Služba momentálne nedostupná. Kontaktujte administrátora.', 503, null, ['missing' => 'KEYS_DIR']);
}

// --- EARLY HEALTH-CHECK: require EMAIL_VERIFICATION_KEY and UNSUBSCRIBE_KEY to be present ---
try {
    $haveEmailVer = false;
    $haveUnsub = false;

    // email verification key presence
    try {
        if (method_exists(KeyManager::class, 'locateLatestKeyFile')) {
            $info = KeyManager::locateLatestKeyFile(KEYS_DIR, 'email_verification_key');
            if (!empty($info['version'])) $haveEmailVer = true;
        }
        if (!$haveEmailVer && method_exists(KeyManager::class, 'getEmailVerificationKeyInfo')) {
            $info = KeyManager::getEmailVerificationKeyInfo(KEYS_DIR);
            if (!empty($info['raw']) && is_string($info['raw']) && strlen($info['raw']) === KeyManager::keyByteLen()) $haveEmailVer = true;
        }
        if (!$haveEmailVer && method_exists(KeyManager::class, 'deriveHmacWithLatest')) {
            // capability exists; accept as present
            $haveEmailVer = true;
        }
    } catch (\Throwable $_) { $haveEmailVer = false; }

    // unsubscribe key presence
    try {
        if (method_exists(KeyManager::class, 'locateLatestKeyFile')) {
            $info = KeyManager::locateLatestKeyFile(KEYS_DIR, 'unsubscribe_key');
            if (!empty($info['version'])) $haveUnsub = true;
        }
        if (!$haveUnsub && method_exists(KeyManager::class, 'getUnsubscribeKeyInfo')) {
            $info = KeyManager::getUnsubscribeKeyInfo(KEYS_DIR);
            if (!empty($info['raw']) && is_string($info['raw']) && strlen($info['raw']) === KeyManager::keyByteLen()) $haveUnsub = true;
        }
        if (!$haveUnsub && method_exists(KeyManager::class, 'deriveHmacWithLatest')) {
            $haveUnsub = true;
        }
    } catch (\Throwable $_) { $haveUnsub = false; }

    if (!$haveEmailVer || !$haveUnsub) {
        Logger::error('Subscribe disabled: required key(s) missing', null, ['have_email_ver' => $haveEmailVer, 'have_unsub' => $haveUnsub]);
        logAndRespond('Služba momentálne nedostupná. Kontaktujte administrátora.', 503, null,
            ['have_email_ver'=>$haveEmailVer,'have_unsub'=>$haveUnsub]);
    }
} catch (\Throwable $_) {
    // fail closed
    http_response_code(503);
    subscribeFeedback('Služba dočasne nedostupná. Kontaktujte administrátora.');
}

// pomocné
$normalizeEmail = static function(string $e): string {
    return mb_strtolower(trim($e), 'UTF-8');
};

$getPdo = static function() {
    try {
        $pdo = null;
        if (class_exists(Database::class, true) && method_exists(Database::class, 'getInstance')) {
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
        return $pdo;
    } catch (\Throwable $e) {
        try { Logger::systemError($e); } catch (\Throwable $_) {}
        throw $e;
    }
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    subscribeFeedback('Metóda musí byť POST.');
}

// získat e-mail (normalized + sanitized) + validace přes Validator
$emailRaw = (string)($_POST['email'] ?? '');
$honeypot = (string)($_POST['website'] ?? '');
if ($honeypot !== '') {
    http_response_code(400);
    subscribeFeedback('Chyba pri odeslaní formulára.');
}
$email = $normalizeEmail($emailRaw);
$email = Validator::stringSanitized($email, 512);

if (!Validator::Email($email) || mb_strlen($email, 'UTF-8') > 512) {
    subscribeFeedback('Neplatný e-mail.');
}

try {
    $pdo = $getPdo();
} catch (\Throwable $e) {
    http_response_code(500);
    subscribeFeedback('Interná chyba (DB).');
}

// ---- recaptcha verification via Recaptcha class ----
$recaptchaToken = (string)($_POST['g-recaptcha-response'] ?? '');
if ($recaptchaToken === '') {
    subscribeFeedback('reCAPTCHA nebola poskytnutá.');
}
$recaptchaSecret = $config['capchav3']['secret_key'] ?? $_ENV['CAPCHA_SECRET_KEY'] ?? '';
if ($recaptchaSecret === '') {
    try { Logger::error('reCAPTCHA secret not configured'); } catch (\Throwable $_) {}
    http_response_code(500);
    subscribeFeedback('Interná chyba (reCAPTCHA). Kontaktujte administrátora.');
}

// get ip hash via Logger helper (kept in place)
$ipHashBin = null;
$ipHashKeyVer = null;
try {
    if (class_exists(Logger::class, true) && method_exists(Logger::class, 'getHashedIp')) {
        $ipInfo = Logger::getHashedIp(); // ['hash'=>binary|null, 'key_id'=>string|null, 'used'=>'keymanager'|'none']
        $ipHashBin = $ipInfo['hash'] ?? null;
        $ipHashKeyVer = $ipInfo['key_id'] ?? null;
    }
} catch (\Throwable $_) {
    $ipHashBin = null;
    $ipHashKeyVer = null;
}

$recaptchaMinScoreRaw = $config['capchav3']['min_score'] ?? $_ENV['CAPCHA_MIN_SCORE'] ?? 0.4;
$recaptchaMinScore = (float)$recaptchaMinScoreRaw;
if ($recaptchaMinScore < 0 || $recaptchaMinScore > 1) $recaptchaMinScore = 0.4;

try {
    // pass Logger::class (static) so Recaptcha can call static methods — Recaptcha supports class name/callable/object
    $rec = new Recaptcha($recaptchaSecret, $recaptchaMinScore, ['logger' => Logger::class]);
    $recResult = $rec->verify($recaptchaToken, $_SERVER['REMOTE_ADDR'] ?? '');
} catch (\Throwable $e) {
    try { Logger::error('Recaptcha verify threw exception', null, ['exception' => (string)$e]); } catch (\Throwable $_) {}
    logAndRespond('Nepodarilo sa overiť reCAPTCHA.', 500, $e, ['email_hint' => emailHint($email)]);
}

if (!is_array($recResult) || empty($recResult['ok'])) {
    try { Logger::warn('reCAPTCHA verification failed', null, ['rec' => $recResult ?? null, 'email_hint' => emailHint($email)]); } catch (\Throwable $_) {}
    logAndRespond('Nepodarilo sa overiť reCAPTCHA.', 400, null, ['rec' => $recResult ?? null, 'email_hint' => emailHint($email)]);
}

// -------------------- compute HMAC candidates ONCE (fail-fast, no fallback) --------------------
try {
    if (!method_exists(KeyManager::class, 'deriveHmacCandidates')) {
        throw new \RuntimeException('KeyManager::deriveHmacCandidates method not available');
    }

    $emailCandidates = KeyManager::deriveHmacCandidates('EMAIL_HASH_KEY', KEYS_DIR, 'email_hash_key', $email);

    if (empty($emailCandidates)) {
        throw new \RuntimeException('No HMAC candidates derived for email; check KEY configuration');
    }
} catch (\Throwable $e) {
    logAndRespond(
        'Interná chyba: overovací kľúč nie je k dispozícii. Kontaktujte administrátora.',
        500,
        $e,
        ['stage'=>'derive_email_hmac', 'email_hint'=>emailHint($email)]
    );
}

// dedupe candidate hashes (by hex) and build $emailHashes (binary strings)
$emailHashes = [];
$seen = [];
foreach ($emailCandidates as $c) {
    if (!isset($c['hash'])) continue;
    $hex = bin2hex($c['hash']);
    if (isset($seen[$hex])) continue;
    $seen[$hex] = true;
    $emailHashes[] = $c['hash'];
}

// -------------------- find existing user (pouzivatelia) via single IN(...) --------------------
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
        if ($found && isset($found['id'])) {
            $userId = (int)$found['id'];
        }
    }
} catch (\Throwable $e) {
    try { Logger::error('User lookup (pouzivatelia) failed (subscribe)', null, ['exception' => (string)$e]); } catch (\Throwable $_) {}
    $userId = null; // continue without user id
}
// memzero původních kandidátů
try {
    foreach ($emailCandidates as $c) {
        if (isset($c['hash']) && is_string($c['hash'])) KeyManager::memzero($c['hash']);
    }
    unset($emailCandidates);
} catch (\Throwable $_) {}

// -------------------- find existing subscription (newsletter_subscribers) via single IN(...) --------------------
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
    try { Logger::error('Newsletter lookup failed (subscribe)', null, ['exception' => (string)$e]); } catch (\Throwable $_) {}
    $existing = null;
}

// pokud je již confirmed a neodhlášený -> nic dělat
if ($existing && !empty($existing['confirmed_at']) && empty($existing['unsubscribed_at'])) {
    // memzero candidate hashes (udělej to dřív než pošleme odpověď)
    try { foreach ($emailHashes as $h) { if (is_string($h)) KeyManager::memzero($h); } } catch (\Throwable $_) {}
    subscribeFeedback('E-mail je už prihlásený na odber.');
}

// --- prepare email crypto + hmac using MailHelper (centralized) ---
try {
    $keysDir = $config['paths']['keys'] ?? (defined('KEYS_DIR') ? KEYS_DIR : null);
    $cryptoInfo = MailHelper::prepareEmailCrypto([
        'email' => $email,
        'keysDir' => $keysDir,
    ]);
    // expected keys returned:
    // ['email_hash_bin'=>binary|null,'email_hash_version'=>string|null,'email_enc'=>binary|null,'email_enc_key_version'=>string|null]
    $emailHashBin = $cryptoInfo['email_hash_bin'] ?? null;
    $emailHashVer = $cryptoInfo['email_hash_version'] ?? null;
    $emailEnc = $cryptoInfo['email_enc'] ?? null;
    $emailEncKeyVer = $cryptoInfo['email_enc_key_version'] ?? null;
} catch (\Throwable $e) {
    try { Logger::error('MailHelper::prepareEmailCrypto failed', null, ['exception' => (string)$e]); } catch (\Throwable $_) {}
    logAndRespond('Interná chyba pri spracovaní e-mailu. Kontaktujte administrátora.', 500, $e, ['email_hint' => emailHint($email)]);
}

// create confirm tokens (selector+validator) and unsubscribe token
$selector = bin2hex(random_bytes(6)); // 12 hex
$validator = random_bytes(32); // binary
$validatorHex = bin2hex($validator); // for URL

// --- confirm validator hash (REQUIRED: EMAIL_VERIFICATION_KEY via KeyManager) ---
$confirmValidatorHash = null;
$confirmValidatorKeyVer = null;
try {
    if (method_exists(KeyManager::class, 'deriveHmacWithLatest')) {
        $vinfo = KeyManager::deriveHmacWithLatest('EMAIL_VERIFICATION_KEY', KEYS_DIR, 'email_verification_key', $validator);
        if (!empty($vinfo['hash'])) {
            $confirmValidatorHash = $vinfo['hash'];
            $confirmValidatorKeyVer = $vinfo['version'] ?? null;
        }
    }

    if ($confirmValidatorHash === null && method_exists(KeyManager::class, 'getEmailVerificationKeyInfo')) {
        $ev = KeyManager::getEmailVerificationKeyInfo(KEYS_DIR);
        if (!empty($ev['raw']) && is_string($ev['raw']) && strlen($ev['raw']) === KeyManager::keyByteLen()) {
            $confirmValidatorHash = hash_hmac('sha256', $validator, $ev['raw'], true);
            $confirmValidatorKeyVer = $ev['version'] ?? null;
            try { KeyManager::memzero($ev['raw']); } catch (\Throwable $_) {}
        }
    }
} catch (\Throwable $e) {
    try { Logger::error('Confirm validator derive failed (subscribe)', null, ['exception' => (string)$e]); } catch (\Throwable $_) {}
}

// ENFORCE: if we don't have a confirm validator hash, abort subscribe (do not proceed)
if ($confirmValidatorHash === null) {
    try { Logger::error('Missing EMAIL_VERIFICATION_KEY — subscribe is disabled (subscribe attempt)'); } catch (\Throwable $_) {}
    // memzero sensitive stuff
    try { if (isset($validator)) KeyManager::memzero($validator); } catch (\Throwable $_) {}
    try { foreach ($emailHashes as $h) { if (is_string($h)) KeyManager::memzero($h); } } catch (\Throwable $_) {}
    subscribeFeedback('Interná chyba: overovací kľúč nie je k dispozícii. Kontaktujte administrátora.');
}

// unsubscribe token (binary) + its hash
$unsubscribeTokenBin = random_bytes(32);
$unsubscribeToken = bin2hex($unsubscribeTokenBin);
// --- unsubscribe token derive (REQUIRED: UNSUBSCRIBE_KEY via KeyManager) ---
$unsubscribeTokenHash = null;
$unsubscribeTokenKeyVer = null;
try {
    if (method_exists(KeyManager::class, 'deriveHmacWithLatest')) {
        $uinfo = KeyManager::deriveHmacWithLatest('UNSUBSCRIBE_KEY', KEYS_DIR, 'unsubscribe_key', $unsubscribeTokenBin);
        if (!empty($uinfo['hash'])) {
            $unsubscribeTokenHash = $uinfo['hash'];
            $unsubscribeTokenKeyVer = $uinfo['version'] ?? null;
        }
    }

    if ($unsubscribeTokenHash === null && method_exists(KeyManager::class, 'getUnsubscribeKeyInfo')) {
        $uv = KeyManager::getUnsubscribeKeyInfo(KEYS_DIR);
        if (!empty($uv['raw']) && is_string($uv['raw']) && strlen($uv['raw']) === KeyManager::keyByteLen()) {
            $unsubscribeTokenHash = hash_hmac('sha256', $unsubscribeTokenBin, $uv['raw'], true);
            $unsubscribeTokenKeyVer = $uv['version'] ?? null;
            try { KeyManager::memzero($uv['raw']); } catch (\Throwable $_) {}
        }
    }
} catch (\Throwable $e) {
    try { Logger::error('Unsubscribe token derive failed (subscribe)', null, ['exception' => (string)$e]); } catch (\Throwable $_) {}
}

// ENFORCE: if we don't have an unsubscribe token hash, abort subscribe (do not proceed)
if ($unsubscribeTokenHash === null) {
    try { Logger::error('Missing UNSUBSCRIBE_KEY — subscribe is disabled (subscribe attempt)'); } catch (\Throwable $_) {}
    try { if (isset($validator)) KeyManager::memzero($validator); } catch (\Throwable $_) {}
    try { if (isset($unsubscribeTokenBin)) KeyManager::memzero($unsubscribeTokenBin); } catch (\Throwable $_) {}
    try { foreach ($emailHashes as $h) { if (is_string($h)) KeyManager::memzero($h); } } catch (\Throwable $_) {}
    subscribeFeedback('Interná chyba: odhlasovací kľúč nie je k dispozícii. Kontaktujte administrátora.');
}

// confirm_expires: 48 hours UTC (microseconds)
$expiresAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+48 hours')->format('Y-m-d H:i:s.u');

// --- prepare origin + meta (minimální, kompatibilní s původní logikou) ---
$origin = trim((string)($_POST['origin'] ?? $_SERVER['HTTP_REFERER'] ?? ''));
if ($origin !== '') $origin = mb_substr($origin, 0, 200);
$meta = [
    'origin' => $origin !== '' ? $origin : null,
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
    'created_via' => 'subscribe.php',
];
$metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE);
if ($metaJson === false) {
    try { Logger::error('Failed to json_encode meta for newsletter_subscribers', $userId ?? null); } catch (\Throwable $_) {}
    $metaJson = json_encode(new \stdClass());
}

// -------------------- insert/update DB v transakci --------------------
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
        if ($emailEnc !== null) {
            $upd->bindValue(':email_enc', $emailEnc, \PDO::PARAM_LOB);
        } else {
            $upd->bindValue(':email_enc', null, \PDO::PARAM_NULL);
        }
        $upd->bindValue(':email_key_version', $emailEncKeyVer, $emailEncKeyVer !== null ? \PDO::PARAM_STR : \PDO::PARAM_NULL);
        $upd->bindValue(':email_hash', $emailHashBin, $emailHashBin !== null ? \PDO::PARAM_LOB : \PDO::PARAM_NULL);
        $upd->bindValue(':email_hash_key_version', $emailHashVer, $emailHashVer !== null ? \PDO::PARAM_STR : \PDO::PARAM_NULL);
        $upd->bindValue(':confirm_selector', $selector, \PDO::PARAM_STR);
        $upd->bindValue(':confirm_validator_hash', $confirmValidatorHash, \PDO::PARAM_LOB);
        $upd->bindValue(':confirm_key_version', $confirmValidatorKeyVer, $confirmValidatorKeyVer !== null ? \PDO::PARAM_STR : \PDO::PARAM_NULL);
        $upd->bindValue(':confirm_expires', $expiresAt, \PDO::PARAM_STR);
        $upd->bindValue(':unsubscribe_token_hash', $unsubscribeTokenHash, \PDO::PARAM_LOB);
        $upd->bindValue(':id', (int)$existing['id'], \PDO::PARAM_INT);
        $upd->bindValue(':unsubscribe_token_key_version', $unsubscribeTokenKeyVer, $unsubscribeTokenKeyVer !== null ? \PDO::PARAM_STR : \PDO::PARAM_NULL);
        $upd->bindValue(':origin', $origin !== '' ? $origin : null, $origin !== '' ? \PDO::PARAM_STR : \PDO::PARAM_NULL);
        if ($ipHashBin !== null) {
            $upd->bindValue(':ip_hash', $ipHashBin, \PDO::PARAM_LOB);
        } else {
            $upd->bindValue(':ip_hash', null, \PDO::PARAM_NULL);
        }
        $upd->bindValue(':ip_hash_key_version', $ipHashKeyVer, $ipHashKeyVer !== null ? \PDO::PARAM_STR : \PDO::PARAM_NULL);
        $upd->bindValue(':meta', $metaJson, \PDO::PARAM_STR);
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
        if ($emailEnc !== null) {
            $ins->bindValue(':email_enc', $emailEnc, \PDO::PARAM_LOB);
        } else {
            $ins->bindValue(':email_enc', null, \PDO::PARAM_NULL);
        }
        $ins->bindValue(':email_key_version', $emailEncKeyVer, $emailEncKeyVer !== null ? \PDO::PARAM_STR : \PDO::PARAM_NULL);
        $ins->bindValue(':email_hash', $emailHashBin, $emailHashBin !== null ? \PDO::PARAM_LOB : \PDO::PARAM_NULL);
        $ins->bindValue(':email_hash_key_version', $emailHashVer, $emailHashVer !== null ? \PDO::PARAM_STR : \PDO::PARAM_NULL);
        $ins->bindValue(':confirm_selector', $selector, \PDO::PARAM_STR);
        $ins->bindValue(':confirm_validator_hash', $confirmValidatorHash, \PDO::PARAM_LOB);
        $ins->bindValue(':confirm_key_version', $confirmValidatorKeyVer, $confirmValidatorKeyVer !== null ? \PDO::PARAM_STR : \PDO::PARAM_NULL);
        $ins->bindValue(':confirm_expires', $expiresAt, \PDO::PARAM_STR);
        $ins->bindValue(':unsubscribe_token_hash', $unsubscribeTokenHash, \PDO::PARAM_LOB);
        $ins->bindValue(':unsubscribe_token_key_version', $unsubscribeTokenKeyVer, $unsubscribeTokenKeyVer !== null ? \PDO::PARAM_STR : \PDO::PARAM_NULL);
        $ins->bindValue(':origin', $origin !== '' ? $origin : null, $origin !== '' ? \PDO::PARAM_STR : \PDO::PARAM_NULL);
        if ($ipHashBin !== null) {
            $ins->bindValue(':ip_hash', $ipHashBin, \PDO::PARAM_LOB);
        } else {
            $ins->bindValue(':ip_hash', null, \PDO::PARAM_NULL);
        }
        $ins->bindValue(':ip_hash_key_version', $ipHashKeyVer, $ipHashKeyVer !== null ? \PDO::PARAM_STR : \PDO::PARAM_NULL);
        $ins->bindValue(':meta', $metaJson, \PDO::PARAM_STR);
        $ins->execute();
        $nsId = (int)$pdo->lastInsertId();
    }

    $pdo->commit();
} catch (\Throwable $e) {
    try { if ($pdo->inTransaction()) $pdo->rollBack(); } catch (\Throwable $_) {}

    // memzero sensitive on error
    try {
        if (isset($validator)) KeyManager::memzero($validator);
    } catch (\Throwable $_) {}
    try {
        if (isset($unsubscribeTokenBin)) KeyManager::memzero($unsubscribeTokenBin);
    } catch (\Throwable $_) {}
    try { foreach ($emailHashes as $h) { if (is_string($h)) KeyManager::memzero($h); } } catch (\Throwable $_) {}
    try { if (isset($confirmValidatorHash)) KeyManager::memzero($confirmValidatorHash); } catch (\Throwable $_) {}
    try { if (isset($unsubscribeTokenHash)) KeyManager::memzero($unsubscribeTokenHash); } catch (\Throwable $_) {}
    try { if (isset($emailHashBin)) KeyManager::memzero($emailHashBin); } catch (\Throwable $_) {}

    logAndRespond(
        'Chyba pri spracovaní (server). Skúste neskôr.',
        500,
        $e,
        [
            'stage' => 'db_insert_update',
            'email_hint' => emailHint($email),
            'user_id' => $userId ?? null,
            'subscriber_try' => (is_array($existing) && isset($existing['id'])) ? (int)$existing['id'] : null
        ]
    );
}

// -------------------- cleanup sensitive variables (memzero + unset) --------------------
try {
    if (isset($validator)) { if (method_exists(KeyManager::class, 'memzero')) KeyManager::memzero($validator); unset($validator); }
} catch (\Throwable $_) {}
try {
    if (isset($unsubscribeTokenBin)) { if (method_exists(KeyManager::class, 'memzero')) KeyManager::memzero($unsubscribeTokenBin); unset($unsubscribeTokenBin); }
} catch (\Throwable $_) {}
try {
    foreach ($emailHashes as $h) { if (is_string($h) && method_exists(KeyManager::class, 'memzero')) KeyManager::memzero($h); }
} catch (\Throwable $_) {}
try {
    if (isset($confirmValidatorHash) && method_exists(KeyManager::class, 'memzero')) { KeyManager::memzero($confirmValidatorHash); unset($confirmValidatorHash); }
} catch (\Throwable $_) {}
try {
    if (isset($unsubscribeTokenHash) && method_exists(KeyManager::class, 'memzero')) { KeyManager::memzero($unsubscribeTokenHash); unset($unsubscribeTokenHash); }
} catch (\Throwable $_) {}
try {
    if (isset($emailHashBin) && method_exists(KeyManager::class, 'memzero')) { KeyManager::memzero($emailHashBin); unset($emailHashBin); }
} catch (\Throwable $_) {}
try {
    if (isset($emailEnc) && is_string($emailEnc) && method_exists(KeyManager::class, 'memzero')) { KeyManager::memzero($emailEnc); unset($emailEnc); }
} catch (\Throwable $_) {}

// -------------------- enqueue verification email (outside transaction) --------------------
try {
    if (class_exists(Mailer::class, true) && method_exists(Mailer::class, 'enqueue')) {
        $base = rtrim((string)($_ENV['APP_URL'] ?? ''), '/');
        $confirmUrl = $base . '/newsletter_confirm?selector=' . rawurlencode($selector) . '&validator=' . rawurlencode($validatorHex);
        $unsubscribeUrl = $base . '/newsletter_unsubscribe?token=' . rawurlencode($unsubscribeToken);

        // build notification payload via MailHelper (ensures consistency & validation)
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

        // --- validate notification payload (using Validator) ---
        // Validator::validateNotificationPayload expects a JSON string and template name
        $payloadForValidation = [
            'to' => $payloadArr['to'],
            'subject' => $payloadArr['subject'],
            'template' => $payloadArr['template'],
            'vars' => $payloadArr['vars'],
        ];
        $payloadJson = json_encode($payloadForValidation, JSON_UNESCAPED_UNICODE);
        if ($payloadJson === false || !Validator::NotificationPayload($payloadJson, $payloadArr['template'])) {
            try { Logger::error('Notification payload validation failed (subscribe)', $userId ?? null, ['subscriber_id'=>$nsId]); } catch (\Throwable $_) {}
            // nenahazujeme interní chybu uživateli — pošli uživatelskou odpověď
            subscribeFeedback('Záznam uložený, ale overovací e-mail nebol naplánovaný (payload invalid). Kontaktujte podporu.');
        }

        $notifId = Mailer::enqueue($payloadArr);
        try { Logger::systemMessage('notice', 'Newsletter confirm enqueued', $userId ?? null, ['subscriber_id' => $nsId, 'notification_id' => $notifId]); } catch (\Throwable $_) {}
        } else {
            try { Logger::warn('Mailer::enqueue not available; confirmation email not scheduled', $userId ?? null); } catch (\Throwable $_) {}
            subscribeFeedback('Záznam uložený. Overovací e-mail nebol naplánovaný (Mailer unavailable). Kontaktujte administrátora.');
        }

    } catch (\Throwable $e) {
        logAndRespond(
            'Záznam uložený, ale nepodarilo sa naplánovať overovací e-mail. Kontaktujte podporu.',
            202,
            $e,
            ['stage'=>'mailer_enqueue', 'subscriber_id'=>$nsId ?? null, 'email_hint'=>emailHint($email)]
        );
    }

// success
subscribeFeedback('Potvrďte odber kliknutím v e-maile.', true);
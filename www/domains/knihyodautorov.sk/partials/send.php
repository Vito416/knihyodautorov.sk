<?php
// send.php — contact form enqueued as user_id = 1 (with encryption like register.php)
declare(strict_types=1);

// najdi a include bootstrap (bezpečně)
$bootstrapPath = realpath(dirname(__DIR__, 1) . '/eshop/inc/bootstrap.php');
if ($bootstrapPath && is_file($bootstrapPath)) {
    require_once $bootstrapPath;
} else {
    // fallback: pokud bootstrap povinný, vyhodíme chybu
    throw new \RuntimeException('Bootstrap not found at expected path: ' . __DIR__ . '/eshop/inc/bootstrap.php');
}

// zajistíme, že $config je pole (bootstrap by ho mohl nastavit)
if (!isset($config) || !is_array($config)) {
    $config = [];
}

// bezpečné čtení reCAPTCHA hodnot
$secretKey = $config['capchav3']['secret_key'] ?? $_ENV['CAPCHA_SECRET_KEY'] ?? '';
$minScoreRaw = $config['capchav3']['min_score'] ?? $_ENV['CAPCHA_MIN_SCORE'] ?? 0.4;
// normalizace na float a bezpečnost (mez 0..1)
$minScore = (float) $minScoreRaw;
if ($minScore < 0 || $minScore > 1) $minScore = 0.4;

function respond(bool $success, string $message = ''): void {
    header('Content-Type: application/json; charset=UTF-8');
    $data = $success
        ? ['success' => true,  'message' => $message]
        : ['success' => false, 'error'   => $message];
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Neplatná metóda');
}

// inputs
$nameRaw  = (string)($_POST['name'] ?? '');
$emailRaw = (string)($_POST['email'] ?? '');
$msgRaw   = (string)($_POST['message'] ?? '');
$honeypot = trim((string)($_POST['website'] ?? ''));
$token    = $_POST['g-recaptcha-response'] ?? '';

// honeypot: ignore bots
if ($honeypot !== '') respond(true, 'OK');

// basic sanitize/validate
$name    = trim($nameRaw);
$email   = trim(strtolower($emailRaw));
$message = trim($msgRaw);

if ($name === '' || $email === '' || $message === '') {
    respond(false, 'Vyplňte všetky polia');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(false, 'Neplatný e-mail');
}

// length caps
$name    = mb_substr($name, 0, 250);
$email   = mb_substr($email, 0, 320);
$message = mb_substr($message, 0, 10000);

// --- compute client IP early (bugfix) ---
$clientIp = null;
if (class_exists('Logger') && method_exists('Logger', 'getClientIp')) {
    try {
        $clientIp = Logger::getClientIp();
    } catch (\Throwable $_) {
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
    }
} else {
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
}

// reCAPTCHA check — now uses $clientIp
if (!is_string($token) || $token === '') {
    respond(false, 'reCAPTCHA token chýba');
}

$ch = curl_init('https://www.google.com/recaptcha/api/siteverify');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query([
        'secret'   => $secretKey,
        'response' => $token,
        'remoteip' => $clientIp,
    ]),
    CURLOPT_TIMEOUT => 10,
]);
$response = curl_exec($ch);
curl_close($ch);

$res = json_decode($response, true);
if (empty($res['success']) || (($res['score'] ?? 0) < $minScore)) {
    respond(false, 'Overenie reCAPTCHA zlyhalo');
}

// basic mail/config
$fromEmail = $_ENV['SMTP_FROM_EMAIL'] ?? 'no-reply@' . ($_SERVER['SERVER_NAME'] ?? 'example.com');
$fromName  = $_ENV['SMTP_FROM_NAME'] ?? 'Web Kontakt';
$toEmail   = $_ENV['SMTP_TO_MAIL'] ?? 'info@' . ($_SERVER['SERVER_NAME'] ?? 'example.com');

if (!$toEmail) {
    respond(false, 'Cieľový e-mail nie je nastavený');
}

$safeName = preg_replace("/[\r\n]+/", ' ', $name);
$subject = 'Kontakt z webu: ' . mb_substr($safeName, 0, 80);

// prepare vars for email template
$vars = [
    'name'    => $name,
    'email'   => $email,
    'message' => $message,
    'ip'      => $clientIp,
    'site'    => $_SERVER['SERVER_NAME'] ?? '',
];

// --- derive email HMAC + optional encryption (like register.php) ---
$emailHashBin = null;
$emailHashVer = null;
$emailEnc = null;
$emailEncKeyVer = null;

try {
    if (class_exists('KeyManager') && method_exists('KeyManager', 'deriveHmacWithLatest') && defined('KEYS_DIR')) {
        $hinfo = KeyManager::deriveHmacWithLatest('EMAIL_HASH_KEY', KEYS_DIR, 'email_hash_key', $email);
        $emailHashBin = $hinfo['hash'] ?? null;
        $emailHashVer = $hinfo['version'] ?? null;
    }
} catch (\Throwable $e) {
    if (class_exists('Logger')) {
        try { Logger::error('Derive email hash failed on contact form', null, ['exception' => (string)$e]); } catch (\Throwable $_) {}
    }
}

try {
    if (class_exists('Crypto') && method_exists('Crypto', 'initFromKeyManager') && defined('KEYS_DIR')) {
        Crypto::initFromKeyManager(KEYS_DIR);
        $emailEnc = Crypto::encrypt($email, 'binary'); // binary like register.php
        if (method_exists('KeyManager', 'locateLatestKeyFile')) {
            $info = KeyManager::locateLatestKeyFile(KEYS_DIR, 'email_key');
            $emailEncKeyVer = $info['version'] ?? null;
        }
        Crypto::clearKey();
    }
} catch (\Throwable $e) {
    if (class_exists('Logger')) {
        try { Logger::error('Email encryption failed on contact form', null, ['exception' => (string)$e]); } catch (\Throwable $_) {}
    }
}

// --- use fixed user_id = 1 for contact notifications ---
$userId = 1;

// build payload (same shape as register.php)
$payloadArr = [
    'user_id' => $userId,
    'to'      => $toEmail,
    'subject' => $subject,
    'template'=> 'contact_admin', // přizpůsobte podle Vaší šablony
    'vars'    => $vars,
    'attachments' => [
        [
            'type' => 'inline_remote',
            'src'  => 'https://knihyodautorov.sk/assets/logo.png',
            'name' => 'logo.png',
            'cid'  => 'logo'
        ]
    ],
    'meta'    => [
        'email_key_version' => $emailEncKeyVer ?? null,
        'email_hash_key_version' => $emailHashVer ?? null,
        'cipher_format' => $emailEnc !== null ? 'aead_xchacha20poly1305_v1_binary' : null,
        'source' => 'contact_form',
        'remote_ip' => $clientIp,
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
    ],
];

if ($emailEnc !== null) {
    // base64-encoded encrypted email (worker dekóduje/dešifruje podle meta.key_version)
    $payloadArr['meta']['email_enc_b64'] = base64_encode($emailEnc);
}

// enqueue via Mailer
try {
    if (!class_exists('Mailer') || !method_exists('Mailer', 'enqueue')) {
        if (class_exists('Logger')) {
            try { Logger::warn('Mailer::enqueue not available for contact form', null, ['payload_subject' => $subject]); } catch (\Throwable $_) {}
        }
        respond(true, 'Ďakujeme — správa bola prijatá, avšak e-mail nebol naplánovaný (Mailer unavailable).');
    }

    $notifId = Mailer::enqueue($payloadArr);

    if (class_exists('Logger')) {
        try { Logger::systemMessage('notice', 'Contact email enqueued', $userId, ['notification_id' => $notifId]); } catch (\Throwable $_) {}
    }

    // best-effort memzero of sensitive blobs
    try {
        if (class_exists('KeyManager') && method_exists('KeyManager', 'memzero')) {
            if (is_string($emailEnc)) KeyManager::memzero($emailEnc);
            if (is_string($emailHashBin)) KeyManager::memzero($emailHashBin);
        }
    } catch (\Throwable $_) {}

    respond(true, 'Ďakujeme — správa bola uložená do fronty');

} catch (\InvalidArgumentException $iae) {
    // Mailer::enqueue requires valid user_id; zalogujeme a vrátíme uživateli úspěch s varováním (fallback)
    if (class_exists('Logger')) {
        try { Logger::systemMessage('error', 'Mailer enqueue rejected payload (invalid user_id)', null, ['exception' => $iae->getMessage()]); } catch (\Throwable $_) {}
    }
    respond(true, 'Ďakujeme — správa bola prijatá, ale e-mail nebol naplánovaný (notify user_id missing).');
} catch (\Throwable $e) {
    if (class_exists('Logger')) {
        try { Logger::systemError($e); } catch (\Throwable $_) {}
    } else {
        error_log('Mailer enqueue error: ' . $e->getMessage());
    }
    respond(false, 'Naplánovanie e-mailu zlyhalo');
}
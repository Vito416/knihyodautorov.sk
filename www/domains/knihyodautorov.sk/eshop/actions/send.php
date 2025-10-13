<?php
declare(strict_types=1);

// actions/send.php
// PURE handler — neprovádí header()/echo/exit — vrací array s odpovědí.
// Očekává vyextrahované proměnné (front controller musí poskytnout):
//   $config (array), $Logger (string class-name) optional,
//   $MailHelper (string class-name), $Mailer (string class-name),
//   $Recaptcha (string class-name) optional, $KEYS_DIR (string|null) optional

// fallbacky
if (!isset($config) || !is_array($config)) $config = [];
$Logger = $Logger ?? null;
$MailHelper = $MailHelper ?? \BlackCat\Core\Helpers\MailHelper::class;
$Mailer = $Mailer ?? \BlackCat\Core\Mail\Mailer::class;
$RecaptchaClass = $Recaptcha ?? \BlackCat\Core\Security\Recaptcha::class;
$KEYS_DIR = $KEYS_DIR ?? (defined('KEYS_DIR') ? KEYS_DIR : null);

// helper: uniform JSON response
$resp = function(int $status, array $json = [], array $headers = []) {
    return ['status' => $status, 'headers' => $headers, 'json' => $json];
};

// simple logger wrapper (supports class-name static calls)
$logError = function(string $msg, array $ctx = []) use ($Logger) {
    if (is_string($Logger) && class_exists($Logger)) {
        try { $Logger::error($msg, null, $ctx); } catch (\Throwable $_) {}
    } else {
        // fallback
        error_log($msg . ' ' . json_encode($ctx));
    }
};
$logWarn = function(string $msg, array $ctx = []) use ($Logger) {
    if (is_string($Logger) && class_exists($Logger)) {
        try { $Logger::warn($msg, null, $ctx); } catch (\Throwable $_) {}
    } else {
        error_log($msg . ' ' . json_encode($ctx));
    }
};

// only allow POST
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    return $resp(405, ['success' => false, 'message' => 'Method Not Allowed']);
}

// read inputs safely
$nameRaw  = (string)($_POST['name'] ?? '');
$emailRaw = (string)($_POST['email'] ?? '');
$msgRaw   = (string)($_POST['message'] ?? '');
$honeypot = trim((string)($_POST['website'] ?? ''));
$token    = (string)($_POST['g-recaptcha-response'] ?? '');

// honeypot
if ($honeypot !== '') {
    // respond success to hide bot probing
    return $resp(200, ['success' => true, 'message' => 'OK']);
}
// if CSRF class passed as $CSRF, use it to verify; otherwise fall back to $csrf token value
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
// sanitize/validate
$name = trim($nameRaw);
$email = trim(strtolower($emailRaw));
$message = trim($msgRaw);
if ($name === '' || $email === '' || $message === '') {
    return $resp(400, ['success' => false, 'message' => 'Vyplňte všechny pole']);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    return $resp(400, ['success' => false, 'message' => 'Neplatný e-mail']);
}

// length caps
$name = mb_substr($name, 0, 250);
$email = mb_substr($email, 0, 320);
$message = mb_substr($message, 0, 10000);

// client IP (bezpečně)
$clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
if (is_string($Logger) && class_exists($Logger) && method_exists($Logger, 'getClientIp')) {
    try { $clientIp = $Logger::getClientIp(); } catch (\Throwable $_) {}
}

// rate-limit (file-based simple, můžes nahradit Redis)
$ipHash = hash('sha256', $clientIp ?: 'unknown');
$key = sys_get_temp_dir() . '/contact_rl_' . $ipHash;
$ttl = 600; $max = 5;
$state = ['count'=>0,'ts'=>time()];

// robustní file-based rate limit s flock
$fp = @fopen($key, 'c+');
if ($fp) {
    try {
        if (flock($fp, LOCK_EX)) {
            // načti existující obsah
            rewind($fp);
            $raw = stream_get_contents($fp);
            $arr = @json_decode((string)$raw, true);
            if (is_array($arr) && isset($arr['count']) && isset($arr['ts'])) $state = $arr;

            // TTL logic
            if ($state['ts'] + $ttl < time()) $state = ['count' => 0, 'ts' => time()];

            $state['count']++;

            // zapiš atomicky
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($state));
            fflush($fp);
            flock($fp, LOCK_UN);
        }
    } catch (\Throwable $_) {
        // best-effort fallback: ignoruj chybu, necháme implicitní chování
    } finally {
        fclose($fp);
    }
} else {
    // fallback bez flocku (best-effort)
    if (is_file($key)) {
        $raw = @file_get_contents($key);
        $arr = @json_decode((string)$raw, true);
        if (is_array($arr) && isset($arr['count']) && isset($arr['ts'])) $state = $arr;
    }
    if ($state['ts'] + $ttl < time()) $state = ['count'=>0,'ts'=>time()];
    $state['count']++;
    @file_put_contents($key, json_encode($state));
}

if ($state['count'] > $max) {
    return $resp(429, ['success' => false, 'message' => 'Příliš mnoho požadavků, zkuste později.']);
}

// recaptcha check — pokud poskytnut secret v $config nebo .env, použij Recaptcha class
$secretKey = $config['capchav3']['secret_key'] ?? $_ENV['CAPCHA_SECRET_KEY'] ?? '';
$minScoreRaw = $config['capchav3']['min_score'] ?? $_ENV['CAPCHA_MIN_SCORE'] ?? 0.4;
$minScore = (float)$minScoreRaw; if ($minScore < 0 || $minScore > 1) $minScore = 0.4;
if ($token === '') {
    return $resp(400, ['success' => false, 'message' => 'reCAPTCHA token chybí']);
}
if ($secretKey === '') {
    $logError('reCAPTCHA secret missing in config/ENV');
    return $resp(500, ['success' => false, 'message' => 'Serverová konfigurace chybí']);
}

try {
    // $RecaptchaClass je string s FQCN - konstruktor: (secret, minScore, opts)
    $rec = new $RecaptchaClass($secretKey, $minScore, ['logger' => is_string($Logger) ? $Logger : null]);
    $recResult = $rec->verify($token, $clientIp);
} catch (\Throwable $e) {
    $logError('Recaptcha verification exception', ['ex' => (string)$e]);
    return $resp(500, ['success' => false, 'message' => 'Chyba při ověření reCAPTCHA']);
}
if (!is_array($recResult) || empty($recResult['ok'])) {
    $logWarn('reCAPTCHA failed', ['rec' => $recResult]);
    return $resp(400, ['success' => false, 'message' => 'Overenie reCAPTCHA zlyhalo']);
}

// build payload for MailHelper
$subject = 'Kontakt z webu: ' . preg_replace("/[\r\n]+/", ' ', mb_substr($name, 0, 80));
$vars = ['name'=>$name,'email'=>$email,'message'=>$message,'ip'=>$clientIp,'site'=>$_SERVER['SERVER_NAME'] ?? ''];

$attachments = [
    [
        'type'=>'inline_remote',
        'src'=>'https://knihyodautorov.sk/assets/logo.png',
        'name'=>'logo.png',
        'cid'=>'logo',
    ]
];
// whitelist hosts
$whitelist = ['knihyodautorov.sk','www.knihyodautorov.sk','cdn.knihyodautorov.sk'];
$outAtt = [];
foreach ($attachments as $a) {
    $p = @parse_url($a['src']);
    $h = isset($p['host']) ? strtolower($p['host']) : '';
    if ($h !== '' && in_array($h, $whitelist, true)) $outAtt[] = $a;
}

// mail helper opts
$opts = [
    'name'=>$name,
    'email'=>$email,
    'message'=>$message,
    'to'=>($_ENV['SMTP_TO_MAIL'] ?? ('info@' . ($_SERVER['SERVER_NAME'] ?? 'example.com'))),
    'subject'=>$subject,
    'user_id'=>1,
    'attachments'=>$outAtt,
    'client_ip'=>$clientIp,
    'user_agent'=>$_SERVER['HTTP_USER_AGENT'] ?? null,
    'site'=>$_SERVER['SERVER_NAME'] ?? null,
    'keysDir'=>$KEYS_DIR ?? null,
    'source'=>'contact_form',
];

try {
    $payload = $MailHelper::buildContactPayload($opts);
} catch (\Throwable $e) {
    $logError('MailHelper::buildContactPayload failed', ['ex'=>(string)$e]);
    return $resp(500, ['success'=>false,'message'=>'Nepodarilo sa pripraviť e-mail']);
}

try {
    $notifId = $Mailer::enqueue($payload);
    return $resp(200, ['success'=>true,'message'=>'Ďakujeme — správa bola uložená do fronty','notification_id'=>$notifId]);
} catch (\Throwable $e) {
    $logError('Mailer::enqueue failed', ['ex'=>(string)$e]);
    return $resp(500, ['success'=>false,'message'=>'Naplánovanie e-mailu zlyhalo']);
}
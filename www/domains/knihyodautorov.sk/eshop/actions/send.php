<?php
declare(strict_types=1);

// actions/send.php - revised
// PURE handler — neprovádí header()/echo/exit — vrací array s odpovědí.

// --- Expected shared vars (frontcontroller) ---
// $config (array), $Logger (class-string|null),
// $MailHelper (class-string), $Mailer (class-string),
// $Recaptcha (class-string) optional, $KEYS_DIR optional
// $CSRF or $csrf may or may not be present depending on routes share spec.

// safe defaults to avoid notices
if (!isset($config) || !is_array($config)) $config = [];
$Logger = $Logger ?? null;
$MailHelper = $MailHelper ?? \BlackCat\Core\Helpers\MailHelper::class;
$Mailer = $Mailer ?? \BlackCat\Core\Mail\Mailer::class;
$RecaptchaClass = $Recaptcha ?? \BlackCat\Core\Security\Recaptcha::class;
$KEYS_DIR = $KEYS_DIR ?? (defined('KEYS_DIR') ? KEYS_DIR : null);

// ensure CSRF vars exist to avoid notices
$CSRF = $CSRF ?? null;   // class-string or object (if provided)
$csrf  = $csrf  ?? null; // legacy single-token value (string|null)

// helper: uniform JSON response
$resp = function(int $status, array $json = [], array $headers = []) {
    return ['status' => $status, 'headers' => $headers, 'json' => $json];
};

// logger wrappers (class-name static or fallback error_log)
$logError = function(string $msg, array $ctx = []) use ($Logger) {
    if (is_string($Logger) && class_exists($Logger)) {
        try { $Logger::error($msg, null, $ctx); } catch (\Throwable $_) {}
    } else {
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

// simple honeypot
if ($honeypot !== '') {
    // respond success to hide bot probing
    return $resp(200, ['success' => true, 'message' => 'OK']);
}

// posted CSRF token (from form)
$postedCsrf = (string)($_POST['csrf'] ?? '');

// default invalid
$csrfOk = false;

// If CSRF class/object passed, try to validate with it
if (!empty($CSRF)) {
    $csrfClass = is_string($CSRF) ? $CSRF : (is_object($CSRF) ? get_class($CSRF) : null);
    if ($csrfClass && class_exists($csrfClass)) {
        if (method_exists($csrfClass, 'validate')) {
            try {
                $csrfOk = (bool) $csrfClass::validate($postedCsrf);
            } catch (\Throwable $_) {
                $csrfOk = false;
            }
        } elseif (method_exists($csrfClass, 'verify')) {
            try {
                $csrfOk = (bool) $csrfClass::verify($postedCsrf);
            } catch (\Throwable $_) {
                $csrfOk = false;
            }
        } else {
            $csrfOk = false;
        }
    }
} else {
    // legacy single-token fallback — use hash_equals to avoid timing leaks
    if (is_string($csrf) && $postedCsrf !== '') {
        try {
            $csrfOk = hash_equals((string)$csrf, $postedCsrf);
        } catch (\Throwable $_) {
            $csrfOk = ($postedCsrf === $csrf); // fallback
        }
    }
}

// block if CSRF failed
if (!$csrfOk) {
    return $resp(400, ['success' => false, 'message' => 'CSRF token invalid']);
}

// sanitize/validate inputs
$name = trim(strip_tags($nameRaw));
$email = trim($emailRaw);
$message = trim($msgRaw);

// basic required checks
if ($name === '' || $email === '' || $message === '') {
    return $resp(400, ['success' => false, 'message' => 'Vyplňte všechny pole']);
}

// protect against header injection/newlines in name/email
if (preg_match("/[\r\n]/", $name) || preg_match("/[\r\n]/", $email)) {
    return $resp(400, ['success' => false, 'message' => 'Neplatné znaky v polích']);
}

// normalize email: lowercase domain part (preserve local part)
if (strpos($email, '@') !== false) {
    [$local, $domain] = explode('@', $email, 2);
    $email = $local . '@' . strtolower($domain);
} else {
    $email = strtolower($email);
}

// validate email
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    return $resp(400, ['success' => false, 'message' => 'Neplatný e-mail']);
}

// length caps
$name = mb_substr($name, 0, 250);
$email = mb_substr($email, 0, 320);
$message = mb_substr($message, 0, 10000);

// client IP detection (best-effort)
$clientIp = '';
// prefer Logger helper if available, otherwise look at X-Forwarded-For then REMOTE_ADDR
if (is_string($Logger) && class_exists($Logger) && method_exists($Logger, 'getClientIp')) {
    try { $clientIp = $Logger::getClientIp(); } catch (\Throwable $_) { $clientIp = ''; }
}
if ($clientIp === '') {
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $clientIp = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    } else {
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
    }
}

// rate-limit (file-based simple, with app identifier)
$appIdent = $config['app_key'] ?? ($config['app_name'] ?? 'app');
$ipHash = hash('sha256', ($clientIp ?: 'unknown'));
$key = sys_get_temp_dir() . '/contact_rl_' . hash('sha256', $appIdent . '|' . $ipHash);
$ttl = 600; $max = 5;
$state = ['count'=>0,'ts'=>time()];

// robust file-based rate limit with flock
$fp = @fopen($key, 'c+');
if ($fp) {
    try {
        if (flock($fp, LOCK_EX)) {
            rewind($fp);
            $raw = stream_get_contents($fp);
            $arr = @json_decode((string)$raw, true);
            if (is_array($arr) && isset($arr['count']) && isset($arr['ts'])) $state = $arr;

            if ($state['ts'] + $ttl < time()) $state = ['count' => 0, 'ts' => time()];
            $state['count']++;

            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($state));
            fflush($fp);
            flock($fp, LOCK_UN);
        }
    } catch (\Throwable $_) {
        // best-effort: ignore and continue
    } finally {
        fclose($fp);
    }
} else {
    // fallback without lock
    if (is_file($key)) {
        $raw = @file_get_contents($key);
        $arr = @json_decode((string)$raw, true);
        if (is_array($arr) && isset($arr['count']) && isset($arr['ts'])) $state = $arr;
    }
    if ($state['ts'] + $ttl < time()) $state = ['count'=>0,'ts'=>time()];
    $state['count']++;
    @file_put_contents($key, json_encode($state), LOCK_EX);
}

if ($state['count'] > $max) {
    return $resp(429, ['success' => false, 'message' => 'Příliš mnoho požadavků, zkuste později.']);
}

// reCAPTCHA: secret from config or ENV
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
    // instantiate recaptcha verifier (best-effort)
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

// prepare mail payload
$subjectName = preg_replace("/[\r\n]+/", ' ', mb_substr($name, 0, 80));
$subject = 'Kontakt z webu: ' . $subjectName;

$siteHost = $config['app_domain'] ?? '';
$toMail = $_ENV['SMTP_TO_MAIL'] ?? ($config['smtp_to'] ?? null);
if (empty($toMail)) {
    // fallback to info@<siteHost> only if siteHost is trustworthy
    if (!empty($siteHost)) {
        $toMail = 'info@' . preg_replace('/[^a-z0-9\.\-]/i', '', $siteHost);
    } else {
        $toMail = 'info@example.com';
    }
}

$vars = ['name'=>$name,'email'=>$email,'message'=>$message,'ip'=>$clientIp,'site'=>$siteHost];

$attachments = [
    [
        'type'=>'inline_remote',
        'src'=>'https://knihyodautorov.sk/assets/logo.png',
        'name'=>'logo.png',
        'cid'=>'logo',
    ]
];
// whitelist hosts for remote attachments
$whitelist = ['knihyodautorov.sk','www.knihyodautorov.sk','cdn.knihyodautorov.sk'];
$outAtt = [];
foreach ($attachments as $a) {
    $p = @parse_url($a['src']);
    $h = isset($p['host']) ? strtolower($p['host']) : '';
    if ($h !== '' && in_array($h, $whitelist, true)) $outAtt[] = $a;
}

$opts = [
    'name'=>$name,
    'email'=>$email,
    'message'=>$message,
    'to'=>$toMail,
    'subject'=>$subject,
    'user_id'=>1,
    'attachments'=>$outAtt,
    'client_ip'=>$clientIp,
    'user_agent'=>$_SERVER['HTTP_USER_AGENT'] ?? null,
    'site'=>$siteHost,
    'keysDir'=>$KEYS_DIR ?? null,
    'source'=>'contact_form',
];

// ensure MailHelper and Mailer exist and expose expected methods
if (!(is_string($MailHelper) && class_exists($MailHelper) && method_exists($MailHelper, 'buildContactPayload'))) {
    $logError('MailHelper missing or invalid', ['MailHelper'=>$MailHelper]);
    return $resp(500, ['success'=>false,'message'=>'Serverová mail helper chyba']);
}
if (!(is_string($Mailer) && class_exists($Mailer) && method_exists($Mailer, 'enqueue'))) {
    $logError('Mailer missing or invalid', ['Mailer'=>$Mailer]);
    return $resp(500, ['success'=>false,'message'=>'Serverový mailer chýba']);
}

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
<?php
declare(strict_types=1);

/**
 * smtp-debug.php
 *
 * Debug helper for Mailer / SMTP configuration.
 *
 * Usage:
 *  - place in webroot or cron dir (adjust require path below)
 *  - call in browser: smtp-debug.php?token=LONG_TOKEN&debug=1
 *  - optional GET overrides: host, port, user, pass, from_email, secure, timeout
 *  - put to /cron if you don´t want to adjust paths...
 * Output: JSON. When debug=1 also echoes human readable logs.
 */

// ----------------- CONFIG - adjust paths if needed -----------------
$EXPECTED_TOKEN = 'YOUR_LONG_TOKEN'; // change before production
$BOOTSTRAP_PATH = __DIR__ . '/../eshop/inc/bootstrap.php'; // adjust if needed
// -----------------------------------------------------------------

$debugHtml = isset($_GET['debug']) && $_GET['debug'] == '1';

function out($data) {
    global $debugHtml;
    if ($debugHtml) {
        echo '<pre>' . htmlspecialchars(print_r($data, true)) . "</pre>\n";
    }
}

$response = [
    'ok' => false,
    'config' => null,
    'checks' => [],
    'phpmailer_debug' => [],
    'errors' => [],
];

function addCheck($name, $ok, $msg = null, $meta = null) {
    global $response;
    $response['checks'][] = ['name' => $name, 'ok' => (bool)$ok, 'message' => $msg, 'meta' => $meta];
}

// ----------------- TOKEN -----------------
$token = $_GET['token'] ?? null;
if ($token === null || !hash_equals($EXPECTED_TOKEN, $token)) {
    http_response_code(403);
    echo json_encode(['error' => 'invalid token']);
    exit;
}

// ----------------- TRY LOAD BOOTSTRAP -----------------
$config = [];
$pdo = null;
if (file_exists($BOOTSTRAP_PATH)) {
    try {
        require_once $BOOTSTRAP_PATH; // should set $config and maybe $pdo / $db in your app
        // common variable names: $config, $smtpCfg, $db, $pdo
    } catch (\Throwable $e) {
        $response['errors'][] = 'Bootstrap include failed: ' . $e->getMessage();
    }
} else {
    $response['errors'][] = "Bootstrap not found at {$BOOTSTRAP_PATH}";
}

// prefer $config['smtp'] from bootstrap if present
$smtpCfg = $_GET; // defaults from GET (easier to override)
if (!empty($config['smtp']) && is_array($config['smtp'])) {
    $smtpCfg = array_merge($smtpCfg, $config['smtp']);
}
// also allow $config variable named otherwise
if (empty($smtpCfg) && isset($config)) {
    // fallback if config populated differently
    $smtpCfg = $config['smtp'] ?? $smtpCfg;
}

// normalize values (GET overrides)
$host = trim((string)($smtpCfg['host'] ?? ($_GET['host'] ?? '')));
$port = (int)($smtpCfg['port'] ?? ($_GET['port'] ?? 0));
$user = (string)($smtpCfg['user'] ?? ($_GET['user'] ?? ''));
$pass = (string)($smtpCfg['pass'] ?? ($_GET['pass'] ?? ''));
$from = trim((string)($smtpCfg['from_email'] ?? ($_GET['from_email'] ?? ($user ?: ''))));
$secure = strtolower(trim((string)($smtpCfg['secure'] ?? ($_GET['secure'] ?? '')))); // '', 'ssl', 'tls'
$timeout = max(1, (int)($smtpCfg['timeout'] ?? ($_GET['timeout'] ?? 10)));
$peerName = (string)($smtpCfg['peer_name'] ?? ($_GET['peer_name'] ?? $host));
$cafile = $smtpCfg['cafile'] ?? ($_GET['cafile'] ?? null);

$response['config'] = [
    'host' => $host,
    'port' => $port,
    'user' => $user,
    'pass_provided' => $pass !== '' ? true : false, // don't reveal password
    'from' => $from,
    'secure' => $secure,
    'timeout' => $timeout,
    'peer_name' => $peerName,
    'cafile' => $cafile,
];

// ----------------- BASIC VALIDATIONS -----------------
if ($host === '') {
    addCheck('smtp_host', false, 'SMTP host is empty');
} else {
    addCheck('smtp_host', true, 'Host provided', ['host' => $host]);
}

if ($port === 0) {
    // default ports
    $port = ($secure === 'ssl') ? 465 : (($secure === 'tls') ? 587 : 25);
    addCheck('smtp_port_defaulted', true, 'Port defaulted based on secure', ['port' => $port]);
} else {
    addCheck('smtp_port', true, 'Port provided', ['port' => $port]);
}

if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
    addCheck('from_email', false, 'From email invalid or empty', ['from' => $from]);
} else {
    addCheck('from_email', true, 'From email appears valid', ['from' => $from]);
}

// ----------------- TCP CONNECTIVITY (basic) -----------------
$connectOk = false;
$tcpErr = null;
$addr = $host . ':' . $port;
$out = '';
$start = microtime(true);
$fp = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, max(3, $timeout));
$elapsed = microtime(true) - $start;
if ($fp !== false) {
    $connectOk = true;
    fclose($fp);
    addCheck('tcp_connect', true, "TCP connect OK ({$addr})", ['elapsed_s' => $elapsed]);
} else {
    $tcpErr = trim((string)($errstr ?: 'unknown'));
    addCheck('tcp_connect', false, "TCP connect FAILED ({$addr})", ['error' => $tcpErr, 'elapsed_s' => $elapsed]);
}

// ----------------- DNS MX check for FROM domain -----------------
$fromDomain = '';
if ($from !== '' && filter_var($from, FILTER_VALIDATE_EMAIL)) {
    $parts = explode('@', $from, 2);
    $fromDomain = $parts[1] ?? '';
    if ($fromDomain !== '') {
        $hasMx = checkdnsrr($fromDomain, 'MX');
        if ($hasMx) {
            addCheck('dns_mx_from', true, "MX record found for {$fromDomain}");
        } else {
            // also check A/AAAA
            $hasA = checkdnsrr($fromDomain, 'A') || checkdnsrr($fromDomain, 'AAAA');
            addCheck('dns_mx_from', $hasA, $hasA ? "No MX but A/AAAA exists for {$fromDomain}" : "No MX/A/AAAA for {$fromDomain}");
        }
    }
}

// ----------------- PHPMailer connect & auth test -----------------
$outLines = [];
function phpmailerDebugCallback($str, $level) {
    global $outLines;
    $outLines[] = trim($str);
}

// try to load PHPMailer
$pmAvailable = class_exists('\PHPMailer\PHPMailer\PHPMailer');
if (!$pmAvailable) {
    $response['errors'][] = 'PHPMailer class not available. Ensure vendor/autoload.php or PHPMailer is loaded by bootstrap.';
    addCheck('phpmailer', false, 'PHPMailer not available');
} else {
    // create instance and attempt connect
    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->SMTPDebug = 3; // verbose
        $mail->Debugoutput = 'phpmailerDebugCallback';
        $mail->Timeout = $timeout;
        $mail->isSMTP();
        $mail->Host = $host;
        $mail->Port = $port;
        $mail->SMTPAutoTLS = ($secure === 'tls');
        // set secure
        if ($secure === 'ssl') {
            if (defined('\PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS')) {
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            } else {
                $mail->SMTPSecure = 'ssl';
            }
        } elseif ($secure === 'tls') {
            if (defined('\PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS')) {
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                $mail->SMTPSecure = 'tls';
            }
        } else {
            $mail->SMTPSecure = '';
        }

        $mail->SMTPAuth = $user !== '';
        if ($mail->SMTPAuth) {
            $mail->Username = $user;
            $mail->Password = $pass;
        }

        // SMTPOptions for TLS / cafile
        $smtpOptions = [
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
                'peer_name' => $peerName,
            ],
        ];
        if (!empty($cafile)) $smtpOptions['ssl']['cafile'] = $cafile;
        $mail->SMTPOptions = $smtpOptions;

        // envelope/from and recipients (dummy) - avoid actually sending mail
        $mail->Sender = $from;
        $mail->setFrom($from, 'Debug Sender');
        $mail->addAddress($from); // send to self to test RCPT (won't actually send)

        // Try low-level connect/authenticate without sending full message
        $connected = false;
        try {
            if ($mail->smtpConnect()) {
                $connected = true;
                addCheck('phpmailer_smtp_connect', true, 'PHPMailer smtpConnect succeeded');
            } else {
                addCheck('phpmailer_smtp_connect', false, 'PHPMailer smtpConnect returned false');
            }
        } catch (\PHPMailer\PHPMailer\Exception $e) {
            addCheck('phpmailer_smtp_connect', false, 'PHPMailer smtpConnect exception', ['message' => $e->getMessage()]);
            $response['errors'][] = 'PHPMailer connect exception: ' . $e->getMessage();
        } catch (\Throwable $e) {
            addCheck('phpmailer_smtp_connect', false, 'PHPMailer smtpConnect exception', ['message' => $e->getMessage()]);
            $response['errors'][] = 'PHPMailer connect exception: ' . $e->getMessage();
        }

        // If connected and SMTPAuth requested, check authentication state by attempting noop or MAIL FROM
        if ($connected) {
            // PHPMailer performs auth during smtpConnect() if SMTPAuth true and credentials set.
            // So if smtpConnect succeeded we assume auth ok. But double-check server 235 in debug output.
            $debugStr = implode("\n", $outLines);
            $authOk = (stripos($debugStr, '235') !== false) || (stripos($debugStr, 'Authentication successful') !== false);
            // also detect "535" or "Authentication failed"
            $authFail = (stripos($debugStr, '535') !== false) || (stripos($debugStr, 'Authentication failed') !== false) || (stripos($debugStr, 'Username and Password not accepted') !== false);

            if ($mail->SMTPAuth) {
                if ($authOk && !$authFail) {
                    addCheck('smtp_auth', true, 'SMTP authentication appears successful (based on server response)');
                } elseif ($authFail) {
                    addCheck('smtp_auth', false, 'SMTP authentication failed (server responded with auth error)');
                } else {
                    // undetermined - but smtpConnect succeeded
                    addCheck('smtp_auth', true, 'smtpConnect succeeded; authentication likely OK (no explicit auth failure in debug)');
                }
            } else {
                addCheck('smtp_auth', true, 'SMTPAuth not configured (no credentials provided)');
            }
            // close connection
            try { $mail->smtpClose(); } catch (\Throwable $_) {}
        }

        // stash debug lines
        $response['phpmailer_debug'] = $outLines;
    } catch (\Throwable $e) {
        $response['errors'][] = 'PHPMailer setup error: ' . $e->getMessage();
        addCheck('phpmailer_setup', false, $e->getMessage());
    }
}

// if any major check failed, keep ok=false
$ok = true;
foreach ($response['checks'] as $c) {
    if (!$c['ok']) { $ok = false; break; }
}
if (!empty($response['errors'])) $ok = false;
$response['ok'] = $ok;

// show results
header('Content-Type: application/json; charset=UTF-8');
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
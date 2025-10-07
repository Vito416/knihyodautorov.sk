<?php
declare(strict_types=1);

// ---------------------------------------------
// public/worker-trigger.php
// Spouští cron/Worker přes HTTP GET
// Použití: public URL s ?token=LONG_SECRET_TOKEN&immediate=1&debug=1
// ---------------------------------------------
require_once realpath(dirname(__DIR__, 1) . '/eshop/inc/bootstrap.php');
require_once realpath(dirname(__DIR__, 4) . '/cron/Worker.php');

header('Content-Type: application/json; charset=UTF-8');

// -------------------------------------------------
// CONFIG
// -------------------------------------------------
$expectedToken = $_ENV['CRON_TOKEN'] ?? '';
$immediate = isset($_GET['immediate']) && $_GET['immediate'] == '1';
$debug = isset($_GET['debug']) && $_GET['debug'] == '1';

$response = [
    'lock' => null,
    'notifications' => null,
    'rotation_jobs' => null,
    'cleanup_notifications_deleted' => null,
    'cleanup_sessions_deleted' => null,
    'gopay_notify' => null,
    'report' => null,
    'errors' => [],
    'logs' => [],
];

// -------------------------------------------------
// VALIDATE TOKEN
// -------------------------------------------------
$token = $_GET['token'] ?? null;
if ($token === null || !hash_equals($expectedToken, $token)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid token'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// -------------------------------------------------
// VERBOSE ERROR HANDLER
// -------------------------------------------------
set_error_handler(function($errno, $errstr, $errfile, $errline) use (&$response, $debug) {
    $msg = "PHP ERROR [$errno] $errstr in $errfile:$errline";
    $response['errors'][] = $msg;
    if ($debug) echo $msg . "<br>\n";
    return true; // zabránit default handleru
});

set_exception_handler(function($e) use (&$response, $debug) {
    $msg = "UNCAUGHT EXCEPTION: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine();
    $response['errors'][] = $msg;
    if ($debug) echo $msg . "<br>\n";
});

// -------------------------------------------------
// DEBUG LOGGER
// -------------------------------------------------
function debugLogger($level, $message, $userId = null, $context = null) {
    global $response, $debug;
    $entry = ['level'=>$level,'message'=>$message];
    if ($userId !== null) $entry['user_id'] = $userId;
    if ($context !== null) $entry['context'] = $context;
    $response['logs'][] = $entry;

    if ($debug) {
        echo strtoupper($level) . ': ' . $message;
        if ($context !== null) echo ' | ' . print_r($context,true);
        echo "<br>\n";
    }
}

// -------------------------------------------------
// OVERRIDE Logger FOR VERBOSE
// -------------------------------------------------
if (!class_exists('Logger')) {
    class Logger {
        private static $callback;
        public static function setCallback($cb) { self::$callback = $cb; }
        public static function systemMessage($level, $msg, $userId = null, $ctx = null) {
            if (is_callable(self::$callback)) call_user_func(self::$callback, $level, $msg, $userId, $ctx);
        }
        public static function systemError($e) {
            if (is_callable(self::$callback)) {
                call_user_func(self::$callback, 'error', $e->getMessage(), null, ['exception'=>$e]);
            }
        }
    }

    // Tady už globálně voláme Logger::setCallback
    Logger::setCallback('debugLogger');
}


// -------------------------------------------------
// BOOTSTRAP Worker
// -------------------------------------------------
try {
    Worker::init($db, $gopayWrapper, $gopayAdapter);
} catch (\Throwable $e) {
    $response['errors'][] = 'Bootstrap failed: ' . $e->getMessage();
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// -------------------------------------------------
// ATOMIC LOCK
// -------------------------------------------------
$lockName = 'public_cron_trigger';
try {
    if (!Worker::lock($lockName, 600)) { // 10 minut TTL
        $response['lock'] = 'already_running';
        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
    $response['lock'] = 'acquired';
} catch (\Throwable $e) {
    $response['errors'][] = 'Lock acquisition failed: ' . $e->getMessage();
}

// -------------------------------------------------
// RUN NOTIFICATIONS
// -------------------------------------------------
try {
    $response['notifications'] = Worker::notification(100, $immediate);
} catch (\Throwable $e) {
    $response['errors'][] = 'Notifications failed: ' . $e->getMessage();
}

// -------------------------------------------------
// RUN NOTIFICATIONS
// -------------------------------------------------
try {
    $response['rotation_jobs'] = Worker::runPendingKeyRotationJobs(5);
} catch (\Throwable $e) {
    $response['errors'][] = 'RotationJobs failed: ' . $e->getMessage();
}

//$keys = [    'password_pepper',    'app_salt',    'session_key',    'ip_hash_key',    'csrf_key',    'jwt_key',    'email_key',    'email_hash_key',    'email_verification_key',    'unsubscribe_key',    'profile_crypto',];

//foreach ($keys as $basename) {    $jobId = Worker::scheduleKeyRotation($basename);    echo "Scheduled rotation for {$basename}, job ID: {$jobId}\n";}

// -------------------------------------------------
// CLEANUP old notifications (sent > 30d)
// -------------------------------------------------
try {
    $response['cleanup_notifications_deleted'] = Worker::cleanupNotifications(30);
} catch (\Throwable $e) {
    $response['errors'][] = 'Cleanup of notifications failed: ' . $e->getMessage();
}

// -------------------------------------------------
// CLEANUP old sessions
// -------------------------------------------------
try {
    $response['cleanup_sessions_deleted'] = Worker::cleanupSessions(24, 90);
} catch (\Throwable $e) {
    $response['errors'][] = 'Cleanup of sessions failed: ' . $e->getMessage();
}

// -------------------------------------------------
// Handle GoPay notify queue
// -------------------------------------------------
try {
    $response['gopay_notify'] = Worker::processGoPayNotify(5, 120);
} catch (\Throwable $e) {
    $response['errors'][] = 'Processing GoPay notifications failed: ' . $e->getMessage();
}

// -------------------------------------------------
// UNLOCK
// -------------------------------------------------
try {
    Worker::unlock($lockName);
} catch (\Throwable $e) {
    $response['errors'][] = 'Unlock failed: ' . $e->getMessage();
}

// -------------------------------------------------
// FINAL OUTPUT JSON
// -------------------------------------------------
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
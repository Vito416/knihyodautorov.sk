<?php
declare(strict_types=1);

/**
 * actions/login.php
 * Strict handler — používá výhradně předané sdílené proměnné.
 *
 * Required shared keys (frontcontroller MUST pass):
 *   - Auth, SessionManager, KeyManager, Logger, CSRF, db, KEYS_DIR
 *
 * Response format: JSON only (respondJson)
 */

function respondJson(array $payload, int $status = 200): void {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    http_response_code($status);
    if (!isset($payload['success']) || $payload['success'] !== true) {
        $payload['csrfToken'] = \BlackCat\Core\Security\CSRF::token();
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

// --- require strictly injected shared variables ---
$required = ['Auth', 'SessionManager', 'KeyManager', 'Logger', 'CSRF', 'db', 'KEYS_DIR'];
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

// --- helper closures ---
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
    if (is_string($injected) && class_exists($injected)) return $injected;
    if (is_object($injected)) return $injected;
    return null;
};

$loggerInvoke = function(?string $method, string $msg, $userId = null, array $ctx = []) use (&$Logger, $call, $resolveTarget) {
    if (empty($Logger)) return;
    try {
        $target = $resolveTarget($Logger);
        if ($target === null) return;
        if (is_string($target) && method_exists($target, $method)) {
            return $call($target, $method, [$msg, $userId, $ctx]);
        }
        if (is_object($target) && method_exists($target, $method)) {
            return $target->{$method}($msg, $userId, $ctx);
        }
    } catch (\Throwable $_) {}
};

$safeMemzero = function(&$buf) use (&$KeyManager, $call): void {
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
            foreach ($buf as &$v) $v = null;
            unset($v);
        } else {
            $buf = null;
        }
    } catch (\Throwable $_) {}
};

// --- GET: return template render parameters ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (!isset($csrfToken) || !is_string($csrfToken) || $csrfToken === '') {
        $loggerInvoke('systemError', 'login: missing csrfToken in trustedShared', null);
        return [
            'template' => 'pages/error.php',
            'vars' => ['message' => 'Interná chyba (CSRF token chýba).'],
            'status' => 500,
        ];
    }
    return [
        'template' => 'pages/login.php',
        'vars' => [
            'pageTitle' => 'Prihlásenie',
            'csrfToken' => $csrfToken,
        ],
    ];
}

// --- POST (login request) ---
$emailRaw = (string)($_POST['email'] ?? '');
$password = (string)($_POST['password'] ?? '');
$csrf = $_POST['csrf'] ?? null;

$email = strtolower(trim($emailRaw));

// --- validate CSRF ---
if ($call($CSRF, 'validate', [$csrf]) !== true) {
    respondJson([
        'success' => false,
        'errors'  => ['csrf' => 'Neplatný CSRF token.'],
        'pref'    => ['email' => $emailRaw],
    ], 400);
}

// --- basic validation ---
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respondJson([
        'success' => false,
        'errors'  => ['email' => 'Neplatný e-mail.'],
        'pref'    => ['email' => $emailRaw],
    ], 400);
}

if ($password === '') {
    respondJson([
        'success' => false,
        'errors'  => ['password' => 'Zadajte heslo.'],
        'pref'    => ['email' => $emailRaw],
    ], 400);
}

// --- get PDO ---
$pdo = null;
if ($db instanceof \PDO) {
    $pdo = $db;
} elseif (is_object($db) && method_exists($db, 'getPdo')) {
    $maybe = $db->getPdo();
    if ($maybe instanceof \PDO) $pdo = $maybe;
}
if (!($pdo instanceof \PDO)) {
    $loggerInvoke('systemError', 'login: PDO not available', null);
    respondJson(['success' => false, 'message' => 'Interná chyba (DB).'], 500);
}

// --- perform login ---
try {
    $result = $call($Auth, 'login', [$pdo, $email, $password, 5]);
    if (!is_array($result) || empty($result['success'])) {
        respondJson([
            'success' => false,
            'errors'  => ['credentials' => $result['message'] ?? 'Nesprávny e-mail alebo heslo.'],
            'pref'    => ['email' => $emailRaw],
        ], 401);
    }

    $user = $result['user'] ?? null;
    $userId = is_array($user) && isset($user['id']) ? (int)$user['id'] : null;
    if ($userId === null) {
        $loggerInvoke('systemError', 'login: Auth success without userId', null);
        respondJson(['success' => false, 'message' => 'Interná chyba pri autentifikácii.'], 500);
    }

    // --- create session ---
    $token = $call($SessionManager, 'createSession', [$pdo, $userId, 30, true, 'Lax']);
    if (empty($token)) {
        respondJson(['success' => false, 'message' => 'Nepodarilo sa vytvoriť reláciu.'], 500);
    }

    $safeMemzero($password);
    $loggerInvoke('auth', 'login_success', $userId);

    respondJson(['success' => true, 'message' => 'Úspešne prihlásený.'], 200);
} catch (\Throwable $e) {
    $loggerInvoke('systemError', 'login: exception', null, ['ex' => (string)$e]);
    respondJson(['success' => false, 'message' => 'Chyba pri prihlásení (server).'], 500);
}
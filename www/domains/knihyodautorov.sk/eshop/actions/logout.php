<?php
declare(strict_types=1);

/**
 * actions/logout.php
 * Strict logout handler — používá výhradně předané shared proměnné.
 *
 * Required shared keys (frontcontroller MUST pass):
 *   - SessionManager, Logger, CSRF, db
 *
 * Response format: JSON only (respondJson)
 */

// --- response helper ---
function respondJson(array $payload, int $status = 200): void {
    if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
    http_response_code($status);
    try { $payload['csrfToken'] = \BlackCat\Core\Security\CSRF::token(); } catch (\Throwable $_) {}
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

// --- require injected dependencies ---
$required = ['SessionManager','Logger','CSRF','db'];
$missing = [];
foreach ($required as $r) { if (!isset($$r)) $missing[] = $r; }
if (!empty($missing)) respondJson(['ok'=>false,'error'=>'Interná konfigurácia chýba: '.implode(', ',$missing)],500);

// --- helpers: call, resolveTarget, loggerInvoke ---
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
        if (is_string($target) && method_exists($target, $method)) return $call($target, $method, [$msg,$userId,$ctx]);
        if (is_object($target) && method_exists($target, $method)) return $target->{$method}($msg,$userId,$ctx);
    } catch (\Throwable $_) {}
    return null;
};

// --- only POST ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respondJson(['ok'=>false,'error'=>'method_not_allowed'],405);
}

// --- CSRF ---
$csrfToken = $_POST['csrf'] ?? null;
$csrfValid = $call($CSRF, 'validate', [$csrfToken]);
if ($csrfValid !== true) {
    $loggerInvoke('warn','logout: CSRF validation failed', $_SESSION['user_id'] ?? null);
    respondJson(['ok'=>false,'error'=>'Neplatný CSRF token'],400);
}

// --- destroy session ---
try {
    $call($SessionManager,'destroySession', [$db]);
} catch (\Throwable $e) {
    $loggerInvoke('systemError','logout exception: '.(string)$e, $_SESSION['user_id'] ?? null);
    respondJson(['ok'=>false,'error'=>'Server error při odhlášení.'],500);
}

// --- respond success ---
respondJson(['ok'=>true,'message'=>'Odhlášení proběhlo úspěšně']);
<?php
declare(strict_types=1);

// actions/csrf_token.php
// PURE handler — vrací ['status'=>int,'json'=>array] pro frontcontroller.
// Očekává, že frontcontroller spustí bootstrap (session/CSRF class loaded).

// fallback helpers
$resp = function(int $status, array $json = [], array $headers = []) {
    return ['status' => $status, 'json' => $json, 'headers' => $headers];
};

try {
    if (!class_exists(\BlackCat\Core\Security\CSRF::class, true)) {
        return $resp(500, ['success' => false, 'message' => 'CSRF not available']);
    }

    // vytáhnout token (CSRF::token() se postará o session / storage)
    $token = \BlackCat\Core\Security\CSRF::token();
    if (!is_string($token) || $token === '') {
        return $resp(500, ['success' => false, 'message' => 'Failed to generate token']);
    }

    return $resp(200, ['success' => true, 'token' => $token]);
} catch (\Throwable $e) {
    // logujeme pokud Logger existuje v scope (frontcontroller obvykle poskytne)
    if (isset($Logger) && is_string($Logger) && class_exists($Logger)) {
        try { $Logger::error('csrf_token handler exception', ['ex' => (string)$e]); } catch (\Throwable $_) {}
    }
    return $resp(500, ['success' => false, 'message' => 'Server error']);
}
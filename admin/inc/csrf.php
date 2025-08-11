<?php
// /admin/inc/csrf.php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();

/**
 * Jednoduchý CSRF: token uložený do SESSION
 */
function csrf_get_token(): string {
    if (empty($_SESSION['adm_csrf_token'])) {
        $_SESSION['adm_csrf_token'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['adm_csrf_token'];
}

function csrf_check_token(?string $token): bool {
    if (empty($token)) return false;
    if (empty($_SESSION['adm_csrf_token'])) return false;
    return hash_equals($_SESSION['adm_csrf_token'], (string)$token);
}

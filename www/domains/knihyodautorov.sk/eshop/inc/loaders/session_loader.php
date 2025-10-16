<?php

declare(strict_types=1);

use BlackCat\Core\Log\Logger;
use BlackCat\Core\Session\SessionManager;

/**
 * inc/loaders/session_cookie_defaults.php
 *
 * Sets default session cookie parameters (secure, httponly, samesite)
 * Call BEFORE session_start()
 */

function setSessionCookieDefaults(): void
{
    $defaults = session_get_cookie_params();

    $proto = strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');
    $secure = $proto === 'https' || (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
    $httponly = true;
    $samesite = 'Lax'; // nebo 'Strict' podle potřeby
    $lifetime = 0; // until browser closes
    $path = '/';
    $domain = $_ENV['SESSION_DOMAIN'] ?? ($_SERVER['HTTP_HOST'] ?? '');

    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path'     => $path,
            'domain'   => $domain,
            'secure'   => $secure,
            'httponly' => $httponly,
            'samesite' => $samesite,
        ]);
    } else {
        // fallback pro PHP <7.3
        session_set_cookie_params(
            $lifetime,
            $path . '; samesite=' . $samesite,
            $domain,
            $secure,
            $httponly
        );
    }

    // Optional: set a custom session name
    if (!session_id()) {
        session_name('ESHOPSESSID');
    }
}

/**
 * inc/loaders/session_loader.php
 *
 * Provides: init_session_and_restore(PDO|Database $db): ?int
 * - ensures session is started
 * - calls SessionManager::validateSession($db) when available
 * - returns userId (int) or null
 */

function init_session_and_restore($db): ?int
{
    // ensure session cookie defaults are applied if helper exists (best-effort)
    if (function_exists('setSessionCookieDefaults')) {
        try {
            setSessionCookieDefaults();
        } catch (\Throwable $_) {}
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        // suppress potential warnings on strict hosts
        try { session_start(); } catch (\Throwable $_) {}
    }

    $userId = null;
    // Prefer SessionManager API if present
    if (class_exists(SessionManager::class, true) && method_exists(SessionManager::class, 'validateSession')) {
        try {
            // SessionManager::validateSession expects Database or PDO
            $userId = SessionManager::validateSession($db);
        } catch (\Throwable $e) {
            if (class_exists(Logger::class, true)) {
                try { Logger::systemError($e, null); } catch (\Throwable $_) {}
            }
            $userId = null;
        }
    } else {
        // fallback: try to restore from plain $_SESSION['user_id']
        if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['user_id'])) {
            $userId = (int) $_SESSION['user_id'];
        }
    }

    return $userId;
}
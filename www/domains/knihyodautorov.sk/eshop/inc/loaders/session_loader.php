<?php

declare(strict_types=1);

use BlackCat\Core\Log\Logger;
use Psr\SimpleCache\CacheInterface;
use BlackCat\Core\Session\SessionManager;
use BlackCat\Core\Session\DbCachedSessionHandler;

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
 * Provides: init_session_and_restore(PDO|Database $db, ?Psr\SimpleCache\CacheInterface $cache = null, int $cacheTtl = 120): ?int
 * - ensures session is started
 * - optionally initializes SessionManager cache
 * - calls SessionManager::validateSession($db) when available
 * - returns userId (int) or null
 *
 * Backwards compatible: callers not providing $cache still work.
 */

function init_session_and_restore($db, ?CacheInterface $cache = null, int $cacheTtl = 120): ?int
{
    // ensure session cookie defaults are applied if helper exists (best-effort)
    if (function_exists('setSessionCookieDefaults')) {
        try {
            setSessionCookieDefaults();
        } catch (\Throwable $_) {}
    }

    // Create and register DB-cached session handler if available — fail-safe
    $handlerRegistered = false;
    if (class_exists(DbCachedSessionHandler::class, true)) {
        try {
            $handler = new DbCachedSessionHandler($db, $cache);
            session_set_save_handler($handler, true);
            $handlerRegistered = true;
        } catch (\Throwable $e) {
            // Log but continue — don't break bootstrap if handler fails to init
            if (class_exists(Logger::class, true)) {
                try { Logger::systemError($e, null); } catch (\Throwable $_) {}
            } else {
                error_log('DbCachedSessionHandler init failed: ' . $e->getMessage());
            }
        }
    } else {
        // handler class missing — optional, continue without it
        if (class_exists(Logger::class, true)) {
            try { Logger::systemMessage('info', 'DbCachedSessionHandler class not present - using default PHP session handler', null, ['component'=>'session_loader']); } catch (\Throwable $_) {}
        }
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        // If headers already sent, starting session will fail — log and continue as guest.
        if (headers_sent($file, $line)) {
            if (class_exists(Logger::class, true)) {
                try { Logger::systemMessage('warning', 'Cannot start session: headers already sent', null, ['file'=>$file,'line'=>$line]); } catch (\Throwable $_) {}
            } else {
                error_log("Cannot start session: headers already sent in $file:$line");
            }
        } else {
            // suppress PHP warnings (session_start emits warnings, not exceptions)
            @session_start();
        }
    }

    // If a cache was provided, initialize SessionManager cache (best-effort)
    if ($cache !== null) {
        try {
            if (class_exists(SessionManager::class, true) && method_exists(SessionManager::class, 'initCache')) {
                SessionManager::initCache($cache, max(0, (int)$cacheTtl));
            }
        } catch (\Throwable $e) {
            if (class_exists(Logger::class, true)) {
                try { Logger::systemError($e, null); } catch (\Throwable $_) {}
            } else {
                error_log('SessionManager::initCache failed: ' . $e->getMessage());
            }
        }
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
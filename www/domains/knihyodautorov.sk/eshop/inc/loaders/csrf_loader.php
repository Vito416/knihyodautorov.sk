<?php
declare(strict_types=1);

use BlackCat\Core\Security\CSRF;
use Psr\SimpleCache\CacheInterface;
use Psr\Log\LoggerInterface;
use BlackCat\Core\Log\Logger;
/**
 * inc/loaders/csrf_loader.php
 *
 * Ensures CSRF is initialized with the current $_SESSION reference.
 * Accepts optional PSR-16 cache instance (e.g. FileCache) and optional Logger.
 * Safe to call multiple times (idempotent).
 *
 * Usage:
 *   init_csrf_from_session($csrfFileCache, $loggerShim);
 */

function init_csrf_from_session(?CacheInterface $cache = null, ?LoggerInterface $logger = null): bool
{
    if (!class_exists(CSRF::class, true)) {
        return false;
    }

    // ensure session started
    if (session_status() !== PHP_SESSION_ACTIVE) {
        try { session_start(); } catch (\Throwable $_) {}
    }
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return false;
    }

    try {
        // attach logger if provided (idempotent)
        if ($logger !== null) {
            try {
                CSRF::setLogger($logger);
            } catch (\Throwable $_) {
                // ignore
            }
        }

        // pass reference to the real session array
        $ref = &$_SESSION;

        // call init with cache if provided; keep existing behaviour if already init'd
        if ($cache instanceof CacheInterface) {
            try {
                CSRF::init($ref, null, null, $cache);
            } catch (\Throwable $e) {
                // fallback: try init without cache to avoid breaking the app
                try { CSRF::init($ref); } catch (\Throwable $_) {}
            }
        } else {
            try {
                CSRF::init($ref);
            } catch (\Throwable $e) {
                // if init fails, log and continue (we don't want to break whole bootstrap)
                error_log('CSRF::init failed: ' . $e->getMessage());
            }
        }

        // cheap cleanup (best-effort)
        try {
            if (method_exists(CSRF::class, 'cleanup')) {
                CSRF::cleanup();
            }
        } catch (\Throwable $_) {
            // ignore
        }

        return true;
    } catch (\Throwable $e) {
        error_log('init_csrf_from_session error: ' . $e->getMessage());
        if (class_exists(Logger::class, true) && method_exists(Logger::class, 'systemError')) {
            try { Logger::systemError($e); } catch (\Throwable $_) {}
        }
        return false;
    }
}

// --- register CSRF cleanup on shutdown (placed in csrf_loader.php, executed when loader is included) ---
if (!defined('CSRF_CLEANUP_SHUTDOWN_REGISTERED')) {
    define('CSRF_CLEANUP_SHUTDOWN_REGISTERED', true);

    register_shutdown_function(function(): void {
        try {
            if (class_exists(CSRF::class, true)
                && method_exists(CSRF::class, 'cleanup')) {
                CSRF::cleanup();
            }
        } catch (\Throwable $e) {
            // best-effort logging; swallow errors to avoid breaking shutdown
            if (class_exists(Logger::class, true) && method_exists(Logger::class, 'systemMessage')) {
                try {
                    Logger::systemMessage('warning', 'CSRF::cleanup() failed on shutdown', null, ['exception' => $e]);
                } catch (\Throwable $_) {
                    // swallow
                }
            }
        }
    });
}
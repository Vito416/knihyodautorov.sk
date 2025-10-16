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

function init_csrf_from_session(?CacheInterface $cache = null, ?LoggerInterface $logger = null): void
{
    if (!class_exists(CSRF::class, true)) {
        return;
    }

    // ensure session started
    if (session_status() !== PHP_SESSION_ACTIVE) {
        try { session_start(); } catch (\Throwable $_) {}
    }
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    try {
        // if logger provided, hand it to CSRF (idempotent)
        if ($logger !== null) {
            try {
                CSRF::setLogger($logger);
            } catch (\Throwable $_) {
                // ignore non-fatal
            }
        }

        // pass reference to the real session array (preserves reference semantics)
        $ref = &$_SESSION;

        // If cache provided and is a PSR-16 implementation, pass it to init()
        if ($cache instanceof CacheInterface) {
            CSRF::init($ref, null, null, $cache);
        } else {
            // no cache given -> init session-only behavior
            CSRF::init($ref);
        }

        // optional cheap cleanup
        if (method_exists(CSRF::class, 'cleanup')) {
            CSRF::cleanup();
        }
    } catch (\Throwable $e) {
        // use error_log to avoid dependency on Logger class here
        error_log('init_csrf_from_session error: ' . $e->getMessage());
        if (class_exists(Logger::class, true) && method_exists(Logger::class, 'systemError')) {
            try {Logger::systemError($e); } catch (\Throwable $_) {}
        }
    }
}
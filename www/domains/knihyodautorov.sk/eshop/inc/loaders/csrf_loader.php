<?php

declare(strict_types=1);

use BlackCat\Core\Security\CSRF;
use BlackCat\Core\Log\Logger;

/**
 * inc/loaders/csrf_loader.php
 *
 * Provides: init_csrf_from_session(): void
 * - calls CSRF::init($_SESSION) if class exists and session active
 * - silently continues on error (best-effort)
 */

function init_csrf_from_session(): void
{
    // Allow both namespaced CSRF class and (rare) legacy global CSRF class.
    if (!class_exists(CSRF::class, true) && !class_exists('CSRF', true)) {
        return;
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        try { session_start(); } catch (\Throwable $_) {}
    }

    if (session_status() !== PHP_SESSION_ACTIVE) return;

    try {
        $ref = &$_SESSION;

        // prefer FQCN if available, else fallback to global legacy name
        $csrfClass = class_exists(CSRF::class, true) ? CSRF::class : 'CSRF';

        if (method_exists($csrfClass, 'init')) {
            $csrfClass::init($ref);
        }
        if (method_exists($csrfClass, 'cleanup')) {
            $csrfClass::cleanup();
        }
    } catch (\Throwable $e) {
        if (class_exists(Logger::class, true) && method_exists(Logger::class, 'systemError')) {
            try { Logger::systemError($e); } catch (\Throwable $_) {}
        } else {
            // last resort: do not expose sensitive details in shared hosting logs
            error_log('init_csrf_from_session error: ' . $e->getMessage());
        }
        // continue as guest
    }
}
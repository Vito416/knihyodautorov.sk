<?php
declare(strict_types=1);

/**
 * inc/loaders/csrf_loader.php
 *
 * Provides: init_csrf_from_session(): void
 * - calls CSRF::init($_SESSION) if class exists and session active
 * - silently continues on error (best-effort)
 */

function init_csrf_from_session(): void
{
    if (!class_exists('CSRF')) return;

    if (session_status() !== PHP_SESSION_ACTIVE) {
        try { session_start(); } catch (\Throwable $_) {}
    }

    if (session_status() !== PHP_SESSION_ACTIVE) return;

    try {
        $ref = &$_SESSION;
        if (method_exists('CSRF', 'init')) {
            CSRF::init($ref);
        }
        if (method_exists('CSRF', 'cleanup')) {
            // cleanup expired tokens gently
            CSRF::cleanup();
        }
    } catch (\Throwable $e) {
        if (class_exists('Logger')) {
            try { Logger::systemError($e); } catch (\Throwable $_) {}
        }
        // continue as guest
    }
}
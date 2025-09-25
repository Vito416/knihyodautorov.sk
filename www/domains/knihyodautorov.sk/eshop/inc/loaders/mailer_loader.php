<?php
declare(strict_types=1);

/**
 * inc/loaders/mailer_loader.php
 *
 * Safe Mailer initialization helper.
 *
 * Usage in bootstrap:
 *   require_once __DIR__ . '/loaders/mailer_loader.php';
 *   init_mailer_from_config($config, $database->getPdo());
 */

function init_mailer_from_config(array $config, ?PDO $pdo = null): void
{
    // best-effort: don't fatally break app if mailer or deps missing
    if (!class_exists('Mailer')) {
        // nothing to do
        return;
    }

    try {
        // Mailer::init expects (array $config, PDO $pdo)
        if ($pdo === null) {
            // if user passed Database instance instead of PDO, try to extract PDO
            if (isset($GLOBALS['database']) && is_object($GLOBALS['database']) && method_exists($GLOBALS['database'], 'getPdo')) {
                $pdo = $GLOBALS['database']->getPdo();
            } else {
                // attempt to use Database singleton if available
                if (class_exists('Database') && Database::isInitialized()) {
                    $pdo = Database::getInstance()->getPdo();
                }
            }
        }

        if (!($pdo instanceof PDO)) {
            // cannot initialize mailer without PDO; log and exit loader gracefully
            if (class_exists('Logger')) {
                try { Logger::systemMessage('warning', 'Mailer init skipped: PDO not available'); } catch (\Throwable $_) {}
            } else {
                error_log('[mailer_loader] PDO not provided - skipping Mailer::init');
            }
            return;
        }

        Mailer::init($config, $pdo);
        if (class_exists('Logger')) {
            try { Logger::systemMessage('notice', 'Mailer initialized'); } catch (\Throwable $_) {}
        }
    } catch (\Throwable $e) {
        // log error but do not abort bootstrap
        if (class_exists('Logger')) {
            try { Logger::systemError($e); } catch (\Throwable $_) {}
            try { Logger::systemMessage('error', 'Mailer init failed (non-fatal)'); } catch (\Throwable $_) {}
        } else {
            error_log('[mailer_loader] Mailer init failed: ' . $e->getMessage());
        }
    }
}
<?php
declare(strict_types=1);

use BlackCat\Core\Database;
use BlackCat\Core\Log\Logger;
use BlackCat\Core\Mail\Mailer;

/**
 * Safe Mailer initialization helper.
 *
 * Usage:
 *   init_mailer_from_config($config, $pdoOrDbOrOpts = null);
 *
 * Accepts:
 *   - ?\PDO instance
 *   - Database wrapper instance (with getPdo())
 *   - array $opts where $opts['trustedShared']['db'] or $opts['db'] may provide DB
 *
 * Behavior: best-effort, never throws. Logs warnings/errors if Logger present.
 */
function init_mailer_from_config(array $config, $pdoOrDbOrOpts = null): void
{
    // If Mailer class is not available, nothing to do.
    if (!class_exists(Mailer::class, true)) {
        return;
    }

    $pdo = null;

    try {
        // 1) If explicit PDO provided
        if ($pdoOrDbOrOpts instanceof \PDO) {
            $pdo = $pdoOrDbOrOpts;
        }

        // 2) If Database wrapper provided
        if ($pdo === null && is_object($pdoOrDbOrOpts)) {
            if (method_exists($pdoOrDbOrOpts, 'getPdo')) {
                try { $pdo = $pdoOrDbOrOpts->getPdo(); } catch (\Throwable $_) { $pdo = null; }
            } elseif ($pdoOrDbOrOpts instanceof Database) {
                try { $pdo = $pdoOrDbOrOpts->getPdo(); } catch (\Throwable $_) { $pdo = null; }
            }
        }

        // 3) If $opts array was passed, check for trustedShared/db
        if ($pdo === null && is_array($pdoOrDbOrOpts)) {
            $ts = $pdoOrDbOrOpts['trustedShared'] ?? $pdoOrDbOrOpts['ts'] ?? null;
            $candidate = $pdoOrDbOrOpts['db'] ?? $ts['db'] ?? null;
            if ($candidate instanceof \PDO) {
                $pdo = $candidate;
            } elseif (is_object($candidate) && method_exists($candidate, 'getPdo')) {
                try { $pdo = $candidate->getPdo(); } catch (\Throwable $_) { $pdo = null; }
            }
        }

        // 4) Try globally provided $database (legacy), or Database singleton (best-effort)
        if ($pdo === null) {
            if (isset($GLOBALS['database']) && is_object($GLOBALS['database']) && method_exists($GLOBALS['database'], 'getPdo')) {
                try { $pdo = $GLOBALS['database']->getPdo(); } catch (\Throwable $_) { $pdo = null; }
                // log legacy usage
                if (class_exists(Logger::class, true)) {
                    try { Logger::systemMessage('warning', 'Mailer init using legacy $GLOBALS[\"database\"]', null, ['component'=>'mailer_loader']); } catch (\Throwable $_) {}
                }
            } elseif (class_exists(Database::class, true)) {
                try {
                    // Database::isInitialized may or may not exist; be defensive
                    $useSingleton = method_exists(Database::class, 'isInitialized') ? Database::isInitialized() : true;
                    if ($useSingleton) {
                        $dbInst = Database::getInstance();
                        if (is_object($dbInst) && method_exists($dbInst, 'getPdo')) {
                            try { $pdo = $dbInst->getPdo(); } catch (\Throwable $_) { $pdo = null; }
                        }
                    }
                } catch (\Throwable $_) {
                    // ignore — cannot obtain singleton PDO
                }
            }

            // If we ended up using $GLOBALS['pdo'] as absolute last resort, log warning
            if ($pdo === null && isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof \PDO) {
                $pdo = $GLOBALS['pdo'];
                if (class_exists(Logger::class, true)) {
                    try { Logger::systemMessage('warning', 'Mailer init using legacy $GLOBALS[\"pdo\"] fallback', null, ['component'=>'mailer_loader']); } catch (\Throwable $_) {}
                }
            }
        }

        // 5) If still no PDO -> skip initialization but log
        if (!($pdo instanceof \PDO)) {
            if (class_exists(Logger::class, true)) {
                try { Logger::systemMessage('warning', 'Mailer init skipped: PDO not available', null, ['component'=>'mailer_loader']); } catch (\Throwable $_) {}
            } else {
                error_log('[mailer_loader] PDO not provided - skipping Mailer::init');
            }
            return;
        }

        // Finally, attempt to init Mailer (best-effort)
        Mailer::init($config, $pdo);

    } catch (\Throwable $e) {
        // log error but do not abort bootstrap
        if (class_exists(Logger::class, true)) {
            try { Logger::systemError($e, null, null, ['component'=>'mailer_loader']); } catch (\Throwable $_) {}
            try { Logger::systemMessage('error', 'Mailer init failed (non-fatal)', null, ['exception' => (string)$e]); } catch (\Throwable $_) {}
        } else {
            error_log('[mailer_loader] Mailer init failed: ' . $e->getMessage());
        }
    }
}
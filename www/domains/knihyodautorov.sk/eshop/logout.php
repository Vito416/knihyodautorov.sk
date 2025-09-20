<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';

/**
 * logout.php
 *
 * Ukončení uživatelské session:
 * - zneplatnění tokenu v DB
 * - vymazání cookie
 * - vymazání $_SESSION
 * - audit log (Logger::session)
 */

try {
    $db = Database::getInstance();
} catch (\Throwable $e) {
    if (class_exists('Logger')) { try { Logger::systemError($e); } catch (\Throwable $_) {} }
    $db = null;
}

// zničit session (i v DB)
try {
    SessionManager::destroySession($db);
} catch (\Throwable $e) {
    if (class_exists('Logger')) { try { Logger::systemError($e); } catch (\Throwable $_) {} }
}

// redirect na login nebo homepage
header('Location: index.php');
exit;
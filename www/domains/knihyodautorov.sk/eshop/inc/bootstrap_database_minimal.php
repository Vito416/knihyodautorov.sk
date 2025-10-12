<?php

declare(strict_types=1);

use BlackCat\Core\Database;

$PROJECT_ROOT = realpath(dirname(__DIR__, 5));
if ($PROJECT_ROOT === false) {
    error_log('[bootstrap_minimal] Cannot resolve PROJECT_ROOT');
    http_response_code(500);
    exit;
}

require_once __DIR__ . '/config_loader.php';
try {
    $config = load_project_config($PROJECT_ROOT);
} catch (Throwable $e) {
    error_log('[bootstrap_minimal] Cannot load config');
    http_response_code(500);
    exit;
}

$autoloadPath = $PROJECT_ROOT . '/libs/autoload.php';
if (!file_exists($autoloadPath)) {
    error_log('[bootstrap_minimal] Autoloader not found at ' . $autoloadPath);
    http_response_code(500);
    exit;
}
require_once $autoloadPath;

if (!class_exists(Database::class, true)) {
        error_log('[bootstrap_minimal] Class BlackCat\\Core\\Database not found by autoloader');
        http_response_code(500);
        exit;
    }

// -------------------- Database init (must succeed) --------------------
try { Database::init($config['db']); $database = Database::getInstance(); $pdo = $database->getPdo();} catch (\Throwable $e) {
    error_log('[bootstrap_minimal] DB init failed: ' . get_class($e) . ' - ' . $e->getMessage());
    http_response_code(500);
    exit;
}
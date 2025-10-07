<?php // minimal_bootstrap.php
declare(strict_types=1);

$PROJECT_ROOT = realpath(dirname(__DIR__, 5));
if ($PROJECT_ROOT === false) {
    error_log('[bootstrap] Cannot resolve PROJECT_ROOT');
    http_response_code(500);
    exit;
}
// resolve paths / config (z tvého config_loader)
require_once __DIR__ . '/config_loader.php';
try {
    $config = load_project_config($PROJECT_ROOT);
} catch (Throwable $e) {
    http_response_code(500);
    exit;
}

require_once $PROJECT_ROOT . '/libs/Database.php';

// -------------------- Database init (must succeed) --------------------
try {
    if (!class_exists('Database')) {
            http_response_code(500);
            exit;
    }

    Database::init($config['db']);

} catch (Throwable $e) {
    http_response_code(500);
    exit;
}
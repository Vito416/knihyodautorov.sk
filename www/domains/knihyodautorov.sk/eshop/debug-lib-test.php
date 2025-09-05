<?php
// Public debug endpoint protected by IP and auth - use only during deployment
require __DIR__ . '/../admin/inc/bootstrap.php';
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1'])) { http_response_code(403); echo 'Forbidden'; exit; }
require __DIR__ . '/../libs/lib-test.php';
$cfg = require __DIR__ . '/../secure/config.php';
Database::init($cfg['db']);
$db = Database::get();
$res = run_checks($db, $cfg);
echo "<pre>"; echo implode("\\n", $res); echo "</pre>";
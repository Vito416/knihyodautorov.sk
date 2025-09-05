<?php
require __DIR__ . '/../../inc/bootstrap.php';
include __DIR__ . '/../../../../libs/lib-test.php';
$cfg = require __DIR__ . '/../../../../secure/config.php';
Database::init($cfg['db']);
$db = Database::get();
$res = run_checks($db, $cfg);
?><!doctype html><html lang="sk"><head><meta charset="utf-8"><title>Health check</title></head><body>
<?php include __DIR__ . '/../templates/admin-header.php'; ?>
<main><h1>Health check</h1><pre><?php echo implode("\n",$res); ?></pre></main>
</body></html>
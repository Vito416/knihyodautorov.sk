<?php
$pdo = require __DIR__ . '/db/config/config.php';
var_dump($pdo instanceof PDO);
echo "<br>inTransaction(): " . ($pdo->inTransaction() ? 'true' : 'false') . "<br>";

echo "Začínam transakciu...<br>";
$pdo->beginTransaction();
echo "inTransaction(): " . ($pdo->inTransaction() ? 'true' : 'false') . "<br>";

echo "Robím rollback...<br>";
$pdo->rollBack();
echo "inTransaction(): " . ($pdo->inTransaction() ? 'true' : 'false') . "<br>";

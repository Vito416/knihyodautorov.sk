<?php
// db/config/config.php
$DB_HOST = 'localhost';
$DB_NAME = 'tvoja_databaza';
$DB_USER = 'tvoje_user';
$DB_PASS = 'tvoje_heslo';
$DB_CHAR = 'utf8mb4';
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
$pdo = new PDO("mysql:host={$DB_HOST};dbname={$DB_NAME};charset={$DB_CHAR}", $DB_USER, $DB_PASS, $options);
return $pdo;
// --- koniec konfiguracie databazy ---
// Tento súbor slúži na pripojenie k databáze a mal by byť upravený podľa vašich nastavení databázy.
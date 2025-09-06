<?php
// bootstrap for eshop pages
session_start();
// secure config must be outside webroot
$cfg = require __DIR__ . '/../../secure/config.php';
require __DIR__ . '/../../libs/autoload.php';
Database::init($cfg['db']);
Crypto::init_from_base64($cfg['crypto_key']);
// set session cookie params
ini_set('session.cookie_httponly',1);
ini_set('session.use_strict_mode',1);
// init globals
$db = Database::get();
// helper function: esc
function e($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
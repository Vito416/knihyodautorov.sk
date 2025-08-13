<?php
// /admin/actions/smtp-test.php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
require __DIR__ . '/../bootstrap.php';
require_admin();

header('Content-Type: application/json; charset=utf-8');

// cesta k config smtp (db/config/configsmtp.php) - musí vracať pole s nastaveniami alebo false
$smtpCfgPath = __DIR__ . '/../db/config/configsmtp.php';
if (!file_exists($smtpCfgPath)) {
    echo json_encode(['ok'=>false, 'error'=>'Súbor konfigurácie SMTP neexistuje: ' . $smtpCfgPath], JSON_UNESCAPED_UNICODE);
    exit;
}

$config = require $smtpCfgPath;
if (!is_array($config)) {
    echo json_encode(['ok'=>false, 'error'=>'Konfig SMTP nevrátil pole. Upravte db/config/configsmtp.php.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// potrebné kľúče (aspoň host a port)
$host = $config['host'] ?? null;
$port = isset($config['port']) ? (int)$config['port'] : null;

if (empty($host) || empty($port)) {
    echo json_encode(['ok'=>false, 'error'=>'Nastavenia SMTP chýbajú (host/port).'], JSON_UNESCAPED_UNICODE);
    exit;
}

// timeout
$timeout = 6;

// pokúsime sa otvoriť spojenie
$errNo = 0;
$errStr = '';
$ctx = stream_context_create();
$scheme = 'tcp';
$address = $host . ':' . $port;
$fp = @stream_socket_client($scheme . '://' . $address, $errNo, $errStr, $timeout, STREAM_CLIENT_CONNECT, $ctx);

if ($fp === false) {
    echo json_encode(['ok'=>false, 'error'=>'Nie je možné sa pripojiť na ' . $address . ' — ' . $errStr], JSON_UNESCAPED_UNICODE);
    exit;
}

// prečítame uvítací banner (non-blocking krátko)
stream_set_timeout($fp, 2);
$banner = @fgets($fp, 512);
fclose($fp);

// vrátime úspech + banner ak existuje
echo json_encode(['ok'=>true, 'message'=>'TCP spojenie na ' . $address . ' bolo úspešné.', 'banner' => $banner ?: null], JSON_UNESCAPED_UNICODE);
exit;
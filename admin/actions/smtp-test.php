<?php
// /admin/actions/smtp-test.php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../partials/notifications.php';

header('Content-Type: application/json; charset=utf-8');

try {
    require_admin();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['ok'=>false,'message'=>'Použite POST.']); exit;
    }

    // CSRF check
    $token = $_POST['admin_csrf_token'] ?? null;
    if (!admin_csrf_check($token)) {
        echo json_encode(['ok'=>false,'message'=>'Neplatný CSRF token.']); exit;
    }

    $cfgPath = realpath(__DIR__ . '/../../db/config/configsmtp.php');
    if (!$cfgPath || !file_exists($cfgPath)) {
        echo json_encode(['ok'=>false,'message'=>'Chýba db/config/configsmtp.php']); exit;
    }
    $cfg = require $cfgPath;
    if (!is_array($cfg)) {
        echo json_encode(['ok'=>false,'message'=>'Konfigurácia SMTP nevrátila pole.']); exit;
    }

    $host = $cfg['host'] ?? '';
    $port = (int)($cfg['port'] ?? 25);
    $user = $cfg['username'] ?? $cfg['user'] ?? '';
    $pass = $cfg['password'] ?? $cfg['pass'] ?? '';
    $secure = strtolower((string)($cfg['secure'] ?? ''));

    if (empty($host) || $port <= 0) {
        echo json_encode(['ok'=>false,'message'=>'Neplatný host alebo port v konfigurácii.']); exit;
    }

    // 1) základný TCP test + EHLO
    $transport = ($secure === 'ssl') ? 'ssl://' : '';
    $remote = $transport . $host . ':' . $port;
    $timeout = 6;
    $fp = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
    if (!$fp) {
        // ak sa nedá pripojiť, pokus o detailnejšiu spravu
        echo json_encode(['ok'=>false,'message'=>"TCP pripojenie zlyhalo: $errstr ($errno)"]); exit;
    }
    stream_set_timeout($fp, $timeout);
    $greeting = trim(fgets($fp, 512));
    fwrite($fp, "EHLO localhost\r\n");
    $ehlo = '';
    while (($line = fgets($fp, 512)) !== false) {
        $ehlo .= $line;
        if (isset($line[3]) && $line[3] === ' ') break;
        if (feof($fp)) break;
    }
    fclose($fp);

    $result = ['ok'=>true,'stage'=>'tcp','greeting'=>substr($greeting,0,200),'ehlo'=>substr($ehlo,0,200)];

    // 2) ak PHPMailer prítomný a bol žiadaný test autentifikácie, skúsi sa prihlásiť
    // POST môže obsahovať "auth"=1 ak chcete testovať full login
    $doAuth = isset($_POST['auth']) && (int)$_POST['auth'] === 1;
    if ($doAuth && (class_exists('\PHPMailer\PHPMailer\PHPMailer') || class_exists('PHPMailer'))) {
        try {
            // kompatibilita so staršími namespacami
            $mailClass = class_exists('\PHPMailer\PHPMailer\PHPMailer') ? '\PHPMailer\PHPMailer\PHPMailer' : 'PHPMailer';
            $mail = new $mailClass(true);
            // suppress exceptions to return JSON
            $mail->SMTPDebug = 0;
            $mail->isSMTP();
            $mail->Host = $host;
            $mail->Port = $port;
            $mail->SMTPSecure = $secure === 'ssl' ? 'ssl' : ($secure === 'tls' ? 'tls' : '');
            $mail->SMTPAutoTLS = ($secure === 'tls');
            $mail->SMTPAuth = !empty($user);
            if (!empty($user)) { $mail->Username = $user; $mail->Password = $pass; }
            // try to connect
            $connected = $mail->smtpConnect();
            if ($connected) {
                $mail->smtpClose();
                $result['auth'] = 'ok';
                $result['message'] = 'TCP OK, autentifikácia PHPMailer: úspešná (smtpConnect)'; 
            } else {
                $result['auth'] = 'fail';
                $result['message'] = 'TCP OK, PHPMailer smtpConnect vrátil false.';
            }
        } catch (Throwable $e) {
            $result['auth'] = 'error';
            $result['auth_error'] = $e->getMessage();
        }
    } else {
        $result['auth'] = 'skipped';
        $result['message'] = $result['message'] ?? 'TCP OK. Pre test autentifikácie pošli POST s auth=1 a nainštalovaným PHPMailer.';
    }

    echo json_encode($result);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'message'=>'Výnimka: '.$e->getMessage()]);
    exit;
}
<?php
// /admin/actions/test-smtp.php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Rýchly test SMTP pripojenia bez odosielania e-mailu.
// POST-only. Vyžaduje admin session.
// Používa db/config/configsmtp.php (array s host/port/username/password/secure/from_email/from_name)

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../bootstrap.php';

try {
    require_admin();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['ok' => false, 'message' => 'Použite POST požiadavku.']);
        exit;
    }

    $cfgPath = realpath(__DIR__ . '/../../db/config/configsmtp.php');
    if (!$cfgPath || !file_exists($cfgPath)) {
        echo json_encode(['ok'=>false,'message'=>'Chýba súbor db/config/configsmtp.php']);
        exit;
    }

    $cfg = require $cfgPath;
    if (!is_array($cfg)) {
        echo json_encode(['ok'=>false,'message'=>'Konfigurácia SMTP nevrátila pole. Opravte configsmtp.php']);
        exit;
    }

    $host = $cfg['host'] ?? '';
    $port = (int)($cfg['port'] ?? 25);
    $secure = strtolower((string)($cfg['secure'] ?? '')); // '', 'ssl', 'tls'
    $timeout = 6;

    if (empty($host) || $port <= 0) {
        echo json_encode(['ok'=>false,'message'=>'Neplatný SMTP host alebo port v konfigurácii.']);
        exit;
    }

    // Príprava transportu (ssl prefix ak požadované)
    $transport = ($secure === 'ssl') ? 'ssl://' : '';
    $remote = $transport . $host . ':' . $port;

    $context = stream_context_create([]);
    $fp = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);

    if (!$fp) {
        echo json_encode(['ok'=>false,'message'=>"TCP pripojenie zlyhalo: $errstr ($errno)"]);
        exit;
    }

    stream_set_timeout($fp, $timeout);
    $greeting = trim(fgets($fp, 512));

    // poslať EHLO / HELO
    $clientName = 'localhost';
    fwrite($fp, "EHLO {$clientName}\r\n");
    $ehloResp = '';
    // čítame viaclajn resp (multi-line)
    while (($line = fgets($fp, 512)) !== false) {
        $ehloResp .= $line;
        // koniec multi-line odpovede ak tretí znak je medzera (SMTP)
        if (isset($line[3]) && $line[3] === ' ') break;
        // ochrana proti stuck read
        if (feof($fp)) break;
    }

    // Ak tls a server podporuje STARTTLS, skúsiť TLS handshake (voliteľné)
    $starttls_ok = false;
    if ($secure === 'tls' && stripos($ehloResp, 'STARTTLS') !== false) {
        fwrite($fp, "STARTTLS\r\n");
        $st = trim(fgets($fp, 512));
        if (strpos($st, '220') === 0) {
            // pokúsime sa zapnúť crypto
            $crypto_ok = @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($crypto_ok) {
                $starttls_ok = true;
                // znovu poslať EHLO po TLS
                fwrite($fp, "EHLO {$clientName}\r\n");
                // prečítať odpoveď (krátko)
                $tmp = '';
                while (($line = fgets($fp, 512)) !== false) {
                    $tmp .= $line;
                    if (isset($line[3]) && $line[3] === ' ') break;
                    if (feof($fp)) break;
                }
                $ehloResp .= "\n[after-TLS]\n" . $tmp;
            }
        }
    }

    fclose($fp);

    $msg = "Pripojené k {$host}:{$port}. Greeting: " . substr($greeting, 0, 200);
    if ($starttls_ok) $msg .= " (STARTTLS úspešný)";
    echo json_encode(['ok'=>true,'message'=>$msg, 'ehlo' => $ehloResp]);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'message'=>'Výnimka: ' . $e->getMessage()]);
    exit;
}
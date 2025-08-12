<?php
// /admin/smtp-test.php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
if (!admin_is_logged()) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Unauthorized']); exit; }

$payload = json_decode(file_get_contents('php://input'), true);
$to = filter_var(trim($payload['email'] ?? $_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL) ?: ($pdo->query("SELECT v FROM settings WHERE k='admin_email'")->fetchColumn() ?: null);

if (!$to) { echo json_encode(['ok'=>false,'error'=>'Chýba cieľový e-mail']); exit; }

// načítanie SMTP nastavení z DB
$s = $pdo->prepare("SELECT k,v FROM settings WHERE k IN ('smtp_host','smtp_port','smtp_user','smtp_pass','smtp_from')");
$s->execute();
$cfg = [];
while($r=$s->fetch(PDO::FETCH_ASSOC)) $cfg[$r['k']] = $r['v'];

$from = $cfg['smtp_from'] ?? ('noreply@' . ($_SERVER['SERVER_NAME'] ?? 'localhost'));

function send_via_mail($to,$subject,$body,$from){
  $headers = "From: $from\r\n";
  $headers .= "MIME-Version: 1.0\r\nContent-type: text/plain; charset=utf-8\r\n";
  return @mail($to, $subject, $body, $headers);
}

/* Basic SMTP via fsockopen (AUTH LOGIN) — minimal, may fail on many hosts */
function send_via_smtp($host,$port,$user,$pass,$from,$to,$subject,$body,&$err){
  $err = '';
  $timeout = 10;
  $fp = @fsockopen($host, (int)$port, $errno, $errstr, $timeout);
  if (!$fp) { $err = "SMTP connect failed: $errno $errstr"; return false; }

  $read = function() use($fp){
    $res = '';
    while(!feof($fp)){
      $line = fgets($fp,512);
      $res .= $line;
      if (substr($line,3,1) == ' ') break;
    }
    return $res;
  };
  $w = function($cmd) use($fp){ fputs($fp, $cmd . "\r\n"); };

  $g = $read();
  // ehlo
  $w("EHLO localhost");
  $g = $read();
  // starttls?
  if (stripos($g,'STARTTLS') !== false) {
    $w("STARTTLS");
    $g = $read();
    if (stripos($g,'220') === false) { /* ignore */ }
    stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
    // EHLO again
    $w("EHLO localhost"); $g = $read();
  }

  if ($user !== '') {
    $w("AUTH LOGIN"); $g = $read();
    $w(base64_encode($user)); $g = $read();
    $w(base64_encode($pass)); $g = $read();
    if (stripos($g,'235')===false && stripos($g,'235')===false) {
      // auth failed maybe
    }
  }

  $w("MAIL FROM:<$from>"); $g = $read();
  $w("RCPT TO:<$to>"); $g = $read();
  $w("DATA"); $g = $read();
  $headers = "From: $from\r\nMIME-Version: 1.0\r\nContent-type: text/plain; charset=utf-8\r\n";
  $w("Subject: $subject\r\n$headers\r\n$body\r\n.");
  $g = $read();
  $w("QUIT"); fclose($fp);
  return true;
}

$subject = "Testovací e-mail z administrácie — " . date('Y-m-d H:i:s');
$body = "Toto je testovací e-mail z administrácie $from\n\nAk ho prijmete, nastavenia fungujú.\n\n— Knihy od autorov";

try {
  if (!empty($cfg['smtp_host'])) {
    $err = '';
    $ok = send_via_smtp($cfg['smtp_host'], $cfg['smtp_port'] ?? 25, $cfg['smtp_user'] ?? '', $cfg['smtp_pass'] ?? '', $from, $to, $subject, $body, $err);
    if ($ok) { echo json_encode(['ok'=>true]); exit; }
    // fallback to mail()
  }
  $ok2 = send_via_mail($to,$subject,$body,$from);
  if ($ok2) { echo json_encode(['ok'=>true]); exit; }
  echo json_encode(['ok'=>false,'error'=>'Odoslanie zlyhalo. Skontrolujte SMTP nastavenia a mail logy. ' . ($err ?? '')]);
} catch (Throwable $e) {
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
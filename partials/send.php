<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../libs/autoload.php';

$recaptchaConfig = include __DIR__ . '/../db/config/configrecaptcha.php';
$smtpConfig      = include __DIR__ . '/../db/config/configsmtp.php';

$secretKey = $recaptchaConfig['secret_key'] ?? '';
$minScore  = $recaptchaConfig['min_score'] ?? 0.4;

function respond(bool $success, string $message = ''): void {
    header('Content-Type: application/json; charset=UTF-8');

    $data = $success
        ? ['success' => true,  'message' => $message]
        : ['success' => false, 'error'   => $message];

    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Neplatná metóda');
}

$name     = trim($_POST['name'] ?? '');
$email    = trim($_POST['email'] ?? '');
$message  = trim($_POST['message'] ?? '');
$honeypot = trim($_POST['website'] ?? '');
$token    = $_POST['g-recaptcha-response'] ?? '';

if ($honeypot !== '') {
    respond(true); // ignoruj bota
}
if ($name === '' || $email === '' || $message === '') {
    respond(false, 'Vyplňte všetky polia');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(false, 'Neplatný e-mail');
}

// reCAPTCHA check
$ch = curl_init('https://www.google.com/recaptcha/api/siteverify');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query([
        'secret'   => $secretKey,
        'response' => $token,
        'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
    ]),
    CURLOPT_TIMEOUT => 10,
]);
$response = curl_exec($ch);
curl_close($ch);

$res = json_decode($response, true);
if (empty($res['success']) || ($res['score'] ?? 0) < $minScore) {
    respond(false, 'Overenie reCAPTCHA zlyhalo');
}

// Mail
try {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = $smtpConfig['host'] ?? '';
    $mail->SMTPAuth   = true;
    $mail->Username   = $smtpConfig['username'] ?? '';
    $mail->Password   = $smtpConfig['password'] ?? '';
    $mail->SMTPSecure = $smtpConfig['secure'] ?? 'tls';
    $mail->Port       = (int)($smtpConfig['port'] ?? 587);

    $mail->CharSet = 'UTF-8';
    $mail->isHTML(false);

    $fromEmail = $smtpConfig['from_email'] ?? 'no-reply@' . ($_SERVER['SERVER_NAME'] ?? 'example.com');
    $fromName  = $smtpConfig['from_name'] ?? 'Web Kontakt';
    $toEmail   = $smtpConfig['to_email'] ?? '';

    if (!$toEmail) {
        respond(false, 'Cieľový e-mail nie je nastavený');
    }

    $mail->setFrom($fromEmail, $fromName);
    $mail->addAddress($toEmail);
    $mail->addReplyTo($email, $name);

    $safeName = preg_replace("/[\r\n]+/", ' ', $name);
    $mail->Subject = 'Kontakt z webu: ' . mb_substr($safeName, 0, 80);
    $mail->Body    = "Meno: $name\nE-mail: $email\n\nSpráva:\n$message\n\nIP: " . ($_SERVER['REMOTE_ADDR'] ?? '');

    $mail->send();

    respond(true, 'Ďakujeme — správa bola odoslaná');
} catch (Exception $e) {
    error_log('Mail error: ' . $e->getMessage());
    respond(false, 'Odoslanie zlyhalo');
}
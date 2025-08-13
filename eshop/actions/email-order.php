<?php
// /eshop/actions/email-order.php
require __DIR__ . '/../_init.php';
// This endpoint is admin-protected in production. Here simple POST.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
$csrf = $_POST['csrf'] ?? '';
if (!eshop_verify_csrf($csrf)) { http_response_code(403); exit; }

$orderId = (int)($_POST['order_id'] ?? 0);
if (!$orderId) { http_response_code(400); exit; }

$order = $pdo->prepare("SELECT o.*, i.pdf_path, i.html_path FROM orders o LEFT JOIN invoices i ON i.order_id = o.id WHERE o.id = ? LIMIT 1");
$order->execute([$orderId]);
$o = $order->fetch(PDO::FETCH_ASSOC);
if (!$o) { http_response_code(404); exit; }

$userEmail = $_POST['to'] ?? '';
if ($userEmail === '') $userEmail = $o['email'] ?? null;
if (!$userEmail) { http_response_code(400); exit; }

$subject = "Potvrdenie objednávky #{$o['id']} — Knihy od autorov";
$body = "Vďaka za objednávku. Číslo objednávky: {$o['id']}.\n\n";

// try PHPMailer
if (class_exists('\PHPMailer\PHPMailer\PHPMailer')) {
    $smtpCfg = [];
    $cfgFile = realpath(__DIR__ . '/../../db/config/configsmtp.php');
    if (file_exists($cfgFile)) $smtpCfg = require $cfgFile;
    $mail = new \PHPMailer\PHPMailer\PHPMailer();
    try {
        if ($smtpCfg) {
            $mail->isSMTP();
            $mail->Host = $smtpCfg['host'] ?? '';
            $mail->SMTPAuth = true;
            $mail->Username = $smtpCfg['username'] ?? ($smtpCfg['user'] ?? '');
            $mail->Password = $smtpCfg['password'] ?? ($smtpCfg['pass'] ?? '');
            $mail->SMTPSecure = $smtpCfg['secure'] ?? 'tls';
            $mail->Port = $smtpCfg['port'] ?? 587;
            $mail->setFrom($smtpCfg['from_email'] ?? 'info@knihyodautorov.sk', $smtpCfg['from_name'] ?? 'Knihy od autorov');
        }
        $mail->addAddress($userEmail);
        $mail->Subject = $subject;
        $mail->Body = $body;
        if (!empty($o['pdf_path']) && file_exists($o['pdf_path'])) $mail->addAttachment($o['pdf_path']);
        $sent = $mail->send();
        echo json_encode(['ok' => (bool)$sent, 'info' => $mail->ErrorInfo ?? '']);
        exit;
    } catch (Throwable $e) {
        error_log("email-order PHPMailer error: ".$e->getMessage());
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
        exit;
    }
} else {
    // fallback: try mail()
    $headers = "From: " . (eshop_settings($pdo,'company_email') ?? 'info@knihyodautorov.sk') . "\r\n";
    $ok = mail($userEmail, $subject, $body, $headers);
    echo json_encode(['ok'=>$ok]);
    exit;
}
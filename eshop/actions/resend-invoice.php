<?php
// /eshop/actions/resend-invoice.php
require __DIR__ . '/../_init.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
if (!eshop_verify_csrf($_POST['csrf'] ?? '')) { http_response_code(403); echo "CSRF"; exit; }

$orderId = (int)($_POST['order_id'] ?? 0);
if (!$orderId) { http_response_code(400); exit; }

$u = current_user($pdo);
$st = $pdo->prepare("SELECT o.*, i.pdf_path, i.html_path FROM orders o LEFT JOIN invoices i ON i.order_id = o.id WHERE o.id = ? LIMIT 1");
$st->execute([$orderId]);
$o = $st->fetch(PDO::FETCH_ASSOC);
if (!$o) { http_response_code(404); echo "Objednávka nenájdená"; exit; }

// pokud je objednávka navázaná na usera, musí sedět
if (!empty($o['user_id']) && (!$u || (int)$u['id'] !== (int)$o['user_id'])) {
  http_response_code(403); echo "Nemáte oprávnenie."; exit;
}

$to = $u['email'] ?? ($o['email'] ?? null);
if (!$to) { http_response_code(400); echo "Chýba email"; exit; }

$subject = "Faktúra k objednávke #{$o['id']}";
$body = "Zasielame vám faktúru k objednávke #{$o['id']}.";

if (class_exists('\PHPMailer\PHPMailer\PHPMailer')) {
  $smtpCfg = [];
  $cfgFile = realpath(__DIR__ . '/../../db/config/configsmtp.php');
  if ($cfgFile && file_exists($cfgFile)) $smtpCfg = require $cfgFile;

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
      $mail->setFrom($smtpCfg['from_email'] ?? 'info@example.com', $smtpCfg['from_name'] ?? 'Eshop');
    }
    $mail->addAddress($to);
    $mail->Subject = $subject;
    $mail->Body = $body;
    if (!empty($o['pdf_path']) && file_exists($o['pdf_path'])) $mail->addAttachment($o['pdf_path']);
    $ok = $mail->send();
    $_SESSION['eshop_msg'] = $ok ? 'Faktúra bola odoslaná.' : ('Odoslanie zlyhalo: '.$mail->ErrorInfo);
  } catch (Throwable $e) {
    $_SESSION['eshop_msg'] = 'Chyba pri odosielaní: '.$e->getMessage();
  }
} else {
  // fallback
  $headers = "From: " . (eshop_settings($pdo,'company_email') ?? 'info@example.com') . "\r\n";
  $ok = mail($to, $subject, $body, $headers);
  $_SESSION['eshop_msg'] = $ok ? 'Email odoslaný.' : 'Odoslanie zlyhalo.';
}

header('Location: /eshop/order.php?id=' . $orderId);
exit;
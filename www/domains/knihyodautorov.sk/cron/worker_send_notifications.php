<?php
// worker_send_notifications.php
require __DIR__ . '/inc/bootstrap.php'; // musí nastavit $db a $config

$limit = 200;

try {
    $sel = $db->prepare(
        "SELECT id, user_id, channel, template, payload, retries, max_retries
         FROM notifications
         WHERE status = 'pending'
           AND channel = 'email'
           AND (scheduled_at <= NOW() OR scheduled_at IS NULL)
           AND (next_attempt_at IS NULL OR next_attempt_at <= NOW())
         ORDER BY created_at ASC
         LIMIT ?"
    );
    $sel->bindValue(1, (int)$limit, PDO::PARAM_INT);
    $sel->execute();
    $rows = $sel->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) exit(0);

    $retryIntervalsHours = [1,2,4,8,24,48];

    foreach ($rows as $r) {
        $nid = (int)$r['id'];
        $payload = json_decode($r['payload'], true);
        if (!is_array($payload)) {
            $db->prepare("UPDATE notifications SET status = 'failed', error = ? WHERE id = ?")
               ->execute(['invalid payload', $nid]);
            continue;
        }

        $to = $payload['to'] ?? null;
        $subject = $payload['subject'] ?? null;
        $template = $payload['template'] ?? null;
        $vars = $payload['vars'] ?? [];

        if (!$to || !$subject) {
            $db->prepare("UPDATE notifications SET status = 'failed', error = ? WHERE id = ?")
               ->execute(['missing to/subject', $nid]);
            continue;
        }

        if ($template === 'verify_email') {
            $body = "Dobrý deň,\n\nKliknite na tento odkaz k potvrdeniu e-mailu:\n\n" . ($vars['verify_url'] ?? '') . "\n\nOdkaz platí do: " . ($vars['expires_at'] ?? '') . "\n\nS pozdravom";
        } else {
            $body = $payload['body'] ?? ($vars['body'] ?? $subject);
        }

        $sent = false;
        $errorMsg = null;

        try {
            if (!class_exists('\PHPMailer\PHPMailer\PHPMailer')) {
                throw new \Exception('PHPMailer not available');
            }
            if (empty($config['smtp']['host']) || empty($config['smtp']['from_email'])) {
                throw new \Exception('SMTP configuration incomplete');
            }

            $smtp = $config['smtp'];
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = $smtp['host'];
            $mail->SMTPAuth = !empty($smtp['user']);
            if (!empty($smtp['user'])) {
                $mail->Username = $smtp['user'];
                $mail->Password = $smtp['pass'];
            }
            if (!empty($smtp['port'])) $mail->Port = (int)$smtp['port'];
            if (!empty($smtp['secure'])) $mail->SMTPSecure = $smtp['secure'];
            $mail->Timeout = isset($smtp['worker_timeout']) ? (int)$smtp['worker_timeout'] : 60;
            $mail->SMTPAutoTLS = true;

            $from = $smtp['from_email'];
            $fromName = $smtp['from_name'] ?? (defined('APP_NAME') ? APP_NAME : null);
            $mail->setFrom($from, $fromName);
            $mail->addAddress($to);
            $mail->Subject = $subject;
            $mail->Body = $body;
            $mail->isHTML(false);

            $mail->send();
            $sent = true;
        } catch (\Exception $ex) {
            $sent = false;
            $errorMsg = $ex->getMessage();
            error_log('[worker_notify] send error id=' . $nid . ' : ' . $errorMsg);
        }

        if ($sent) {
            $db->prepare("UPDATE notifications SET status = 'sent', sent_at = NOW(), error = NULL WHERE id = ?")
               ->execute([$nid]);
        } else {
            $retries = (int)$r['retries'] + 1;
            $maxRetries = (int)($r['max_retries'] ?? 6);

            if ($retries >= $maxRetries) {
                $db->prepare("UPDATE notifications SET status = 'failed', retries = ?, error = ?, next_attempt_at = NULL WHERE id = ?")
                   ->execute([$retries, $errorMsg ?? 'send failed', $nid]);
            } else {
                $idx = min($retries - 1, count($retryIntervalsHours) - 1);
                $hours = (int)$retryIntervalsHours[$idx];
                $next = (new DateTime())->add(new DateInterval('PT' . $hours . 'H'))->format('Y-m-d H:i:s');

                $db->prepare("UPDATE notifications SET retries = ?, next_attempt_at = ?, error = ? WHERE id = ?")
                   ->execute([$retries, $next, $errorMsg ?? 'send failed', $nid]);
            }
        }
    }
} catch (\Exception $ex) {
    error_log('[worker_notify] ' . $ex->getMessage());
    exit(1);
}
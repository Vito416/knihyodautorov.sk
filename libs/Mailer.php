<?php
// libs/Mailer.php - minimal wrapper: if PHPMailer class exists use it, otherwise fallback to mail()
class Mailer {
    public static function send($to, $subject, $body, $fromName = null, $fromEmail = null) {
        if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            try {
                $mail->setFrom($fromEmail ?? 'noreply@localhost', $fromName ?? 'NoReply');
                $mail->addAddress($to);
                $mail->Subject = $subject;
                $mail->Body = $body;
                $mail->isHTML(false);
                $mail->send();
                return true;
            } catch (Exception $e) {
                error_log('PHPMailer error: '.$e->getMessage());
                return false;
            }
        } else {
            $headers = 'From: '.($fromName ? $fromName.' <'.($fromEmail?:'noreply@localhost').'>' : ($fromEmail ?? 'noreply@localhost'))."\r\n";
            return mail($to, $subject, $body, $headers);
        }
    }
}
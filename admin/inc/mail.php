<?php
// /admin/inc/mail.php
// Jednoduchý mail wrapper: pokusí sa poslať cez SMTP (ak /db/config/configsmtp.php existuje a 'use_smtp'==true),
// inak použije PHP mail() a do logu zapíše výsledok.
// Vracia true/false.

if (!function_exists('send_mail')) {
    function send_mail(string $to, string $subject, string $htmlBody, string $textBody = ''): bool {
        // načítaj konfiguráciu ak existuje
        $cfgPath = __DIR__ . '/../../db/config/configsmtp.php';
        $smtp = null;
        if (file_exists($cfgPath)) {
            /** @noinspection PhpIncludeInspection */
            include $cfgPath;
            if (isset($smtp_config) && is_array($smtp_config)) $smtp = $smtp_config;
        }

        // headers pre HTML mail
        $boundary = '==_KDA_' . bin2hex(random_bytes(6));
        $headers = "MIME-Version: 1.0\r\n";
        $headers .= "From: " . ($smtp['from_name'] ?? 'Knihy od autorov') . " <" . ($smtp['from_email'] ?? 'no-reply@knihyodautorov.sk') . ">\r\n";
        $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";

        $body = "--{$boundary}\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
        $body .= ($textBody ?: strip_tags($htmlBody)) . "\r\n\r\n";
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
        $body .= $htmlBody . "\r\n\r\n";
        $body .= "--{$boundary}--\r\n";

        // ak máme explicitný smtp_config a use_smtp => jednoduchý SMTP (AUTH LOGIN)
        if ($smtp && !empty($smtp['use_smtp'])) {
            // very small SMTP client (blocking), funguje len ak hostitel povolí spojenie
            $host = $smtp['host'] ?? '';
            $port = (int)($smtp['port'] ?? 25);
            $timeout = (int)($smtp['timeout'] ?? 10);

            $errno = 0; $errstr = '';
            $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
            if (!$fp) {
                error_log("send_mail: SMTP connect failed: $errno $errstr");
            } else {
                stream_set_timeout($fp, $timeout);
                $res = fgets($fp, 512);
                $send = function($cmd) use ($fp) { fwrite($fp, $cmd . "\r\n"); return fgets($fp, 512); };

                $ehlo = $send("EHLO " . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
                if (!empty($smtp['secure']) && $smtp['secure'] === 'ssl') {
                    // ssl over connect already handled by fsockopen with ssl:// if needed
                } elseif (!empty($smtp['secure']) && $smtp['secure'] === 'tls') {
                    $send("STARTTLS");
                    stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                    $send("EHLO " . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
                }

                if (!empty($smtp['username'])) {
                    $send("AUTH LOGIN");
                    $send(base64_encode($smtp['username']));
                    $send(base64_encode($smtp['password']));
                }

                $from = $smtp['from_email'] ?? 'no-reply@knihyodautorov.sk';
                $send("MAIL FROM:<{$from}>");
                $send("RCPT TO:<{$to}>");
                $send("DATA");
                $send("Subject: " . $subject);
                $send($headers);
                $send(""); // blank line
                $send($body . "\r\n.");
                $send("QUIT");
                fclose($fp);
                return true;
            }
        }

        // fallback na PHP mail()
        $ok = @mail($to, $subject, $body, $headers);
        if (!$ok) error_log("send_mail: PHP mail() returned false for to={$to}");
        return (bool)$ok;
    }
}
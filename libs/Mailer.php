// File: libs/Mailer.php
<?php
declare(strict_types=1);

/**
 * Simple Mailer helper.
 * Supports two transports:
 *  - mail() (default)
 *  - basic SMTP (if config['transport']=='smtp' and host/port provided)
 *
 * Usage:
 * $mailer = new Mailer($config);
 * $mailer->sendMail('to@example.com', 'Subject', '<p>HTML body</p>', ['from' => 'noreply@site']);
 */
class Mailer
{
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    /**
     * Send email. Returns true on success.
     * $options: ['from' => 'Name <email>', 'reply_to' => '...', 'headers' => array]
     */
    public function sendMail(string $to, string $subject, string $body, array $options = []): bool
    {
        $from = $options['from'] ?? ($this->config['from'] ?? 'no-reply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        $replyTo = $options['reply_to'] ?? null;
        $additionalHeaders = $options['headers'] ?? [];

        // Prepare headers
        $headers = [];
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-type: text/html; charset=UTF-8';
        $headers[] = 'From: ' . $from;
        if ($replyTo) $headers[] = 'Reply-To: ' . $replyTo;
        foreach ($additionalHeaders as $h) $headers[] = $h;

        $transport = $this->config['transport'] ?? 'mail';
        if ($transport === 'smtp' && !empty($this->config['smtp']['host'])) {
            try {
                return $this->sendViaSmtp($to, $subject, $body, $from, $headers);
            } catch (Throwable $e) {
                error_log('Mailer sendViaSmtp failed: ' . $e->getMessage());
                // fallback to mail()
            }
        }

        // Use mail() fallback
        $headerStr = implode("\r\n", $headers);
        // Ensure subject is properly encoded
        $encodedSubject = $this->encodeHeader($subject);
        $result = mail($to, $encodedSubject, $body, $headerStr);
        if (!$result) error_log('Mailer::sendMail mail() returned false for to=' . $to);
        return (bool)$result;
    }

    private function encodeHeader(string $value): string
    {
        // simple UTF-8 base64 encoding for subject
        if (preg_match('/[\x80-\xFF]/', $value)) {
            return '=?UTF-8?B?' . base64_encode($value) . '?=';
        }
        return $value;
    }

    /**
     * Basic SMTP implementation supporting AUTH LOGIN and STARTTLS if configured.
     */
    private function sendViaSmtp(string $to, string $subject, string $body, string $from, array $headers): bool
    {
        $smtp = $this->config['smtp'];
        $host = $smtp['host'];
        $port = (int)($smtp['port'] ?? 25);
        $user = $smtp['user'] ?? null;
        $pass = $smtp['pass'] ?? null;
        $useTls = !empty($smtp['tls']);
        $timeout = 30;

        $remote = ($port === 465 ? 'ssl://' : '') . $host . ':' . $port;
        $fp = stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
        if (!$fp) throw new RuntimeException('SMTP connect failed: ' . $errstr);
        stream_set_timeout($fp, $timeout);

        $this->smtpRead($fp); // greet
        $this->smtpCommand($fp, 'EHLO ' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));

        if ($useTls && $port !== 465) {
            $this->smtpCommand($fp, 'STARTTLS');
            // enable crypto
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('Failed to enable TLS for SMTP');
            }
            $this->smtpCommand($fp, 'EHLO ' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        }

        if ($user && $pass) {
            $this->smtpCommand($fp, 'AUTH LOGIN');
            $this->smtpCommand($fp, base64_encode($user));
            $this->smtpCommand($fp, base64_encode($pass));
        }

        // MAIL FROM
        $fromEmail = $this->extractEmail($from);
        $this->smtpCommand($fp, 'MAIL FROM:<' . $fromEmail . '>');

        // RCPT TO
        $this->smtpCommand($fp, 'RCPT TO:<' . $to . '>');

        // DATA
        $this->smtpCommand($fp, 'DATA');
        $headerStr = implode("\r\n", $headers);
        $msg = $headerStr . "\r\nSubject: " . $this->encodeHeader($subject) . "\r\n\r\n" . $body . "\r\n.";
        $this->smtpCommand($fp, $msg);

        $this->smtpCommand($fp, 'QUIT');
        fclose($fp);
        return true;
    }

    private function smtpRead($fp): string
    {
        $data = '';
        while (($line = fgets($fp, 515)) !== false) {
            $data .= $line;
            // if line[3] is space then it's last line of response
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        return $data;
    }

    private function smtpCommand($fp, string $cmd): string
    {
        if (substr($cmd, -2) !== "\r\n") $cmdToSend = $cmd . "\r\n"; else $cmdToSend = $cmd;
        fwrite($fp, $cmdToSend);
        return $this->smtpRead($fp);
    }

    private function extractEmail(string $input): string
    {
        // naive extraction of email from "Name <email>" or just email
        if (preg_match('/<([^>]+)>/', $input, $m)) return $m[1];
        return $input;
    }
}
<?php
declare(strict_types=1);

/**
 * libs/Mailer.php
 *
 * Mailer WITHOUT DKIM. Minimal dependencies: KeyManager, Crypto, EmailTemplates, Validator, Logger.
 * No OpenSSL usage. No libsodium required.
 *
 * Config expectations:
 *  $config['smtp'] = [...];
 *  $config['paths']['keys'] = '/path/to/keys' (optional, used by Crypto/KeyManager)
 *  $config['app_domain'] = 'example.com' // used for Message-ID
 *
 * This Mailer:
 *  - enqueues encrypted notifications into `notifications` table
 *  - worker (processPendingNotifications) decrypts, validates, renders and sends via SMTP
 *  - implements retry/backoff and locking
 */

final class Mailer
{
    private static ?array $config = null;
    private static ?PDO $pdo = null;
    private static bool $inited = false;

    /** @var ?string path to keys dir (optional) */
    private static ?string $keysDir = null;

    public static function init(array $config, PDO $pdo): void
    {
        if (!class_exists('KeyManager') || !class_exists('Crypto') || !class_exists('Validator') || !class_exists('EmailTemplates')) {
            throw new RuntimeException('Mailer init failed: required libs missing (KeyManager, Crypto, Validator, EmailTemplates).');
        }
        if (!class_exists('Logger')) {
            throw new RuntimeException('Mailer init failed: Logger missing.');
        }

        self::$config = $config;
        self::$pdo = $pdo;

        // initialize Crypto if needed (KeyManager backed)
        $keysDir = $config['paths']['keys'] ?? null;
        try {
            Crypto::initFromKeyManager($keysDir);
            self::$keysDir = $keysDir;
        } catch (\Throwable $e) {
            Logger::systemError($e);
            throw new RuntimeException('Mailer init: Crypto initialization failed.');
        }

        // No DKIM required/handled here (host provider signs mail or not).
        self::$inited = true;
    }

    public static function enqueue(array $payloadArr, int $maxRetries = 0): int
    {
        if (!self::$inited) throw new RuntimeException('Mailer not initialized.');

        $configMax = (int)(self::$config['smtp']['max_retries'] ?? 0);
        if ($maxRetries <= 0) $maxRetries = $configMax > 0 ? $configMax : 6;

        $json = json_encode($payloadArr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Failed to encode notification payload to JSON.');
        }
        $templateName = $payloadArr['template'] ?? '';
        if (!Validator::validateNotificationPayload($json, $templateName)) {
            throw new RuntimeException('Invalid notification payload.');
        }

        $keysDir = self::$keysDir ?? (self::$config['paths']['keys'] ?? null);
        $emailKeyInfo = KeyManager::getEmailKeyInfo($keysDir); // ['raw'=>binary,'version'=>'vN']
        $keyRaw = $emailKeyInfo['raw'] ?? null;
        if ($keyRaw === null) {
            throw new RuntimeException('Email key not available.');
        }

        $cipher = Crypto::encryptWithKeyBytes($json, $keyRaw, 'binary');

        try { KeyManager::memzero($keyRaw); } catch (\Throwable $_) {}
        unset($keyRaw);

        $payloadForDb = json_encode([
            'cipher' => base64_encode($cipher),
            'meta' => [
                'key_version' => $emailKeyInfo['version'] ?? null,
                'created_at'  => gmdate('c'),
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // optional: protect against extremely large payloads
        if (strlen($payloadForDb) > 2000000) { // 2MB safe-guard (adjust as needed)
            Logger::systemMessage('error', 'Mailer enqueue failed: payload too large', null, ['size' => strlen($payloadForDb)]);
            throw new RuntimeException('Notification payload too large.');
        }

        // získat user_id z payloadu
        $userId = null;
        if (isset($payloadArr['user_id'])) {
            $userId = (int)$payloadArr['user_id'];
        } elseif (isset($payloadArr['userId'])) {
            $userId = (int)$payloadArr['userId'];
        }

        if ($userId === null || $userId <= 0) {
            Logger::systemMessage('error', 'Mailer enqueue failed: missing/invalid user_id in payload', null, ['template' => $templateName]);
            throw new \InvalidArgumentException('Mailer::enqueue requires valid user_id in payload.');
        }

        // ověření existence uživatele
        try {
            $chk = self::$pdo->prepare('SELECT 1 FROM pouzivatelia WHERE id = ? LIMIT 1');
            $chk->execute([$userId]);
            if (!$chk->fetchColumn()) {
                Logger::systemMessage('error', 'Mailer enqueue failed: user_id does not exist', $userId, ['template' => $templateName]);
                throw new \RuntimeException("Mailer::enqueue: user_id {$userId} does not exist.");
            }
        } catch (\Throwable $e) {
            Logger::systemMessage('error', 'Mailer enqueue DB check failed', $userId, ['exception' => $e->getMessage()]);
            throw $e;
        }

        // vložení notifikace (UTC_TIMESTAMP pro konzistenci)
        try {
            $stmt = self::$pdo->prepare('INSERT INTO notifications (user_id, channel, template, payload, status, retries, max_retries, scheduled_at, created_at) VALUES (?, ?, ?, ?, ?, 0, ?, NULL, UTC_TIMESTAMP())');
            $ok = $stmt->execute([$userId, 'email', $templateName, $payloadForDb, 'pending', $maxRetries]);
            if (!$ok) {
                $err = self::$pdo->errorInfo();
                Logger::systemMessage('error', 'Mailer enqueue DB insert failed', $userId, ['error' => $err[2] ?? $err]);
                throw new RuntimeException('Failed to enqueue notification (DB).');
            }
            $id = (int) self::$pdo->lastInsertId();
            Logger::systemMessage('notice', 'Notification enqueued', $userId, ['id' => $id, 'template' => $templateName]);
            return $id;
        } catch (\Throwable $e) {
            Logger::systemMessage('error', 'Mailer enqueue failed (exception)', $userId, ['exception' => $e->getMessage()]);
            throw $e;
        }
    }

    public static function processPendingNotifications(int $limit = 100): array
    {
        if (!self::$inited) throw new RuntimeException('Mailer not initialized.');
        $report = ['processed' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'errors' => []];

        $pdo = self::$pdo;

        // SELECT includes pending/failed ready to send, OR processing with expired lock (stale)
        $fetchSql = '
            SELECT * FROM notifications
            WHERE (
                (status IN (\'pending\', \'failed\') AND (next_attempt_at IS NULL OR next_attempt_at <= NOW()))
                OR
                (status = \'processing\' AND locked_until IS NOT NULL AND locked_until <= NOW())
            )
            ORDER BY priority DESC, created_at ASC
            LIMIT :lim
        ';
        $fetchStmt = $pdo->prepare($fetchSql);
        $fetchStmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $fetchStmt->execute();
        $rows = $fetchStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $report['processed']++;
            $id = (int)$row['id'];
            $retries = (int)$row['retries'];
            $maxRetries = (int)$row['max_retries'];

            try {
                // Try to atomically claim (re-lock also stale 'processing' rows)
                $upd = $pdo->prepare('UPDATE notifications SET status = ?, locked_by = ?, locked_until = DATE_ADD(NOW(), INTERVAL 300 SECOND) WHERE id = ? AND (status IN (\'pending\', \'failed\') OR (status = \'processing\' AND (locked_until IS NULL OR locked_until <= NOW())))');
                $lockedBy = 'worker-http';
                $ok = $upd->execute(['processing', $lockedBy, $id]);
                if (!$ok || $upd->rowCount() === 0) {
                    $report['skipped']++;
                    continue;
                }

                // payload is JSON column with base64 cipher
                $payloadColRaw = $row['payload'];
                $payloadCol = null;
                if ($payloadColRaw !== null && $payloadColRaw !== '') {
                    $payloadCol = json_decode($payloadColRaw, true);
                }
                if (!is_array($payloadCol) || empty($payloadCol['cipher'])) {
                    self::markFailed($id, $retries, $maxRetries, 'payload_db_malformed');
                    $report['failed']++;
                    Logger::systemMessage('warning', 'Notification payload DB malformed', null, ['id' => $id]);
                    continue;
                }
                $cipher = base64_decode($payloadCol['cipher'], true);
                if ($cipher === false) {
                    self::markFailed($id, $retries, $maxRetries, 'payload_base64_invalid');
                    $report['failed']++;
                    Logger::systemMessage('warning', 'Notification payload base64 invalid', null, ['id' => $id]);
                    continue;
                }

                // get candidates using keysDir
                $keysDir = self::$keysDir ?? (self::$config['paths']['keys'] ?? null);
                $candidates = KeyManager::getAllRawKeys('EMAIL_KEY', $keysDir, 'email_key', KeyManager::keyByteLen());
                // If KeyManager returns structured array, normalize to raw bytes expected by Crypto
                if (is_array($candidates) && !empty($candidates) && isset($candidates[0]) && is_array($candidates[0]) && isset($candidates[0]['raw'])) {
                    $raws = [];
                    foreach ($candidates as $c) {
                        if (isset($c['raw'])) $raws[] = $c['raw'];
                    }
                    $candidates = $raws;
                }

                $plain = Crypto::decryptWithKeyCandidates($cipher, $candidates);
                if ($plain === null) {
                    $errMsg = 'decrypt_failed';
                    self::markFailed($id, $retries, $maxRetries, $errMsg);
                    $report['failed']++;
                    Logger::systemMessage('warning', 'Notification decryption failed', null, ['id' => $id]);
                    continue;
                }

                if (!Validator::validateJson($plain)) {
                    self::markFailed($id, $retries, $maxRetries, 'invalid_json');
                    $report['failed']++;
                    Logger::systemMessage('warning', 'Notification payload JSON invalid', null, ['id' => $id]);
                    continue;
                }

                $payload = json_decode($plain, true);
                $templateName = $payload['template'] ?? '';
                if (!Validator::validateNotificationPayload(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $templateName)) {
                    self::markFailed($id, $retries, $maxRetries, 'payload_validation_failed');
                    $report['failed']++;
                    Logger::systemMessage('warning', 'Notification payload validation failed', null, ['id' => $id, 'template' => $templateName]);
                    continue;
                }

                $rendered = EmailTemplates::renderWithText($templateName, $payload['vars'] ?? []);
                $to = (string)$payload['to'];
                $subject = (string)$payload['subject'];
                $htmlBody = $rendered['html'];
                $textBody = $rendered['text'];

                $sendMeta = self::sendSmtpEmail($to, $subject, $htmlBody, $textBody, $payload);
                if ($sendMeta['ok']) {
                    $stmt = $pdo->prepare('UPDATE notifications SET status = ?, sent_at = NOW(), error = NULL, last_attempt_at = NOW(), retries = ?, updated_at = NOW() WHERE id = ?');
                    $stmt->execute(['sent', $retries + 1, $id]);
                    $report['sent']++;
                    Logger::systemMessage('notice', 'Notification sent', null, ['id' => $id, 'to' => $to]);
                } else {
                    $err = $sendMeta['error'] ?? 'send_failed';
                    self::markFailed($id, $retries, $maxRetries, $err);
                    $report['failed']++;
                    Logger::systemMessage('warning', 'Notification send failed', null, ['id' => $id, 'error' => $err]);
                }

            } catch (\Throwable $e) {
                try { self::markFailed($id, $retries, $maxRetries, 'exception: ' . $e->getMessage()); } catch (\Throwable $_) {}
                $report['failed']++;
                Logger::systemError($e);
            } finally {
                // clear lock regardless
                $pdo->prepare('UPDATE notifications SET locked_until = NULL, locked_by = NULL WHERE id = ?')->execute([$id]);
            }
        }

        return $report;
    }

    private static function markFailed(int $id, int $retries, int $maxRetries, string $error): void
    {
        $pdo = self::$pdo;
        $retriesNew = $retries + 1;
        $status = $retriesNew >= $maxRetries ? 'failed' : 'pending';
        $delaySeconds = (int) pow(2, min($retries, 6)) * 60;
        if ($delaySeconds < 60) $delaySeconds = 60;
        $nextAttempt = date('Y-m-d H:i:s', time() + $delaySeconds);

        $stmt = $pdo->prepare('UPDATE notifications SET status = ?, retries = ?, error = ?, next_attempt_at = ?, last_attempt_at = NOW(), updated_at = NOW() WHERE id = ?');
        $stmt->execute([$status, $retriesNew, $error, $nextAttempt, $id]);
    }

    private static function sendSmtpEmail(string $to, string $subject, string $htmlBody, string $textBody, array $payload): array
    {
        if (!self::$config || !isset(self::$config['smtp'])) {
            throw new RuntimeException('SMTP config missing.');
        }
        $smtp = self::$config['smtp'];
        $host = trim((string)($smtp['host'] ?? ''));
        $port = (int)($smtp['port'] ?? 0);
        $user = $smtp['user'] ?? '';
        $pass = $smtp['pass'] ?? '';
        $fromEmail = trim((string)($smtp['from_email'] ?? ($smtp['user'] ?? '')));
        $fromName = (string)($smtp['from_name'] ?? '');
        $secure = strtolower(trim((string)($smtp['secure'] ?? ''))); // '', 'ssl', 'tls'
        $timeout = max(1, (int)($smtp['timeout'] ?? 10));

        $verifyTls = isset($smtp['tls_verify']) ? (bool)$smtp['tls_verify'] : true;
        $cafile = $smtp['cafile'] ?? null;
        $peerName = $smtp['peer_name'] ?? $host;

        if ($host === '') {
            return ['ok' => false, 'error' => 'smtp_host_missing'];
        }
        if ($fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'smtp_from_email_invalid'];
        }

        $fromName = preg_replace("/[\r\n]+/", ' ', $fromName);
        $subject = preg_replace("/[\r\n]+/", ' ', $subject);

        $rcpts = array_filter(array_map('trim', preg_split('/[,;]+/', $to)));
        if (empty($rcpts)) {
            return ['ok' => false, 'error' => 'no_recipients'];
        }
        foreach ($rcpts as $r) {
            if (!filter_var($r, FILTER_VALIDATE_EMAIL)) {
                return ['ok' => false, 'error' => 'invalid_recipient: ' . $r];
            }
        }

        if ($port === 0) {
            if ($secure === 'ssl') $port = 465;
            elseif ($secure === 'tls') $port = 587;
            else $port = 25;
        }

        $transportPrefix = ($secure === 'ssl') ? 'ssl://' : '';

        // build stream context for TLS/SSL verification (best-effort; configurable)
        $sslOptions = [
            'verify_peer' => $verifyTls,
            'verify_peer_name' => $verifyTls,
        ];
        if ($cafile) $sslOptions['cafile'] = $cafile;
        if ($peerName) $sslOptions['peer_name'] = $peerName;
        $ctx = stream_context_create(['ssl' => $sslOptions]);

        $errno = null; $errstr = null;
        $socket = @stream_socket_client($transportPrefix . $host . ':' . $port, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if ($socket === false) {
            return ['ok' => false, 'error' => "socket_connect_failed: $errno $errstr"];
        }
        stream_set_timeout($socket, $timeout);

        $recv = function() use ($socket, $timeout) {
            $s = '';
            $start = time();
            while (($line = fgets($socket, 515)) !== false) {
                $s .= $line;
                if (isset($line[3]) && $line[3] === ' ') break;
                if ((time() - $start) > $timeout) break;
            }
            return $s;
        };
        $send = function(string $cmd) use ($socket) {
            fwrite($socket, $cmd . "\r\n");
        };

        $banner = $recv();
        if ($banner === '' || stripos($banner, '220') !== 0) {
            fclose($socket);
            return ['ok' => false, 'error' => 'smtp_banner_invalid: ' . trim($banner)];
        }

        $send("EHLO " . (gethostname() ?: 'localhost'));
        $ehlo = $recv();

        if ($secure === 'tls') {
            $send("STARTTLS");
            $r = $recv();
            if (stripos($r, '220') === 0) {
                // enable crypto and verify peer name (stream_socket_enable_crypto respects context)
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    fclose($socket);
                    return ['ok' => false, 'error' => 'starttls_failed'];
                }
                $send("EHLO " . (gethostname() ?: 'localhost'));
                $ehlo = $recv();
            } else {
                fclose($socket);
                return ['ok' => false, 'error' => 'starttls_not_supported'];
            }
        }

        if ($user !== '') {
            $send("AUTH LOGIN");
            $r = $recv();
            if (stripos($r, '334') === 0) {
                $send(base64_encode($user));
                $r = $recv();
                if (stripos($r, '334') !== 0) { fclose($socket); return ['ok'=>false,'error'=>'smtp_auth_user_rejected']; }
                $send(base64_encode($pass));
                $r = $recv();
                if (stripos($r, '235') !== 0) { fclose($socket); return ['ok'=>false,'error'=>'smtp_auth_failed']; }
            } else {
                // server didn't accept AUTH right now - proceed and let RCPT/MAIL fail if needed
            }
        }

        try {
            $msgId = bin2hex(random_bytes(8)) . '@' . (self::$config['app_domain'] ?? 'localhost');
        } catch (\Throwable $e) {
            fclose($socket);
            return ['ok' => false, 'error' => 'random_bytes_failed'];
        }

        $boundary = 'b' . bin2hex(random_bytes(8));
        $headers = [];
        $headers[] = 'From: ' . (self::encodeHeader($fromName) !== '' ? (self::encodeHeader($fromName) . " <{$fromEmail}>") : $fromEmail);
        $headers[] = 'To: ' . implode(', ', $rcpts);
        $headers[] = 'Subject: ' . self::encodeHeader($subject);
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
        $headers[] = 'Date: ' . gmdate('r');
        $headers[] = 'Message-ID: <' . $msgId . '>';
        $headers[] = 'X-Mailer: CustomMailer/1';
        $headersRaw = implode("\r\n", $headers);

        $bodyLines = [];
        $bodyLines[] = "--{$boundary}";
        $bodyLines[] = 'Content-Type: text/plain; charset="utf-8"';
        $bodyLines[] = 'Content-Transfer-Encoding: 8bit';
        $bodyLines[] = '';
        $bodyLines[] = $textBody;
        $bodyLines[] = '';
        $bodyLines[] = "--{$boundary}";
        $bodyLines[] = 'Content-Type: text/html; charset="utf-8"';
        $bodyLines[] = 'Content-Transfer-Encoding: 8bit';
        $bodyLines[] = '';
        $bodyLines[] = $htmlBody;
        $bodyLines[] = '';
        $bodyLines[] = "--{$boundary}--";
        $body = implode("\r\n", $bodyLines);

        $body = preg_replace_callback("/(^|\r\n)\./", function($m){ return $m[1] . '..'; }, $body);

        // envelope_from support (use for MAIL FROM to correctly route SPF/bounces)
        $envelopeFrom = $smtp['envelope_from'] ?? $fromEmail;

        $send("MAIL FROM:<{$envelopeFrom}>");
        $r = $recv();
        if (stripos($r, '250') !== 0) { fclose($socket); return ['ok' => false, 'error' => 'MAIL_FROM rejected: ' . trim($r)]; }

        foreach ($rcpts as $rto) {
            $send("RCPT TO:<{$rto}>");
            $r = $recv();
            if (!(stripos($r, '250') === 0 || stripos($r, '251') === 0)) {
                fclose($socket);
                return ['ok' => false, 'error' => 'RCPT_TO rejected: ' . trim($r)];
            }
        }

        $send("DATA");
        $r = $recv();
        if (stripos($r, '354') !== 0) { fclose($socket); return ['ok' => false, 'error' => 'DATA command rejected: ' . trim($r)]; }

        fwrite($socket, $headersRaw . "\r\n\r\n");
        fwrite($socket, $body . "\r\n.\r\n");

        $r = $recv();
        if (stripos($r, '250') !== 0) {
            fclose($socket);
            return ['ok' => false, 'error' => 'DATA send failed: ' . trim($r)];
        }

        $send("QUIT");
        $recv();
        fclose($socket);
        return ['ok' => true, 'error' => null];
    }

    private static function encodeHeader(string $s): string
    {
        if ($s === '') return '';
        if (preg_match('/[^\x20-\x7F]/', $s)) {
            return '=?UTF-8?B?' . base64_encode($s) . '?=';
        }
        return $s;
    }
}
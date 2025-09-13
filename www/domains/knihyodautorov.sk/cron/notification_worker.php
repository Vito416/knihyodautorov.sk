<?php
// notification_worker.php
declare(strict_types=1);

// CLI worker for processing notifications (email). Run under CLI (cron).
// Requires: PHP with sodium, PHPMailer available via autoload, KEYs_DIR constant or env, PDO connection.

// ---- CONFIG ----
$MAX_PROCESSED = 10;           // how many notifications per run
$LEASE_SECONDS  = 600;         // how long the lock lasts (10 minutes)
$WORKER_ID = gethostname() . '-' . getmypid();

// ---- bootstrap / include your app bootstrap so $pdo, $config, APP_URL, KEYS_DIR are available ----
$bootstrap = __DIR__ . '/inc/bootstrap.php'; // adjust path if needed
if (!is_file($bootstrap)) {
    fwrite(STDERR, "Bootstrap not found: $bootstrap\n");
    exit(1);
}
require $bootstrap; // must set $db (PDO) or $pdo
$pdo = $db ?? ($pdo ?? null);
if (!($pdo instanceof PDO)) {
    fwrite(STDERR, "PDO \$db not available from bootstrap\n");
    exit(1);
}

// optional config for mail (expects $config['smtp'])
$smtp = $config['smtp'] ?? [];

// helper: load specific crypto_key version file (expects files like crypto_key_v1.bin in KEYS_DIR)
function loadCryptoKeyByVersion(string $keysDir, string $basename, string $versionStr): string {
    $ver = (int) filter_var($versionStr, FILTER_SANITIZE_NUMBER_INT);
    if ($ver <= 0) $ver = 1;
    $path = rtrim($keysDir, '/\\') . '/' . $basename . '_v' . $ver . '.bin';
    if (!is_readable($path)) {
        throw new RuntimeException("Crypto key file not readable: {$path}");
    }
    $raw = @file_get_contents($path);
    if ($raw === false) throw new RuntimeException("Failed to read key file: {$path}");
    return $raw; // binary
}

// helper: exponential backoff minutes
function computeBackoffMinutes(int $retries): int {
    // min 2^retries minutes capped to 24h in minutes
    $mins = (int) pow(2, max(1, $retries));
    $cap = 60 * 24;
    return min($mins, $cap);
}

// ensure libsodium
if (!extension_loaded('sodium')) {
    fwrite(STDERR, "libsodium not available\n");
    exit(1);
}

// main loop: process up to $MAX_PROCESSED notifications
$processed = 0;
for ($i = 0; $i < $MAX_PROCESSED; $i++) {
    try {
        // 1) Try to claim a row atomically using SELECT ... FOR UPDATE SKIP LOCKED (MySQL 8+)
        $pdo->beginTransaction();
        $selectSql = "
            SELECT id, user_id, payload, retries, max_retries
            FROM notifications
            WHERE status = 'pending'
              AND (next_attempt_at IS NULL OR next_attempt_at <= UTC_TIMESTAMP())
              AND (scheduled_at IS NULL OR scheduled_at <= UTC_TIMESTAMP())
            ORDER BY priority DESC, next_attempt_at ASC, created_at ASC
            LIMIT 1
            FOR UPDATE SKIP LOCKED
        ";
        $stmt = $pdo->prepare($selectSql);
        try {
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $ex) {
            // SKIP LOCKED may not be supported -> fallback to non-locking select
            $pdo->rollBack();
            $row = null;
        }

        // fallback claim if SKIP LOCKED not supported or returned nothing
        if (!$row) {
            // try atomic UPDATE ... WHERE status='pending' LIMIT 1 pattern
            // select candidate id without locking
            $candidate = $pdo->query("
                SELECT id FROM notifications
                WHERE status = 'pending'
                  AND (next_attempt_at IS NULL OR next_attempt_at <= UTC_TIMESTAMP())
                  AND (scheduled_at IS NULL OR scheduled_at <= UTC_TIMESTAMP())
                ORDER BY priority DESC, next_attempt_at ASC, created_at ASC
                LIMIT 1
            ")->fetch(PDO::FETCH_COLUMN);

            if (!$candidate) {
                // nothing to do
                $pdo->commit();
                break;
            }

            // try to claim it with an atomic update (only if still pending)
            $claimStmt = $pdo->prepare("
                UPDATE notifications
                SET status='processing', locked_by = ?, locked_until = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND), last_attempt_at = UTC_TIMESTAMP()
                WHERE id = ? AND status = 'pending'
            ");
            $claimStmt->execute([$WORKER_ID, $LEASE_SECONDS, (int)$candidate]);
            if ($claimStmt->rowCount() === 0) {
                // lost race, try next
                $pdo->commit();
                continue;
            }
            // fetch claimed row
            $rowStmt = $pdo->prepare("SELECT id, user_id, payload, retries, max_retries FROM notifications WHERE id = ? LIMIT 1");
            $rowStmt->execute([(int)$candidate]);
            $row = $rowStmt->fetch(PDO::FETCH_ASSOC);
            $pdo->commit();
        } else {
            // we have row locked by FOR UPDATE SKIP LOCKED; update to processing + locked_until
            $id = (int)$row['id'];
            $upd = $pdo->prepare("
                UPDATE notifications
                SET status='processing', locked_by = ?, locked_until = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND), last_attempt_at = UTC_TIMESTAMP()
                WHERE id = ?
            ");
            $upd->execute([$WORKER_ID, $LEASE_SECONDS, $id]);
            $pdo->commit();
            // reload row to have fresh values
            $rowStmt = $pdo->prepare("SELECT id, user_id, payload, retries, max_retries FROM notifications WHERE id = ? LIMIT 1");
            $rowStmt->execute([$id]);
            $row = $rowStmt->fetch(PDO::FETCH_ASSOC);
        }

        if (!$row) {
            // nothing claimed
            break;
        }

        // process the claimed notification
        $notifId = (int)$row['id'];
        $userId = (int)$row['user_id'];
        $retries = (int)$row['retries'];
        $maxRetries = (int)$row['max_retries'];
        $payload = json_decode($row['payload'] ?? '{}', true);
        if (!is_array($payload)) $payload = [];

        // Only handle email channel for now (extendable)
        // expect payload.vars.encrypted_token and payload.vars.crypto_key_version and payload.to and payload.subject
        $channel = $payload['channel'] ?? 'email';
        if ($channel !== 'email') {
            // unsupported -> mark failed
            $pdo->prepare("UPDATE notifications SET status='failed', error = ?, locked_by = NULL, locked_until = NULL WHERE id = ?")
                ->execute(['unsupported channel', $notifId]);
            $processed++;
            continue;
        }

        $to = $payload['to'] ?? ($payload['vars']['to'] ?? null);
        $subject = $payload['subject'] ?? ($payload['vars']['subject'] ?? null);
        $vars = $payload['vars'] ?? [];

        if (empty($vars['encrypted_token']) || empty($vars['crypto_key_version']) || empty($to)) {
            $err = 'payload missing required fields';
            $pdo->prepare("UPDATE notifications SET status='failed', error = ?, locked_by = NULL, locked_until = NULL WHERE id = ?")
                ->execute([$err, $notifId]);
            $processed++;
            continue;
        }

        // decode encrypted token
        $encB64 = $vars['encrypted_token'];
        $raw = base64_decode($encB64, true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES) {
            throw new RuntimeException('Invalid encrypted_token format');
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

        // load correct crypto key version
        $cryptoVersionStr = (string)$vars['crypto_key_version'];
        try {
            $cryptoKey = loadCryptoKeyByVersion(KEYS_DIR, 'crypto_key', $cryptoVersionStr);
        } catch (Throwable $e) {
            throw new RuntimeException('Failed to load crypto key: ' . $e->getMessage());
        }

        // decrypt (returns raw token string, e.g. hex)
        $tokenRaw = @sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($cipher, '', $nonce, $cryptoKey);
        if ($tokenRaw === false) {
            throw new RuntimeException('Decrypt failed for encrypted_token');
        }

        // Build verify URL
        $base = rtrim(defined('APP_URL') ? APP_URL : ($_ENV['APP_URL'] ?? 'https://example.com'), '/');
        $verifyUrl = $base . '/verify_email.php?uid=' . $userId . '&token=' . rawurlencode($tokenRaw);

        // Send email (PHPMailer)
        try {
            if (!class_exists('\PHPMailer\PHPMailer\PHPMailer')) {
                throw new RuntimeException('PHPMailer not available');
            }
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            if (!empty($smtp['host'])) {
                $mail->isSMTP();
                $mail->Host = $smtp['host'];
                $mail->SMTPAuth = !empty($smtp['user']);
                if (!empty($smtp['user'])) {
                    $mail->Username = $smtp['user'];
                    $mail->Password = $smtp['pass'];
                }
                if (!empty($smtp['port'])) $mail->Port = (int)$smtp['port'];
                if (!empty($smtp['secure'])) $mail->SMTPSecure = $smtp['secure'];
                $mail->Timeout = isset($smtp['timeout']) ? (int)$smtp['timeout'] : 15;
            }
            $mail->CharSet = 'UTF-8';
            $mail->Encoding = 'base64';
            $fromEmail = $smtp['from_email'] ?? ('no-reply@' . ($_SERVER['HTTP_HOST'] ?? 'example.com'));
            $fromName = $smtp['from_name'] ?? (defined('APP_NAME') ? APP_NAME : 'Service');
            $mail->setFrom($fromEmail, $fromName);
            $mail->addAddress($to);
            $mail->Subject = $subject ?? (defined('APP_NAME') ? APP_NAME . ' Notification' : 'Notification');
            $mail->isHTML(false);
            $body = "Dobrý deň,\n\nKliknite na tento odkaz pre overenie e-mailu:\n\n{$verifyUrl}\n\nOdkaz platí do: " . ($vars['expires_at'] ?? '') . "\n\nS pozdravom";
            $mail->Body = $body;

            $mail->send();

            // success -> mark sent
            $stmt = $pdo->prepare("UPDATE notifications SET status='sent', sent_at = UTC_TIMESTAMP(), error = NULL, locked_by = NULL, locked_until = NULL WHERE id = ?");
            $stmt->execute([$notifId]);

        } catch (Throwable $sendEx) {
            // handle failure -> increment retries + schedule next attempt (exponential backoff)
            $errMsg = mb_substr($sendEx->getMessage(), 0, 1000);
            $retries++;
            if ($retries >= $maxRetries) {
                $stmt = $pdo->prepare("UPDATE notifications SET status='failed', error = ?, retries = ?, locked_by = NULL, locked_until = NULL WHERE id = ?");
                $stmt->execute([$errMsg, $retries, $notifId]);
            } else {
                $delayMins = computeBackoffMinutes($retries);
                $stmt = $pdo->prepare("UPDATE notifications SET status='pending', error = ?, retries = ?, next_attempt_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? MINUTE), locked_by = NULL, locked_until = NULL WHERE id = ?");
                $stmt->execute([$errMsg, $retries, $delayMins, $notifId]);
            }
        }

        $processed++;

    } catch (Throwable $e) {
        // unexpected error during claim or processing: log and continue
        fwrite(STDERR, '[worker] Exception: ' . $e->getMessage() . PHP_EOL);
        error_log('[worker] ' . $e->getMessage());
        // ensure transaction not left open
        if ($pdo->inTransaction()) {
            try { $pdo->rollBack(); } catch (Throwable $_) {}
        }
        // small sleep to avoid busy loop on fatal error
        sleep(1);
        continue;
    }
}

// exit with status
fwrite(STDOUT, "Processed: {$processed}\n");
exit(0);
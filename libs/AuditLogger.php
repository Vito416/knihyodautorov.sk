<?php
declare(strict_types=1);

/**
 * libs/AuditLogger.php
 *
 * Loguje auditní záznamy šifrovaně. Preferuje samostatný AUDIT_KEY (verzovaný v keys dir).
 * Pokud AUDIT_KEY není dostupný, použije Crypto::encrypt (master key).
 *
 * Uloží do DB pokud je PDO poskytnuto, jinak do souboru storage/audit/audit.log (encrypted entries).
 *
 * NEVER prints keys or plaintext payloads.
 */

final class AuditLogger
{
    /**
     * Loguje událost.
     * @param PDO|null $pdo  - pokud provided, uloží do DB
     * @param int|null $actorId
     * @param string $action
     * @param array $details
     * @param string|null $keyVersion - volitelně verze klíče použitá pro obsah
     * @return bool
     */
    public static function log(?PDO $pdo, ?int $actorId, string $action, array $details, ?string $keyVersion = null): bool
    {
        // prepare payload
        $payloadArr = [
            'ts' => gmdate('Y-m-d\TH:i:s\Z'),
            'actor_id' => $actorId,
            'action' => $action,
            'details' => $details,
        ];
        $payload = json_encode($payloadArr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) return false;

        // try audit-specific key first (KeyManager)
        $enc = null;
        $enc_method = null;
        try {
            if (class_exists('KeyManager')) {
                $keysDir = $GLOBALS['config']['paths']['keys'] ?? (__DIR__ . '/../secure/keys');
                // getRawKeyBytes returns ['raw' => ..., 'version'=> 'vN']
                $info = null;
                try {
                    $info = KeyManager::getRawKeyBytes('AUDIT_KEY', $keysDir, 'audit_key', false);
                } catch (Throwable $e) {
                    $info = null;
                }

                if (is_array($info) && isset($info['raw']) && is_string($info['raw'])) {
                    // use sodium AEAD to encrypt payload, return compact_base64 (nonce|tag|cipher) base64
                    $rawKey = $info['raw'];
                    $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
                    $combined = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($payload, '', $nonce, $rawKey);
                    if ($combined === false) throw new RuntimeException('audit encrypt failed');
                    $tagSize = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES;
                    $tag = substr($combined, -$tagSize);
                    $cipher = substr($combined, 0, -$tagSize);
                    // compact: nonce|tag|cipher then base64
                    $enc = base64_encode($nonce . $tag . $cipher);
                    $enc_method = 'audit_key';
                    // zero rawKey from memory best-effort
                    KeyManager::memzero($rawKey);
                }
            }
        } catch (Throwable $e) {
            error_log('[AuditLogger] audit-key encryption failed: ' . $e->getMessage());
            $enc = null;
        }

        // fallback: use Crypto (must be initialized)
        if ($enc === null) {
            try {
                if (!class_exists('Crypto')) throw new RuntimeException('Crypto class unavailable');
                $enc = Crypto::encrypt($payload, 'compact_base64');
                $enc_method = 'master_crypto';
            } catch (Throwable $e) {
                error_log('[AuditLogger] fallback Crypto encrypt failed: ' . $e->getMessage());
                return false;
            }
        }

        $now = gmdate('Y-m-d H:i:s');

        // Try DB insert first
        if ($pdo instanceof PDO) {
            try {
                $stmt = $pdo->prepare('INSERT INTO audit_log (event_time, actor_id, action, payload_enc, key_version, meta) VALUES (:t,:actor,:action,:payload,:kv,:meta)');
                $meta = json_encode(['stored_via'=>'db','enc_method'=>$enc_method], JSON_UNESCAPED_SLASHES);
                $stmt->execute([
                    ':t' => $now,
                    ':actor' => $actorId,
                    ':action' => $action,
                    ':payload' => $enc,
                    ':kv' => $keyVersion,
                    ':meta' => $meta
                ]);
                return true;
            } catch (Throwable $e) {
                error_log('[AuditLogger] DB insert failed: ' . $e->getMessage());
                // fallthrough to file fallback
            }
        }

        // File fallback
        try {
            $storage = $GLOBALS['config']['paths']['storage'] ?? (__DIR__ . '/../secure/storage');
            $auditDir = rtrim($storage, '/\\') . '/audit';
            if (!is_dir($auditDir)) @mkdir($auditDir, 0750, true);
            $entry = json_encode(['ts'=>$now,'actor'=>$actorId,'action'=>$action,'payload'=>$enc,'key_version'=>$keyVersion,'enc_method'=>$enc_method], JSON_UNESCAPED_SLASHES);
            if ($entry !== false) {
                $f = $auditDir . '/audit.log';
                file_put_contents($f, $entry . PHP_EOL, FILE_APPEND | LOCK_EX);
                @chmod($f, 0600);
                return true;
            }
        } catch (Throwable $e) {
            error_log('[AuditLogger] file fallback failed: ' . $e->getMessage());
        }

        return false;
    }
}
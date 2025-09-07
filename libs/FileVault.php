<?php
declare(strict_types=1);

/**
 * libs/FileVault.php
 *
 * Secure file-at-rest helper using libsodium (PHP 8.1+).
 * - Uses KeyManager for key retrieval (versioned keys supported)
 * - Writes canonical binary payload and .meta including key_version & encryption_algo
 * - Supports streaming (secretstream) for large files
 * - Calls AuditLogger::log() after successful downloads (best-effort)
 *
 * Public API:
 *   FileVault::uploadAndEncrypt(string $srcTmp, string $destEnc): string|false
 *   FileVault::decryptAndStream(string $encPath, string $downloadName, string $mimeType = 'application/octet-stream'): bool
 *   FileVault::deleteFile(string $path): bool
 */

require_once __DIR__ . '/KeyManager.php';
require_once __DIR__ . '/AuditLogger.php';
require_once __DIR__ . '/Crypto.php';

final class FileVault
{
    private const VERSION = 1;
    private const STREAM_THRESHOLD = 20 * 1024 * 1024; // 20 MB
    private const FRAME_SIZE = 1 * 1024 * 1024; // 1 MB

    /**
     * Helper: get key raw bytes and version for filevault keys.
     * If $specificVersion provided (like 'v1'), try to load exact file: filevault_key_v1.bin
     * Returns ['raw' => <bytes>, 'version' => 'vN']
     * Throws RuntimeException on failure.
     */
    private static function getFilevaultKeyInfo(?string $specificVersion = null): array
    {
        $keysDir = $GLOBALS['config']['paths']['keys'] ?? (__DIR__ . '/../secure/keys');
        // If specific version requested, attempt to load exact file
        if ($specificVersion !== null && $specificVersion !== '') {
            $verNum = ltrim($specificVersion, 'vV');
            if ($verNum === '') $verNum = '1';
            $path = rtrim($keysDir, '/\\') . '/filevault_key_v' . $verNum . '.bin';
            if (is_readable($path)) {
                $raw = @file_get_contents($path);
                if ($raw !== false && strlen($raw) === SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
                    return ['raw' => $raw, 'version' => 'v' . $verNum];
                }
            }
            // fallback: try exact non-versioned name
            $path2 = rtrim($keysDir, '/\\') . '/filevault_key.bin';
            if (is_readable($path2)) {
                $raw = @file_get_contents($path2);
                if ($raw !== false && strlen($raw) === SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
                    return ['raw' => $raw, 'version' => 'v1'];
                }
            }
            // else fallthrough to locateLatest (below)
        }

        // fallback: use KeyManager locate latest
        try {
            $info = KeyManager::getRawKeyBytes('FILEVAULT_KEY', $keysDir, 'filevault_key', false);
            if (!is_array($info) || !isset($info['raw'])) {
                throw new RuntimeException('KeyManager did not return key info');
            }
            return ['raw' => $info['raw'], 'version' => $info['version'] ?? 'v1'];
        } catch (Throwable $e) {
            // Rethrow as runtime exception for caller to handle
            throw new RuntimeException('getFilevaultKeyInfo failure: ' . $e->getMessage());
        }
    }

    /**
     * Encrypt uploaded file and write canonical binary payload to destination.
     * Returns destination path on success, or false on error.
     *
     * @param string $srcTmp
     * @param string $destEnc
     * @return string|false
     */
    public static function uploadAndEncrypt(string $srcTmp, string $destEnc)
    {
        if (!is_readable($srcTmp)) {
            self::logError('uploadAndEncrypt: source not readable: ' . $srcTmp);
            return false;
        }

        // try to get key info (throws on fatal)
        try {
            $keyInfo = self::getFilevaultKeyInfo(null);
            $key = $keyInfo['raw'];
            $keyVersion = $keyInfo['version'];
        } catch (Throwable $e) {
            self::logError('uploadAndEncrypt: key retrieval failed: ' . $e->getMessage());
            return false;
        }

        $filesize = filesize($srcTmp) ?: 0;
        $destDir = dirname($destEnc);
        if (!is_dir($destDir)) {
            if (!@mkdir($destDir, 0750, true)) {
                self::logError('uploadAndEncrypt: failed to create destination directory: ' . $destDir);
                return false;
            }
        }

        $tmpDest = $destEnc . '.tmp-' . bin2hex(random_bytes(6));
        $out = @fopen($tmpDest, 'wb');
        if ($out === false) {
            self::logError('uploadAndEncrypt: cannot open destination for write: ' . $tmpDest);
            return false;
        }

        // write version byte
        if (fwrite($out, chr(self::VERSION)) === false) {
            fclose($out); @unlink($tmpDest);
            self::logError('uploadAndEncrypt: failed writing version byte');
            return false;
        }

        $useStream = ($filesize > self::STREAM_THRESHOLD);

        if ($useStream) {
            // secretstream init_push
            $res = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
            if (!is_array($res) || count($res) !== 2) {
                fclose($out); @unlink($tmpDest);
                self::logError('uploadAndEncrypt: secretstream init_push failed');
                return false;
            }
            [$state, $header] = $res;
            if (!is_string($header) || strlen($header) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES) {
                fclose($out); @unlink($tmpDest);
                self::logError('uploadAndEncrypt: secretstream header invalid length');
                return false;
            }

            $iv_len = strlen($header);
            if ($iv_len > 255) { fclose($out); @unlink($tmpDest); self::logError('uploadAndEncrypt: header too long'); return false; }

            if (fwrite($out, chr($iv_len)) === false || fwrite($out, $header) === false) {
                fclose($out); @unlink($tmpDest); self::logError('uploadAndEncrypt: failed writing header'); return false;
            }

            // tag_len == 0 marks secretstream mode
            if (fwrite($out, chr(0)) === false) { fclose($out); @unlink($tmpDest); self::logError('uploadAndEncrypt: failed writing tag_len'); return false; }

            $in = @fopen($srcTmp, 'rb');
            if ($in === false) { fclose($out); @unlink($tmpDest); self::logError('uploadAndEncrypt: cannot open source for read: ' . $srcTmp); return false; }

            try {
                while (!feof($in)) {
                    $chunk = fread($in, self::FRAME_SIZE);
                    if ($chunk === false) throw new RuntimeException('uploadAndEncrypt: read error');
                    $isFinal = feof($in);
                    $tag = $isFinal ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;
                    $frame = sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk, '', $tag);
                    if ($frame === false) throw new RuntimeException('uploadAndEncrypt: secretstream push failed');

                    $frameLen = strlen($frame);
                    $lenBuf = pack('N', $frameLen);
                    if (fwrite($out, $lenBuf) === false || fwrite($out, $frame) === false) {
                        throw new RuntimeException('uploadAndEncrypt: write error while writing frame');
                    }
                }
            } catch (Throwable $e) {
                fclose($in); fclose($out); @unlink($tmpDest); self::logError('uploadAndEncrypt: ' . $e->getMessage()); return false;
            }

            fclose($in);
            fflush($out); fclose($out);

            // write meta atomically
            $meta = [
                'plain_size' => $filesize,
                'mode' => 'stream',
                'version' => self::VERSION,
                'key_version' => $keyVersion,
                'encryption_algo' => 'secretstream_xchacha20poly1305'
            ];
            @file_put_contents($tmpDest . '.meta', json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            @chmod($tmpDest, 0600);

            if (!@rename($tmpDest, $destEnc)) {
                @unlink($tmpDest);
                self::logError('uploadAndEncrypt: failed to rename tmp file to destination');
                return false;
            }
            @rename($tmpDest . '.meta', $destEnc . '.meta');
            return $destEnc;
        }

        // SINGLE-PASS small file
        $plaintext = file_get_contents($srcTmp);
        if ($plaintext === false) {
            fclose($out); @unlink($tmpDest);
            self::logError('uploadAndEncrypt: failed to read small source into memory');
            return false;
        }

        // AEAD encrypt
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $combined = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, '', $nonce, $key);
        if ($combined === false) {
            fclose($out); @unlink($tmpDest);
            self::logError('uploadAndEncrypt: AEAD encrypt failed');
            return false;
        }

        $tagLen = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES;
        $tag = substr($combined, -$tagLen);
        $cipher = substr($combined, 0, -$tagLen);

        $iv_len = strlen($nonce);
        if ($iv_len > 255 || $tagLen > 255) { fclose($out); @unlink($tmpDest); self::logError('uploadAndEncrypt: iv/tag too long'); return false; }

        if (fwrite($out, chr($iv_len)) === false || fwrite($out, $nonce) === false) { fclose($out); @unlink($tmpDest); self::logError('uploadAndEncrypt: failed writing iv'); return false; }
        if (fwrite($out, chr($tagLen)) === false || fwrite($out, $tag) === false) { fclose($out); @unlink($tmpDest); self::logError('uploadAndEncrypt: failed writing tag'); return false; }
        if (fwrite($out, $cipher) === false) { fclose($out); @unlink($tmpDest); self::logError('uploadAndEncrypt: failed writing ciphertext'); return false; }

        fflush($out); fclose($out);

        // meta
        $meta = [
            'plain_size' => strlen($plaintext),
            'mode' => 'single',
            'version' => self::VERSION,
            'key_version' => $keyVersion,
            'encryption_algo' => 'xchacha20poly1305_ietf'
        ];
        @file_put_contents($tmpDest . '.meta', json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        @chmod($tmpDest, 0600);

        if (!@rename($tmpDest, $destEnc)) {
            @unlink($tmpDest);
            self::logError('uploadAndEncrypt: failed to rename tmp file to dest');
            return false;
        }
        @rename($tmpDest . '.meta', $destEnc . '.meta');

        return $destEnc;
    }

    /**
     * Decrypt encrypted file and stream to client. Returns true on success, false on error.
     * Does not call exit().
     *
     * Attempts to read .meta for key_version and will try to load the exact key if available.
     *
     * @param string $encPath
     * @param string $downloadName
     * @param string $mimeType
     * @return bool
     */
    public static function decryptAndStream(string $encPath, string $downloadName, string $mimeType = 'application/octet-stream'): bool
    {
        if (!is_readable($encPath)) {
            self::logError('decryptAndStream: encrypted file not readable: ' . $encPath);
            return false;
        }

        // Try read meta to get key_version and expected plain_size
        $metaPath = $encPath . '.meta';
        $meta = null;
        $metaJson = null;
        if (is_readable($metaPath)) {
            $metaJson = @file_get_contents($metaPath);
            if ($metaJson !== false) {
                $tmp = json_decode($metaJson, true);
                if (is_array($tmp)) $meta = $tmp;
            }
        }

        $specificKeyVersion = $meta['key_version'] ?? null;
        $contentLength = $meta['plain_size'] ?? null;

        // get key (try specific version first)
        try {
            $keyInfo = self::getFilevaultKeyInfo($specificKeyVersion);
            $key = $keyInfo['raw'];
            $keyVersion = $keyInfo['version'];
        } catch (Throwable $e) {
            self::logError('decryptAndStream: key retrieval failed: ' . $e->getMessage());
            return false;
        }

        $fh = @fopen($encPath, 'rb');
        if ($fh === false) {
            self::logError('decryptAndStream: cannot open encrypted file: ' . $encPath);
            return false;
        }

        try {
            // version
            $versionByte = fread($fh, 1);
            if ($versionByte === false || strlen($versionByte) !== 1) {
                self::logError('decryptAndStream: failed reading version byte');
                return false;
            }
            $version = ord($versionByte);
            if ($version !== self::VERSION) {
                self::logError('decryptAndStream: unsupported version: ' . $version);
                return false;
            }

            // iv_len
            $b = fread($fh, 1);
            if ($b === false || strlen($b) !== 1) {
                self::logError('decryptAndStream: failed reading iv_len');
                return false;
            }
            $iv_len = ord($b);
            if ($iv_len < 0 || $iv_len > 255) {
                self::logError('decryptAndStream: unreasonable iv_len: ' . $iv_len);
                return false;
            }

            $iv = '';
            if ($iv_len > 0) {
                $iv = fread($fh, $iv_len);
                if ($iv === false || strlen($iv) !== $iv_len) {
                    self::logError('decryptAndStream: failed reading iv/header');
                    return false;
                }
            }

            // tag_len
            $b = fread($fh, 1);
            if ($b === false || strlen($b) !== 1) {
                self::logError('decryptAndStream: failed reading tag_len');
                return false;
            }
            $tag_len = ord($b);
            if ($tag_len < 0 || $tag_len > 255) {
                self::logError('decryptAndStream: unreasonable tag_len: ' . $tag_len);
                return false;
            }

            $tag = '';
            if ($tag_len > 0) {
                $tag = fread($fh, $tag_len);
                if ($tag === false || strlen($tag) !== $tag_len) {
                    self::logError('decryptAndStream: failed reading tag');
                    return false;
                }
            }

            // Prepare headers
            if (!headers_sent()) {
                header('Content-Type: ' . $mimeType);
                $safeName = basename((string)$downloadName);
                header('Content-Disposition: attachment; filename="' . $safeName . '"');
                if ($contentLength !== null) {
                    header('Content-Length: ' . (string)$contentLength);
                } else {
                    header('Transfer-Encoding: chunked');
                }
            }

            if ($tag_len > 0) {
                // single-pass: rest is cipher (without tag)
                $cipher = stream_get_contents($fh);
                if ($cipher === false) {
                    self::logError('decryptAndStream: failed reading ciphertext');
                    return false;
                }
                $combined = $cipher . $tag;
                $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($combined, '', $iv, $key);
                if ($plain === false) {
                    self::logError('decryptAndStream: single-pass decryption failed (auth)');
                    return false;
                }

                // stream plaintext
                $pos = 0;
                $len = strlen($plain);
                while ($pos < $len) {
                    $chunk = substr($plain, $pos, self::FRAME_SIZE);
                    echo $chunk;
                    $pos += strlen($chunk);
                    @ob_flush(); @flush();
                }

                // audit log (best-effort)
                self::maybeAudit($encPath, $downloadName, $contentLength ?? strlen($plain), $keyVersion);

                return true;
            }

            // STREAM mode: secretstream frames
            try {
                $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($iv, $key);
            } catch (Throwable $e) {
                self::logError('decryptAndStream: secretstream init_pull failed: ' . $e->getMessage());
                return false;
            }

            $outTotal = 0;
            while (!feof($fh)) {
                $lenBuf = fread($fh, 4);
                if ($lenBuf === false || strlen($lenBuf) === 0) {
                    break; // EOF
                }
                if (strlen($lenBuf) !== 4) {
                    self::logError('decryptAndStream: incomplete frame length header');
                    return false;
                }
                $un = unpack('Nlen', $lenBuf);
                $frameLen = $un['len'] ?? 0;
                if ($frameLen <= 0) {
                    self::logError('decryptAndStream: invalid frame length: ' . $frameLen);
                    return false;
                }
                $frame = fread($fh, $frameLen);
                if ($frame === false || strlen($frame) !== $frameLen) {
                    self::logError('decryptAndStream: failed reading frame payload');
                    return false;
                }

                $res = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $frame);
                if ($res === false || !is_array($res)) {
                    self::logError('decryptAndStream: secretstream pull failed (auth?)');
                    return false;
                }
                [$plainChunk, $tagFrame] = $res;
                echo $plainChunk;
                $outTotal += strlen($plainChunk);
                @ob_flush(); @flush();

                if ($tagFrame === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                    break;
                }
            }

            // audit log (best-effort)
            self::maybeAudit($encPath, $downloadName, $contentLength ?? $outTotal, $keyVersion);

            return true;
        } finally {
            fclose($fh);
        }
    }

    /**
     * Delete file safely if inside configured storage.
     */
    public static function deleteFile(string $path): bool
    {
        if (!file_exists($path)) return true;
        $real = realpath($path);
        if ($real === false) {
            self::logError('deleteFile: realpath failed for: ' . $path);
            return false;
        }

        if (!empty($GLOBALS['config']['paths']['storage'])) {
            $storageBase = $GLOBALS['config']['paths']['storage'];
            $storageReal = realpath($storageBase) ?: $storageBase;
            if (strpos($real, $storageReal) !== 0) {
                self::logError('deleteFile: refusing to delete outside configured storage: ' . $real);
                return false;
            }
        }

        return @unlink($real);
    }

    /**
     * Best-effort audit call. Does not break streaming on failure.
     * @param string $encPath
     * @param string $downloadName
     * @param int|null $plainSize
     * @param string|null $keyVersion
     */
    private static function maybeAudit(string $encPath, string $downloadName, ?int $plainSize, ?string $keyVersion = null): void
    {
        try {
            if (!class_exists('AuditLogger')) return;
            $pdo = $GLOBALS['pdo'] ?? null;
            $actorId = null;
            if (session_status() === PHP_SESSION_NONE) {
                @session_start();
            }
            if (isset($_SESSION['user_id']) && is_int($_SESSION['user_id'])) {
                $actorId = $_SESSION['user_id'];
            } elseif (isset($_SESSION['user_id'])) {
                $actorId = (int)$_SESSION['user_id'];
            }
            $details = [
                'enc_path' => $encPath,
                'download_name' => $downloadName,
                'plain_size' => $plainSize,
            ];
            // best-effort - pass PDO if available
            AuditLogger::log($pdo instanceof PDO ? $pdo : null, $actorId, 'file_download', $details, $keyVersion);
        } catch (Throwable $e) {
            // swallow
            error_log('[FileVault] audit log failed: ' . $e->getMessage());
        }
    }

    private static function logError(string $msg): void
    {
        if (class_exists('Logger') && method_exists('Logger', 'error')) {
            try {
                Logger::error('[FileVault] ' . $msg);
                return;
            } catch (Throwable $e) {
                // fallback
            }
        }
        error_log('[FileVault] ' . $msg);
    }
}
<?php
declare(strict_types=1);

/**
 * libs/Crypto.php
 *
 * Hardened libsodium-based Crypto helper.
 * - Use Crypto::init_from_base64($b64) or Crypto::init_from_file($path)
 * - Provides encrypt(), decrypt(), encrypt_legacy(), decrypt_legacy(), clearKey()
 */

final class Crypto
{
    private static ?string $key_bytes = null; // raw 32 bytes
    private const VERSION = 1;

    public static function init_from_base64(string $b64): void
    {
        if ($b64 === '') {
            throw new InvalidArgumentException('Crypto::init_from_base64 requires a non-empty base64 string');
        }
        $raw = base64_decode($b64, true);
        if ($raw === false) {
            throw new InvalidArgumentException('Crypto::init_from_base64: invalid base64');
        }
        if (strlen($raw) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new InvalidArgumentException(sprintf('Crypto key must be %d bytes after base64 decode', SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES));
        }
        if (self::$key_bytes !== null) {
            if (function_exists('sodium_memzero')) sodium_memzero(self::$key_bytes);
        }
        self::$key_bytes = $raw;
    }

    public static function init_from_file(string $path): void
    {
        if (!is_readable($path)) throw new InvalidArgumentException('Crypto::init_from_file: cannot read file');
        $raw = @file_get_contents($path);
        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new InvalidArgumentException('Crypto::init_from_file: invalid key file');
        }
        if (self::$key_bytes !== null && function_exists('sodium_memzero')) sodium_memzero(self::$key_bytes);
        self::$key_bytes = $raw;
    }

    public static function clearKey(): void
    {
        if (self::$key_bytes !== null) {
            if (function_exists('sodium_memzero')) sodium_memzero(self::$key_bytes);
            self::$key_bytes = null;
        }
    }

    public static function getKey(): string
    {
        if (self::$key_bytes === null) {
            throw new RuntimeException('Crypto::getKey: key not initialized');
        }
        return base64_encode(self::$key_bytes);
    }

    public static function encrypt(string $plaintext, string $outFormat = 'binary'): string
    {
        if (self::$key_bytes === null) {
            throw new RuntimeException('Crypto key not initialized (call init_from_base64 or init_from_file)');
        }
        if ($outFormat !== 'binary' && $outFormat !== 'compact_base64') {
            throw new InvalidArgumentException('Unsupported outFormat');
        }

        $nonceSize = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES; // 24
        $nonce = random_bytes($nonceSize);
        $combined = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, '', $nonce, self::$key_bytes);
        if ($combined === false) {
            throw new RuntimeException('Crypto::encrypt: encryption failed');
        }

        $tagSize = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES; // 16
        $tag = substr($combined, -$tagSize);
        $cipher = substr($combined, 0, -$tagSize);

        if ($outFormat === 'compact_base64') {
            return base64_encode($nonce . $tag . $cipher);
        }

        $iv_len = strlen($nonce);
        if ($iv_len > 255 || $tagSize > 255) {
            throw new RuntimeException('Crypto::encrypt produced iv/tag too large');
        }

        return chr(self::VERSION) . chr($iv_len) . $nonce . chr($tagSize) . $tag . $cipher;
    }

    public static function decrypt(string $payload): ?string
    {
        if (self::$key_bytes === null) {
            self::log('decrypt failed: key not initialized');
            return null;
        }
        if ($payload === '') {
            self::log('decrypt failed: empty payload');
            return null;
        }

        $decoded = base64_decode($payload, true);
        if ($decoded !== false) {
            if (strlen($decoded) >= 1 && ord($decoded[0]) === self::VERSION) {
                return self::decrypt_versioned($decoded);
            }
            $minLegacy = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES + 1;
            if (strlen($decoded) >= $minLegacy) {
                $iv = substr($decoded, 0, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
                $tag = substr($decoded, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES);
                $cipher = substr($decoded, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES);
                if ($iv === false || $tag === false || $cipher === false) {
                    self::log('decrypt failed: legacy base64 substr failed');
                    return null;
                }
                $combined = $cipher . $tag;
                $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($combined, '', $iv, self::$key_bytes);
                if ($plain === false) {
                    self::log('decrypt failed: legacy base64 decryption failed');
                    return null;
                }
                return $plain;
            }
            self::log('decrypt failed: base64 decoded does not match known patterns');
            return null;
        }

        if (strlen($payload) >= 1 && ord($payload[0]) === self::VERSION) {
            return self::decrypt_versioned($payload);
        }

        self::log('decrypt failed: unknown payload format');
        return null;
    }

    private static function decrypt_versioned(string $data): ?string
    {
        $len = strlen($data);
        if ($len < 1) {
            self::log('decrypt_versioned: empty');
            return null;
        }

        $ptr = 0;
        $version = ord($data[$ptr++]);
        if ($version !== self::VERSION) {
            self::log('decrypt_versioned: unsupported version ' . $version);
            return null;
        }

        if ($len < $ptr + 2) {
            self::log('decrypt_versioned: too short for headers');
            return null;
        }

        $iv_len = ord($data[$ptr++]);
        if ($iv_len < 1 || $iv_len > 255) {
            self::log('decrypt_versioned: unreasonable iv_len ' . $iv_len);
            return null;
        }

        if ($len < $ptr + $iv_len + 1) {
            self::log('decrypt_versioned: data too short for iv');
            return null;
        }

        $iv = substr($data, $ptr, $iv_len); $ptr += $iv_len;
        if ($iv === false || strlen($iv) !== $iv_len) {
            self::log('decrypt_versioned: iv read failed');
            return null;
        }

        $tag_len = ord($data[$ptr++]);
        if ($tag_len < 0 || $tag_len > 255) {
            self::log('decrypt_versioned: unreasonable tag_len ' . $tag_len);
            return null;
        }

        if ($tag_len > 0) {
            if ($len < $ptr + $tag_len) {
                self::log('decrypt_versioned: data too short for tag');
                return null;
            }
            $tag = substr($data, $ptr, $tag_len); $ptr += $tag_len;
            $cipher = substr($data, $ptr);
            if ($cipher === false) $cipher = '';

            $combined = $cipher . $tag;
            $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($combined, '', $iv, self::$key_bytes);
            if ($plain === false) {
                self::log('decrypt_versioned: single-pass decrypt failed');
                return null;
            }
            return $plain;
        }

        // STREAM mode (not used in this Crypto helper) - fallback
        self::log('decrypt_versioned: stream mode not supported in Crypto helper');
        return null;
    }

    private static function log(string $msg): void
    {
        // Do NOT log payloads or keys. Only non-sensitive messages here.
        error_log('[Crypto] ' . $msg);
    }

    // compatibility
    public static function encrypt_legacy(string $plaintext): string
    {
        return self::encrypt($plaintext, 'compact_base64');
    }

    public static function decrypt_legacy(string $b64): ?string
    {
        return self::decrypt($b64);
    }

    public static function encryptFile(string $src, string $dest): int
    {
        if (!is_readable($src)) throw new InvalidArgumentException('encryptFile: source not readable: ' . $src);
        if (class_exists('FileVault') && method_exists('FileVault', 'uploadAndEncrypt')) {
            $res = FileVault::uploadAndEncrypt($src, $dest);
            if ($res === false) throw new RuntimeException('encryptFile: FileVault failed');
            return filesize($dest) ?: 0;
        }
        throw new RuntimeException('encryptFile: FileVault not available');
    }
}
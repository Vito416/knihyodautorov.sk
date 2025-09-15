<?php
declare(strict_types=1);

/**
 * libs/Crypto.php
 *
 * Hardened libsodium-based Crypto helper.
 * - Use Crypto::init_from_base64($b64) or Crypto::init_from_file($path)
 * - Provides encrypt(), decrypt(), clearKey()
 */

final class Crypto
{
    private static ?string $key_bytes = null; // raw 32 bytes
    private const VERSION = 1;
    private const AD = 'app:crypto:v1'; // associated data for AEAD
    private static ?string $keysDir = null;
    public static function initFromKeyManager(?string $keysDir = null): void
    {
        self::$keysDir = $keysDir;
        $info = KeyManager::getRawKeyBytes('APP_CRYPTO_KEY', self::$keysDir, 'crypto_key');
        self::$key_bytes = $info['raw'];
    }

    public static function clearKey(): void
    {
        if (self::$key_bytes !== null) {
            if (function_exists('sodium_memzero')) sodium_memzero(self::$key_bytes);
            self::$key_bytes = null;
        }
    }

    public static function encrypt(string $plaintext, string $outFormat = 'binary'): string
    {
        if (self::$key_bytes === null) {
            throw new RuntimeException('Crypto key not initialized (call initFromKeyManager)');
        }
        if ($outFormat !== 'binary' && $outFormat !== 'compact_base64') {
            throw new InvalidArgumentException('Unsupported outFormat');
        }
        
        $nonceSize = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES; // 24
        $nonce = random_bytes($nonceSize);
        $combined = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, self::AD, $nonce, self::$key_bytes);
        if ($combined === false) {
            throw new RuntimeException('Crypto::encrypt: encryption failed');
        }

        if ($outFormat === 'compact_base64') {
            return base64_encode($nonce . $combined);
        }

        // Binární verzované payload
        $iv_len = strlen($nonce);
        if ($iv_len > 255) {
            throw new RuntimeException('Crypto::encrypt produced iv too large');
        }

        return chr(self::VERSION) . chr($iv_len) . $nonce . $combined;
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
                $cipher = substr($decoded, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

                $keys = KeyManager::getAllRawKeys('APP_CRYPTO_KEY', self::$keysDir, 'crypto_key');
                foreach ($keys as $k) {
                    $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($cipher, self::AD, $iv, $k);
                    if ($plain !== false) {
                        if (function_exists('sodium_memzero')) sodium_memzero($k);
                        return $plain;
                    }
                    if (function_exists('sodium_memzero')) sodium_memzero($k);
                }
                self::log('decrypt: all legacy keys exhausted');
                return null;
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
        if ($len < 2) {
            self::log('decrypt_versioned: too short');
            return null;
        }

        $ptr = 0;
        $version = ord($data[$ptr++]);
        if ($version !== self::VERSION) {
            self::log('decrypt_versioned: unsupported version ' . $version);
            return null;
        }

        $nonce_len = ord($data[$ptr++]);
        if ($nonce_len < 1 || $nonce_len > 255) {
            self::log('decrypt_versioned: unreasonable nonce_len ' . $nonce_len);
            return null;
        }

        if ($len < $ptr + $nonce_len) {
            self::log('decrypt_versioned: data too short for nonce');
            return null;
        }

        $nonce = substr($data, $ptr, $nonce_len);
        $ptr += $nonce_len;

        $cipher = substr($data, $ptr);
        if ($cipher === false || $cipher === '') {
            self::log('decrypt_versioned: no cipher data');
            return null;
        }

        $keys = KeyManager::getAllRawKeys('APP_CRYPTO_KEY', self::$keysDir, 'crypto_key');
        foreach ($keys as $k) {
            $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($cipher, self::AD, $nonce, $k);
            if ($plain !== false) {
                if (function_exists('sodium_memzero')) sodium_memzero($k);
                return $plain;
            }
            if (function_exists('sodium_memzero')) sodium_memzero($k);
        }

        self::log('decrypt_versioned: all keys exhausted');
        return null;
    }

    private static function log(string $msg): void
    {
        if (class_exists('Logger')) {
            Logger::systemMessage('error', 'Crypto error', null, [
                'stage' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function'] ?? 'unknown',
                'error' => $msg
            ]);
        }
    }
}
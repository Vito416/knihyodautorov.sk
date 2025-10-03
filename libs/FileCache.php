<?php
declare(strict_types=1);

use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException as PsrInvalidArgumentException;

/**
 * Production-ready file-based PSR-16 cache.
 *
 * - ALWAYS uses dedicated key material via KeyManager for encryption/decryption.
 * - No global Crypto fallback.
 * - Default key: ENV = CACHE_CRYPTO_KEY, basename = cache_crypto
 *
 * Stored format (serialized array):
 * [
 *   'expires' => int|null,
 *   'value' => string (plain or compact_base64 encrypted),
 *   'enc' => bool (true if encrypted),
 *   'key_version' => string|null
 * ]
 */
class FileCache implements CacheInterface
{
    private string $cacheDir;

    // encryption options
    private bool $useEncryption = false;
    private ?string $cryptoKeysDir = null;
    private string $cryptoEnvName = 'CACHE_CRYPTO_KEY';
    private string $cryptoBasename = 'cache_crypto';

    private const SAFE_PREFIX_LEN = 32;

    public static function ensurePsrExceptionExists(): void
    {
        // helper; PSR exception class defined below
    }

    /**
     * @param string|null $cacheDir path to cache directory (default: __DIR__ . '/../cache')
     * @param bool $useEncryption enable AEAD encryption using KeyManager keys
     * @param string|null $cryptoKeysDir directory where key files are stored (or null to rely on ENV)
     * @param string $cryptoEnvName env var name for base64 key fallback (default: CACHE_CRYPTO_KEY)
     * @param string $cryptoBasename basename for key files (default: cache_crypto)
     */
    public function __construct(
        ?string $cacheDir = null,
        bool $useEncryption = false,
        ?string $cryptoKeysDir = null,
        string $cryptoEnvName = 'CACHE_CRYPTO_KEY',
        string $cryptoBasename = 'cache_crypto'
    ) {
        $this->cacheDir = $cacheDir ?? __DIR__ . '/../cache';

        if (!is_dir($this->cacheDir)) {
            if (!@mkdir($this->cacheDir, 0700, true) && !is_dir($this->cacheDir)) {
                throw new RuntimeException("Cannot create cache directory: {$this->cacheDir}");
            }
            @chmod($this->cacheDir, 0700);
        }

        $this->useEncryption = $useEncryption;
        $this->cryptoKeysDir = $cryptoKeysDir;
        $this->cryptoEnvName = $cryptoEnvName;
        $this->cryptoBasename = $cryptoBasename;

if ($this->useEncryption) {
    if (!class_exists('KeyManager') || !class_exists('Crypto')) {
        throw new RuntimeException('KeyManager or Crypto class not available; cannot enable encryption.');
    }

    // ensure libsodium present early (clear diagnostics)
    try {
        KeyManager::requireSodium();
    } catch (Throwable $e) {
        throw new RuntimeException('libsodium extension required for FileCache encryption: ' . $e->getMessage());
    }

    // quick probe: ensure we have at least one candidate key (file or ENV)
    try {
        $candidates = KeyManager::getAllRawKeys(
            $this->cryptoEnvName,
            $this->cryptoKeysDir,
            $this->cryptoBasename,
            KeyManager::keyByteLen()
        );
        if (!is_array($candidates) || empty($candidates)) {
            throw new RuntimeException('No key material found for FileCache encryption (check ENV ' . $this->cryptoEnvName . ' or keysDir ' . ($this->cryptoKeysDir ?? 'null') . ').');
        }
        // memzero any probe copies (best-effort)
        foreach ($candidates as &$c) {
            try { KeyManager::memzero($c); } catch (Throwable $_) {}
        }
        unset($candidates);
    } catch (Throwable $e) {
        throw new RuntimeException('FileCache encryption initialization failed: ' . $e->getMessage());
    }
}

    }

    /**
     * Validate PSR-16 key.
     *
     * @throws PsrInvalidArgumentException
     */
    private function validateKey(string $key): void
    {
        if ($key === '') {
            throw new CacheInvalidArgumentException('Cache key must not be empty.');
        }
        // PSR-16 reserved characters
        if (preg_match('/[\{\}\(\)\/\\\\\@\:]/', $key)) {
            throw new CacheInvalidArgumentException('Cache key contains reserved characters {}()/\\@:');
        }
        // control characters
        if (preg_match('/[\x00-\x1F\x7F]/', $key)) {
            throw new CacheInvalidArgumentException('Cache key contains control characters.');
        }
        if (strlen($key) > 1024) {
            throw new CacheInvalidArgumentException('Cache key too long.');
        }
    }

    private function getPath(string $key): string
    {
        $prefix = preg_replace('/[^a-zA-Z0-9_\-]/', '_', mb_substr($key, 0, self::SAFE_PREFIX_LEN));
        if ($prefix === '') $prefix = 'key';
        $hash = hash('sha256', $key);
        return rtrim($this->cacheDir, '/\\') . '/' . $prefix . '_' . $hash . '.cache';
    }

    private function normalizeTtl($ttl): ?int
    {
        if ($ttl === null) return null;
        if ($ttl instanceof DateInterval) {
            $now = new DateTimeImmutable();
            $expiry = $now->add($ttl);
            return $expiry->getTimestamp() - $now->getTimestamp();
        }
        return (int)$ttl;
    }

    /**
     * Get value or $default.
     *
     * IMPORTANT: uses KeyManager exclusively. No global Crypto fallback.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $this->validateKey($key);
        $file = $this->getPath($key);
        if (!is_file($file)) return $default;

        $raw = @file_get_contents($file);
        if ($raw === false) return $default;

        $data = @unserialize($raw, ['allowed_classes' => false]);
        if (!is_array($data) || !array_key_exists('expires', $data) || !array_key_exists('value', $data)) {
            return $default;
        }
        if ($data['expires'] !== null && $data['expires'] < time()) {
            @unlink($file);
            return $default;
        }

        if (empty($data['enc'])) {
            return $data['value'];
        }

        $payload = $data['value'];
        $version = $data['key_version'] ?? null;

        // 1) if stored version, try it first
        if ($version !== null) {
            try {
                $info = KeyManager::getRawKeyBytesByVersion(
                    $this->cryptoEnvName,
                    $this->cryptoKeysDir,
                    $this->cryptoBasename,
                    $version,
                    KeyManager::keyByteLen()
                );
                $rawKey = $info['raw'];
                $plain = Crypto::decryptWithKeyCandidates($payload, [$rawKey]);

                // memzero the rawKey copy
                try { KeyManager::memzero($rawKey); } catch (Throwable $_) {}
                unset($rawKey, $info);

                if ($plain !== null) {
                    $val = @unserialize($plain, ['allowed_classes' => false]);
                    if ($val === false && $plain !== serialize(false)) return $default;
                    return $val;
                }
                // else fallthrough to try all keys
            } catch (Throwable $e) {
                // don't expose internals, just log
                error_log('[FileCache] decrypt_by_version failed: ' . $e->getMessage());
            }
        }

        // 2) try all available keys for this basename/env (newest->oldest)
        try {
            $candidates = KeyManager::getAllRawKeys(
                $this->cryptoEnvName,
                $this->cryptoKeysDir,
                $this->cryptoBasename,
                KeyManager::keyByteLen()
            );

            if (!is_array($candidates) || empty($candidates)) {
                error_log('[FileCache] No key material found for cache basename/env');
                return $default;
            }

            $plain = Crypto::decryptWithKeyCandidates($payload, $candidates);

            // memzero candidate copies (best-effort)
            foreach ($candidates as &$c) {
                try { KeyManager::memzero($c); } catch (Throwable $_) {}
            }
            unset($candidates);

            if ($plain === null) return $default;
            $val = @unserialize($plain, ['allowed_classes' => false]);
            if ($val === false && $plain !== serialize(false)) return $default;
            return $val;
        } catch (Throwable $e) {
            error_log('[FileCache] decrypt_all_keys failed: ' . $e->getMessage());
            return $default;
        }
    }

    /**
     * Set value.
     *
     * IMPORTANT: uses KeyManager + Crypto::encryptWithKeyBytes. No global fallback.
     */
    public function set(string $key, mixed $value, DateInterval|int|null $ttl = null): bool
    {
        $this->validateKey($key);
        $file = $this->getPath($key);
        $ttlSec = $this->normalizeTtl($ttl);
        $expires = $ttlSec !== null ? time() + $ttlSec : null;

        if (!$this->useEncryption) {
            $data = ['expires' => $expires, 'value' => $value];
        } else {
            // serialize
            $plain = serialize($value);

            // Obtain raw key + version from KeyManager (strict)
            try {
                $info = KeyManager::getRawKeyBytes(
                    $this->cryptoEnvName,
                    $this->cryptoKeysDir,
                    $this->cryptoBasename,
                    false,
                    KeyManager::keyByteLen()
                );
            } catch (Throwable $e) {
                error_log('[FileCache] failed to obtain raw key: ' . $e->getMessage());
                $plain = '';
                return false;
            }

            $raw = $info['raw'] ?? null;
            $version = $info['version'] ?? null;

            if (!is_string($raw) || strlen($raw) !== KeyManager::keyByteLen()) {
                try { KeyManager::memzero($raw); } catch (Throwable $_) {}
                $plain = '';
                error_log('[FileCache] invalid raw key returned by KeyManager');
                return false;
            }

            try {
                $encrypted = Crypto::encryptWithKeyBytes($plain, $raw, 'compact_base64');
            } catch (Throwable $e) {
                error_log('[FileCache] encryptWithKeyBytes failed: ' . $e->getMessage());
                try { KeyManager::memzero($raw); } catch (Throwable $_) {}
                $plain = '';
                return false;
            }

            // memzero raw key copy
            try { KeyManager::memzero($raw); } catch (Throwable $_) {}
            unset($raw, $info);

            $data = [
                'expires' => $expires,
                'value' => $encrypted,
                'enc' => true,
                'key_version' => $version,
            ];

            $plain = '';
        }

        // atomic write (temp file + rename)
        try {
            $tmp = $file . '.tmp-' . bin2hex(random_bytes(6));
            $written = @file_put_contents($tmp, serialize($data), LOCK_EX);
            if ($written === false) {
                @unlink($tmp);
                return false;
            }
            if (!@rename($tmp, $file)) {
                @unlink($tmp);
                return false;
            }
            @chmod($file, 0600);
            clearstatcache(true, $file);
            return true;
        } catch (Throwable $e) {
            error_log('[FileCache] set() error: ' . $e->getMessage());
            return false;
        }
    }

    public function delete(string $key): bool
    {
        $this->validateKey($key);
        $file = $this->getPath($key);
        if (is_file($file)) {
            try { return unlink($file); } catch (Throwable $_) { return false; }
        }
        return true;
    }

    public function clear(): bool
    {
        $success = true;
        $pattern = rtrim($this->cacheDir, '/\\') . '/*.cache';
        foreach (glob($pattern) as $file) {
            if (is_file($file) && !@unlink($file)) $success = false;
        }
        return $success;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $out = [];
        foreach ($keys as $k) {
            try {
                $this->validateKey($k);
                $out[$k] = $this->get($k, $default);
            } catch (PsrInvalidArgumentException $e) {
                throw $e;
            }
        }
        return $out;
    }

    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        $success = true;
        foreach ($values as $k => $v) {
            try {
                $this->validateKey($k);
                if (!$this->set($k, $v, $ttl)) $success = false;
            } catch (PsrInvalidArgumentException $e) {
                throw $e;
            }
        }
        return $success;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        $success = true;
        foreach ($keys as $k) {
            try {
                $this->validateKey($k);
                if (!$this->delete($k)) $success = false;
            } catch (PsrInvalidArgumentException $e) {
                throw $e;
            }
        }
        return $success;
    }

    public function has(string $key): bool
    {
        $this->validateKey($key);
        $file = $this->getPath($key);
        if (!is_file($file)) return false;

        $data = @unserialize(@file_get_contents($file), ['allowed_classes' => false]);
        if (!is_array($data) || !isset($data['expires'])) return false;
        if ($data['expires'] !== null && $data['expires'] < time()) {
            @unlink($file);
            return false;
        }
        return true;
    }
}

/**
 * PSR-16 compatible InvalidArgumentException for this file cache.
 */
class CacheInvalidArgumentException extends InvalidArgumentException implements PsrInvalidArgumentException {}
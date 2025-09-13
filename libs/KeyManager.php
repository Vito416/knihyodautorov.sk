<?php
declare(strict_types=1);

final class KeyManager
{
    private const DEFAULT_PER_REQUEST_CACHE_TTL = 300; // seconds, but here just per-request static cache

    private static array $cache = []; // simple per-request cache ['key_<env>_<basename>[_vN]'=> ['raw'=>..., 'version'=>...]]

    public static function requireSodium(): void
    {
        if (!extension_loaded('sodium')) {
            throw new RuntimeException('libsodium extension required');
        }
    }

    public static function keyByteLen(): int
    {
        return SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES;
    }

    /**
     * List available versioned key files for a basename (e.g. password_pepper or app_salt).
     * Returns array of versions => fullpath, e.g. ['v1'=>'/keys/app_salt_v1.bin','v2'=>...]
     *
     * @param string $keysDir
     * @param string $basename
     * @return array<string,string>
     */
    public static function listKeyVersions(string $keysDir, string $basename): array
    {
        $pattern = rtrim($keysDir, '/\\') . '/' . $basename . '_v*.bin';
        $out = [];
        foreach (glob($pattern) as $p) {
            if (!is_file($p)) continue;
            if (preg_match('/_v([0-9]+)\.bin$/', $p, $m)) {
                $ver = 'v' . (string)(int)$m[1];
                $out[$ver] = $p;
            }
        }
        // natural sort by version number
        if (!empty($out)) {
            uksort($out, function($a, $b){
                return ((int)substr($a,1)) <=> ((int)substr($b,1));
            });
        }
        return $out;
    }

    /**
     * Find latest key file or fallback exact basename.bin
     *
     * @return array|null ['path'=>'/full/path','version'=>'v2'] or null
     */
    public static function locateLatestKeyFile(string $keysDir, string $basename): ?array
    {
        $list = self::listKeyVersions($keysDir, $basename);
        if (!empty($list)) {
            end($list);
            $v = key($list);
            return ['path' => $list[$v], 'version' => $v];
        }

        $exact = rtrim($keysDir, '/\\') . '/' . $basename . '.bin';
        if (is_file($exact)) {
            return ['path' => $exact, 'version' => 'v1'];
        }

        return null;
    }

    /**
     * Return base64-encoded key (prefer versioned file; else env; optionally generate v1 in dev).
     *
     * @param string $envName name of env var holding base64 encoded key (e.g. 'APP_SALT' or 'PASSWORD_PEPPER')
     * @param string|null $keysDir
     * @param string $basename
     * @param bool $generateIfMissing
     * @return string base64-encoded key
     * @throws RuntimeException
     */
    public static function getBase64Key(string $envName, ?string $keysDir = null, string $basename = '', bool $generateIfMissing = false): string
    {
        self::requireSodium();
        $wantedLen = self::keyByteLen();

        if ($keysDir !== null && $basename !== '') {
            $info = self::locateLatestKeyFile($keysDir, $basename);
            if ($info !== null) {
                $raw = @file_get_contents($info['path']);
                if ($raw === false || strlen($raw) !== $wantedLen) {
                    throw new RuntimeException('Key file exists but invalid length: ' . $info['path']);
                }
                return base64_encode($raw);
            }
        }

        $envVal = $_ENV[$envName] ?? '';
        if ($envVal !== '') {
            $raw = base64_decode($envVal, true);
            if ($raw === false || strlen($raw) !== $wantedLen) {
                throw new RuntimeException(sprintf('ENV %s set but invalid base64 or wrong length', $envName));
            }
            return $envVal;
        }

        if ($generateIfMissing) {
            if ($keysDir === null || $basename === '') {
                throw new RuntimeException('generateIfMissing requires keysDir and basename');
            }
            $raw = random_bytes($wantedLen);
            $info = self::locateLatestKeyFile($keysDir, $basename);
            $next = 1;
            if ($info !== null && preg_match('/v([0-9]+)/', $info['version'], $m)) {
                $next = ((int)$m[1]) + 1;
            }
            $target = rtrim($keysDir, '/\\') . '/' . $basename . '_v' . $next . '.bin';
            self::atomicWriteKeyFile($target, $raw);
            return base64_encode($raw);
        }

        throw new RuntimeException(sprintf('Key not configured: %s (no key file, no env)', $envName));
    }

    /**
     * Return raw key bytes + version. Uses per-request cache to avoid repeated disk reads.
     *
     * @return array{raw:string,version:string}
     */
    public static function getRawKeyBytes(string $envName, ?string $keysDir = null, string $basename = '', bool $generateIfMissing = false, ?string $version = null): array
    {
        if ($version !== null && $keysDir !== null && $basename !== '') {
            return self::getRawKeyBytesByVersion($envName, $keysDir, $basename, $version);
        }
        $cacheKey = 'key_' . $envName . '_' . ($basename ?: 'env') . '_' . md5((string)$keysDir);
        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        $b64 = self::getBase64Key($envName, $keysDir, $basename, $generateIfMissing);
        $raw = base64_decode($b64, true);
        if ($raw === false) {
            throw new RuntimeException('Base64 decode failed in KeyManager for ' . $envName);
        }

        $ver = null;
        if ($keysDir !== null && $basename !== '') {
            $info = self::locateLatestKeyFile($keysDir, $basename);
            if ($info !== null) $ver = $info['version'];
        }

        $res = ['raw' => $raw, 'version' => $ver ?? 'v1'];
        self::$cache[$cacheKey] = $res;
        return $res;
    }

    /**
     * Read a specific versioned key file (e.g. 'v2') if present.
     * Returns ['raw'=>'...', 'version'=>'v2'] or throws if not found/invalid.
     */
    public static function getRawKeyBytesByVersion(string $envName, string $keysDir, string $basename, string $version): array
    {
        $version = ltrim($version, 'v'); // accept 'v2' or '2'
        $verStr = 'v' . (string)(int)$version;
        $path = rtrim($keysDir, '/\\') . '/' . $basename . '_' . $verStr . '.bin';
        if (!is_file($path)) {
            throw new RuntimeException('Requested key version not found: ' . $path);
        }
        $raw = @file_get_contents($path);
        if ($raw === false || strlen($raw) !== self::keyByteLen()) {
            throw new RuntimeException('Key file invalid or wrong length: ' . $path);
        }
        $cacheKey = 'key_' . $envName . '_' . $basename . '_' . $verStr;
        $res = ['raw' => $raw, 'version' => $verStr];
        self::$cache[$cacheKey] = $res;
        return $res;
    }

    /**
     * atomic write + perms (0400) for key files.
     */
    private static function atomicWriteKeyFile(string $path, string $raw): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0750, true)) {
                throw new RuntimeException('Failed to create keys directory: ' . $dir);
            }
        }

        $tmp = $path . '.tmp-' . bin2hex(random_bytes(6));
        $written = @file_put_contents($tmp, $raw, LOCK_EX);
        if ($written === false || $written !== strlen($raw)) {
            @unlink($tmp);
            throw new RuntimeException('Failed to write key temp file');
        }

        @chmod($tmp, 0400);

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Failed to atomically move key file to destination');
        }

        clearstatcache(true, $path);
        if (!is_readable($path) || filesize($path) !== strlen($raw)) {
            throw new RuntimeException('Key file appears corrupted after write');
        }
    }

    /**
     * Overwrite-sensitive string to zeros and clear variable.
     */
    public static function memzero(?string &$s): void
    {
        if ($s === null) {
            return;
        }
        if (function_exists('sodium_memzero')) {
            @sodium_memzero($s);
        } else {
            $s = str_repeat("\0", strlen($s));
        }
        $s = '';
    }

    /**
     * Convenience: get binary pepper + version (fail-fast).
     * Returns ['raw'=>binary,'version'=>'vN']
     */
    public static function getPasswordPepperInfo(?string $keysDir = null): array
    {
        $basename = 'password_pepper';
        $info = self::getRawKeyBytes('PASSWORD_PEPPER', $keysDir, $basename, false);
        if (empty($info['raw'])) {
            throw new RuntimeException('PASSWORD_PEPPER returned empty raw bytes.');
        }
        return $info;
    }

    /**
     * Convenience: legacy getPasswordPepper() for compatibility (returns binary raw only).
     */
    public static function getPasswordPepper(): string
    {
        $info = self::getPasswordPepperInfo();
        return $info['raw'];
    }

    /**
     * Convenience: get SALT (APP_SALT) info (raw bytes + version).
     * Use this for IP hashing.
     */
    public static function getSaltInfo(?string $keysDir = null): array
    {
        $basename = 'app_salt';
        $info = self::getRawKeyBytes('APP_SALT', $keysDir, $basename, false);
        if (empty($info['raw'])) {
            throw new RuntimeException('APP_SALT returned empty raw bytes.');
        }
        return $info;
    }
}
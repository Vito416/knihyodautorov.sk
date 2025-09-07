<?php
declare(strict_types=1);

final class KeyManager
{
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
     * Najdi nejnovější versioned key file podle basename (např. "filevault_key").
     * Hledá v $keysDir soubory jako filevault_key_v1.bin, filevault_key_v2.bin atd.
     * Pokud nic nenajde, zkusí přesnou cestu $keysDir . '/' . $basename . '.bin'.
     *
     * @return array|null ['path'=>'/full/path/file.bin','version'=>'v2'] nebo null pokud nic
     */
    public static function locateLatestKeyFile(string $keysDir, string $basename): ?array
    {
        $pattern = $keysDir . '/' . $basename . '_v*.bin';
        $matches = glob($pattern);
        $bestVer = 0;
        $bestPath = null;
        foreach ($matches as $p) {
            if (!is_file($p)) continue;
            if (preg_match('/_v([0-9]+)\.bin$/', $p, $m)) {
                $ver = (int)$m[1];
                if ($ver > $bestVer) {
                    $bestVer = $ver;
                    $bestPath = $p;
                }
            }
        }
        if ($bestPath !== null) {
            return ['path' => $bestPath, 'version' => 'v' . (string)$bestVer];
        }

        // fallback exact filename: basename.bin
        $exact = rtrim($keysDir, '/\\') . '/' . $basename . '.bin';
        if (is_file($exact)) {
            // no explicit version => return 'v1' implicit
            return ['path' => $exact, 'version' => 'v1'];
        }

        return null;
    }

    /**
     * Vrátí base64-encoded key. Preferuje verziovanou binární key file (produkce).
     * @param string $envName
     * @param string|null $keysDir directory where key files are stored
     * @param string $basename key basename without version suffix (e.g. 'filevault_key' or 'crypto_key')
     * @param bool $generateIfMissing dev-only - generate version v1
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
                    throw new RuntimeException('Key file exists but invalid: ' . $info['path']);
                }
                return base64_encode($raw);
            }
        }

        // fallback to $_ENV
        $envVal = $_ENV[$envName] ?? '';
        if ($envVal !== '') {
            $raw = base64_decode($envVal, true);
            if ($raw === false || strlen($raw) !== $wantedLen) {
                throw new RuntimeException(sprintf('ENV %s set but invalid base64 or wrong length', $envName));
            }
            return $envVal;
        }

        // optionally generate: create v1 file in keysDir with basename_v1.bin
        if ($generateIfMissing) {
            if ($keysDir === null || $basename === '') {
                throw new RuntimeException('generateIfMissing requires keysDir and basename');
            }
            $raw = random_bytes($wantedLen);
            // choose next version (1 or highest+1)
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

    public static function getRawKeyBytes(string $envName, ?string $keysDir = null, string $basename = '', bool $generateIfMissing = false): array
    {
        $b64 = self::getBase64Key($envName, $keysDir, $basename, $generateIfMissing);
        $raw = base64_decode($b64, true);
        if ($raw === false) throw new RuntimeException('Base64 decode failed in KeyManager');
        // find version if possible
        $ver = null;
        if ($keysDir !== null && $basename !== '') {
            $info = self::locateLatestKeyFile($keysDir, $basename);
            if ($info !== null) $ver = $info['version'];
        }
        return ['raw' => $raw, 'version' => $ver ?? 'v1'];
    }

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

    public static function memzero(string &$s): void
    {
        if (function_exists('sodium_memzero')) {
            @sodium_memzero($s);
        } else {
            $s = str_repeat("\0", strlen($s));
        }
        $s = '';
    }
}
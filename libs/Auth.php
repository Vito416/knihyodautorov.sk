<?php
declare(strict_types=1);

final class Auth
{
    private const TOKEN_BYTES = 32;

    private static function getArgon2Options(): array
    {
        return [
            'memory_cost' => (int)($_ENV['ARGON_MEMORY_KIB'] ?? (1 << 16)),
            'time_cost'   => (int)($_ENV['ARGON_TIME_COST'] ?? 4),
            'threads'     => (int)($_ENV['ARGON_THREADS'] ?? 2),
        ];
    }

    private static function loadPepper(): ?string
    {
        static $cached = null;
        if ($cached !== null) return $cached;

        try {
            if (class_exists('KeyManager')) {
                $keysDir = $_ENV['KEYS_DIR'] ?? (defined('KEYS_DIR') ? KEYS_DIR : null);
                $info = KeyManager::getRawKeyBytes('PASSWORD_PEPPER', $keysDir, 'password_pepper', false);
                if (!empty($info['raw'])) return $cached = $info['raw'];
            }

            $b64 = $_ENV['PASSWORD_PEPPER'] ?? getenv('PASSWORD_PEPPER') ?: '';
            if ($b64 !== '' && ($raw = base64_decode($b64, true)) !== false) return $cached = $raw;
        } catch (\Throwable $e) {
            Logger::error('Auth::loadPepper failed', null, ['exception' => $e]);
        }

        return $cached = null;
    }

    private static function preprocessPassword(string $password): string
    {
        $pepper = self::loadPepper();
        return $pepper !== null ? hash_hmac('sha256', $password, $pepper, true) : $password;
    }

    public static function hashPassword(string $password): string
    {
        $pwd = self::preprocessPassword($password);
        $hash = password_hash($pwd, PASSWORD_ARGON2ID, self::getArgon2Options());
        if ($hash === false) throw new \RuntimeException('password_hash failed');
        return $hash;
    }

    public static function verifyPassword(string $password, string $storedHash): bool
    {
        $pepper = self::loadPepper();
        if ($pepper !== null && password_verify(hash_hmac('sha256', $password, $pepper, true), $storedHash)) {
            return true;
        }
        return password_verify($password, $storedHash);
    }

    /**
     * Login by email + password, returns user data array on success
     * Does NOT manage session tokens; only authentication & logging
     */
    public static function login(PDO $db, string $email, string $password, int $maxFailed = 5): array
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Logger::auth('login_failure', null, ['email' => $email]);
            return [false, 'Neplatné přihlášení'];
        }

        try {
            $stmt = $db->prepare('SELECT * FROM pouzivatelia WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            $u = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            Logger::systemError($e);
            return [false, 'Chyba serveru'];
        }

        if (!$u || empty($u['is_active']) || !empty($u['is_locked'])) {
            Logger::auth('login_failure', $u['id'] ?? null, ['email' => $email]);
            usleep(150_000);
            return [false, 'Neplatné přihlášení'];
        }

        if (!self::verifyPassword($password, (string)$u['heslo_hash'])) {
            try {
                $db->prepare('UPDATE pouzivatelia SET failed_logins = failed_logins + 1 WHERE id = ?')->execute([$u['id']]);
                $failed = (int)($db->query("SELECT failed_logins FROM pouzivatelia WHERE id={$u['id']}")->fetchColumn());
                if ($failed >= $maxFailed) {
                    $db->prepare('UPDATE pouzivatelia SET is_locked = 1 WHERE id = ?')->execute([$u['id']]);
                    Logger::auth('lockout', $u['id']);
                } else {
                    Logger::auth('login_failure', $u['id']);
                }
            } catch (\Throwable $e) {
                Logger::systemError($e, $u['id'] ?? null);
            }
            return [false, 'Neplatné přihlášení'];
        }

        try {
            $db->prepare('UPDATE pouzivatelia SET failed_logins = 0, last_login_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$u['id']]);
        } catch (\Throwable $e) {
            Logger::systemError($e, $u['id']);
        }

        // Rehash if needed
        if (password_needs_rehash((string)$u['heslo_hash'], PASSWORD_ARGON2ID, self::getArgon2Options())) {
            try {
                $newHash = self::hashPassword($password);
                $db->prepare('UPDATE pouzivatelia SET heslo_hash = ? WHERE id = ?')->execute([$newHash, $u['id']]);
            } catch (\Throwable $e) {
                Logger::systemError($e, $u['id']);
            }
        }

        Logger::auth('login_success', $u['id']);
        return [$u, 'OK'];
    }

    /**
     * Check if user is admin by actor_type
     */
    public static function isAdmin(array $userData): bool
    {
        return isset($userData['actor_type']) && $userData['actor_type'] === 'admin';
    }
}
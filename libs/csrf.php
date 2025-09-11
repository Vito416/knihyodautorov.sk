<?php
class CSRF {
    private const TOKEN_TTL = 60 * 60; // 1 hour
    private const MAX_TOKENS = 16;

    private static function ensureSession(): void {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new RuntimeException('Session not active — call bootstrap first.');
        }
        if (!isset($_SESSION['csrf_tokens']) || !is_array($_SESSION['csrf_tokens'])) {
            $_SESSION['csrf_tokens'] = [];
        }
    }

    /**
     * Vrátí jednorázový token ve formátu id:value
     * - id slouží jako klíč v session poli
     * - value je náhodná hodnota porovnaná pomocí hash_equals
     */
    public static function token(): string {
        self::ensureSession();

        // cleanup expired tokens (best-effort)
        $now = time();
        foreach ($_SESSION['csrf_tokens'] as $k => $meta) {
            if (!isset($meta['exp']) || $meta['exp'] < $now) {
                unset($_SESSION['csrf_tokens'][$k]);
            }
        }

        // limit stored tokens to avoid DoS in session growth
        while (count($_SESSION['csrf_tokens']) >= self::MAX_TOKENS) {
            // remove the oldest
            uasort($_SESSION['csrf_tokens'], function($a,$b){ return ($a['exp'] <=> $b['exp']); });
            $firstKey = array_key_first($_SESSION['csrf_tokens']);
            unset($_SESSION['csrf_tokens'][$firstKey]);
        }

        $id = bin2hex(random_bytes(8)); // 16 hex chars
        $val = bin2hex(random_bytes(32)); // value to validate
        $_SESSION['csrf_tokens'][$id] = ['v' => $val, 'exp' => $now + self::TOKEN_TTL];

        return $id . ':' . $val;
    }

    /**
     * Validate and *consume* a token (one-time). Returns bool.
     */
    public static function validate($token): bool {
        self::ensureSession();

        if (!is_string($token) || strpos($token, ':') === false) return false;
        list($id, $val) = explode(':', $token, 2);
        if (!isset($_SESSION['csrf_tokens'][$id])) return false;

        $stored = $_SESSION['csrf_tokens'][$id];
        // consume immediately (one-time)
        unset($_SESSION['csrf_tokens'][$id]);

        if (!isset($stored['v'])) return false;
        if (!hash_equals($stored['v'], (string)$val)) return false;
        if (!isset($stored['exp']) || $stored['exp'] < time()) return false;
        return true;
    }
}
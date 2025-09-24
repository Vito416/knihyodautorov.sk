<?php
declare(strict_types=1);

/**
 * header_loader.php
 *
 * Kompletní header loader:
 * - SessionManager / CSRF / Crypto integration (best-effort)
 * - cache dešifrovaného profilu v $_SESSION (invalidace přes key_version)
 * - APCu cache kategorií (fallback na DB)
 * - validace cookie cart_id
 * - whitelist navActive
 * - device / bot detection
 * - utility: invalidateCategoryCache(), invalidateProfileCache(), setSessionCookieDefaults()
 *
 * Vystavuje: $user, $categories, $cart_count, $csrf_token, $device_type, $is_bot, $meta
 */

if (!defined('TEMPLATES_LOADER_INCLUDED')) {
    define('TEMPLATES_LOADER_INCLUDED', true);
}

/* ---------------------------
 * Utilities (callable z ostatních částí aplikace)
 * --------------------------- */

/**
 * Vrátí true pokud je request přes HTTPS (bere v potaz X-Forwarded-Proto).
 */
function header_is_https(): bool
{
    $proto = strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');
    if ($proto === 'https') return true;
    if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') return true;
    if (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) return true;
    return false;
}

/**
 * Nastaví doporučené session cookie parametry pokud session ještě není aktivní.
 * Volat **před** session_start() / před tím, než něco jiného spustí session.
 *
 * @param array|null $overrides  Přepíše výchozí hodnoty. Klíče: lifetime, path, domain, secure, httponly, samesite
 */
function setSessionCookieDefaults(?array $overrides = null): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        // už běží — neměníme (není bezpečné měnit cookie params po startu)
        return;
    }
    $defaults = [
        'lifetime' => 0,
        'path' => '/',
        'domain' => $_ENV['SESSION_DOMAIN'] ?? ($_SERVER['HTTP_HOST'] ?? ''),
        'secure' => header_is_https(),
        'httponly' => true,
        'samesite' => 'Lax', // doporučené: Lax (nebo Strict dle potřeby)
    ];
    if (is_array($overrides)) {
        $defaults = array_merge($defaults, $overrides);
    }
    // PHP < 7.3 má jiný signaturu
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => (int)$defaults['lifetime'],
            'path' => (string)$defaults['path'],
            'domain' => (string)$defaults['domain'],
            'secure' => (bool)$defaults['secure'],
            'httponly' => (bool)$defaults['httponly'],
            'samesite' => (string)$defaults['samesite'],
        ]);
    } else {
        session_set_cookie_params(
            (int)$defaults['lifetime'],
            (string)$defaults['path'] . '; samesite=' . (string)$defaults['samesite'],
            (string)$defaults['domain'],
            (bool)$defaults['secure'],
            (bool)$defaults['httponly']
        );
    }
}

/**
 * Invalidate cached profile(s) in session storage.
 * - If $userId is provided, remove keys matching that user.
 * - Otherwise remove all profile cache keys in session.
 *
 * Nota bene: session must be active to operate on $_SESSION.
 */
function invalidateProfileCache(?int $userId = null): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) return;
    if ($userId !== null) {
        $key = 'profile_cache_user_' . $userId . '_v';
        // remove any versions for that user (iterate keys)
        foreach (array_keys($_SESSION) as $k) {
            if (strpos($k, $key) === 0) {
                unset($_SESSION[$k]);
            }
        }
    } else {
        // remove any keys matching profile_cache_user_*
        foreach (array_keys($_SESSION) as $k) {
            if (strpos($k, 'profile_cache_user_') === 0) {
                unset($_SESSION[$k]);
            }
        }
    }
}

/* Defaults (možno nastaviť pred includom) */
$navActive = $navActive ?? 'catalog';
$pageTitle = $pageTitle ?? null;

$user = $user ?? null;
$categories = $categories ?? [];
$cart_count = $cart_count ?? 0;
$csrf_token = $csrf_token ?? null;
$device_type = $device_type ?? 'desktop';
$is_bot = $is_bot ?? false;
$meta = $meta ?? ['title' => $pageTitle ?? ''];

/* ---------------------------
 * 1) DB connection (best-effort)
 * --------------------------- */
$pdo = null;
$dbWrapper = null;
try {
    if (class_exists('Database') && method_exists('Database', 'getInstance')) {
        $dbWrapper = Database::getInstance();
        if (is_object($dbWrapper) && method_exists($dbWrapper, 'getPdo')) {
            $pdo = $dbWrapper->getPdo();
        } elseif ($dbWrapper instanceof \PDO) {
            $pdo = $dbWrapper;
        }
    }
    if (!($pdo instanceof \PDO) && isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof \PDO) {
        $pdo = $GLOBALS['pdo'];
    }
} catch (\Throwable $e) {
    if (class_exists('Logger')) { try { Logger::systemError($e); } catch (\Throwable $_) {} }
    $pdo = null;
}

/* ---------------------------
 * small helpers
 * --------------------------- */
$readBlob = function ($b) {
    if ($b === null) return null;
    if (is_resource($b)) {
        try { return stream_get_contents($b); } catch (\Throwable $_) { return null; }
    }
    if (is_string($b)) return $b;
    return null;
};

$truncateUserAgent = function (?string $ua) {
    if ($ua === null) return null;
    $ua = preg_replace('/[\x00-\x1F\x7F]+/u', '', $ua);
    $ua = preg_replace('/\s+/u', ' ', $ua);
    return mb_substr(trim($ua), 0, 512, 'UTF-8');
};

/**
 * Try to decrypt profile blob (user_profiles.profile_enc)
 * Returns assoc array with keys: given_name, family_name, display_name, avatar_url (if present)
 * or null on failure.
 */
$decryptProfileBlob = function ($blobBin) use ($readBlob) : ?array {
    $blob = $readBlob($blobBin);
    if ($blob === null || $blob === '') return null;

    // 1) Prefer Crypto::decrypt if available (handles versioned payloads)
    if (class_exists('Crypto')) {
        try {
            if (method_exists('Crypto', 'initFromKeyManager')) {
                try { Crypto::initFromKeyManager(defined('KEYS_DIR') ? KEYS_DIR : ($_ENV['KEYS_DIR'] ?? null)); } catch (\Throwable $_) {}
            }

            $plain = Crypto::decrypt($blob);
            if (is_string($plain) && $plain !== '') {
                $data = @json_decode($plain, true);
                if (is_array($data)) {
                    $payload = $data['data'] ?? $data;
                    $given = trim((string)($payload['given_name'] ?? $payload['givenName'] ?? $payload['first_name'] ?? ''));
                    $family = trim((string)($payload['family_name'] ?? $payload['familyName'] ?? $payload['last_name'] ?? ''));
                    $display = trim((string)($payload['display_name'] ?? $payload['name'] ?? ''));
                    $avatar = isset($payload['avatar_url']) ? (string)$payload['avatar_url'] : (isset($payload['avatar']) ? (string)$payload['avatar'] : '');
                    if ($display === '') {
                        $maybe = trim(($given !== '' ? $given : '') . ' ' . ($family !== '' ? $family : ''));
                        if ($maybe !== '') $display = $maybe;
                    }
                    return ['given_name'=>$given, 'family_name'=>$family, 'display_name'=>$display, 'avatar_url'=>$avatar];
                }
            }
        } catch (\Throwable $_) {
            // fallback to other attempts (or return null)
            if (class_exists('Logger')) { try { Logger::systemError($_); } catch (\Throwable $__ ) {} }
            return null;
        }
    }

    // 2) Fallback: attempt libsodium decryption matching your encryption code (nonce + ciphertext)
    // (left out because Crypto already covers most flavors; keep as future extension)
    return null;
};

/* ---------------------------
 * 2) Session validation & CSRF readiness (best-effort)
 * --------------------------- */
try {
    $userIdFromSession = null;
    $dbForSession = $dbWrapper ?? $pdo;

    if (class_exists('SessionManager') && $dbForSession !== null) {
        try {
            $userIdFromSession = SessionManager::validateSession($dbForSession);
        } catch (\Throwable $e) {
            if (class_exists('Logger')) { try { Logger::systemError($e); } catch (\Throwable $_) {} }
            $userIdFromSession = null;
        }
    } else {
        // ensure session exists if CSRF needed
        if (class_exists('CSRF') && session_status() !== PHP_SESSION_ACTIVE) {
            try { session_start(); } catch (\Throwable $_) {}
        }
    }

    // init CSRF with session reference (best-effort)
    if (class_exists('CSRF')) {
        try {
            if (session_status() !== PHP_SESSION_ACTIVE) session_start();
            $ref = &$_SESSION;
            if (method_exists('CSRF','init')) CSRF::init($ref);
        } catch (\Throwable $_) {}
    }
} catch (\Throwable $_) {}

/* ---------------------------
 * 3) CSRF token (best-effort)
 * --------------------------- */
if ($csrf_token === null && class_exists('CSRF') && method_exists('CSRF', 'token')) {
    try {
        $t = CSRF::token();
        if (is_string($t) && $t !== '') $csrf_token = $t;
    } catch (\Throwable $e) {
        if (class_exists('Logger')) { try { Logger::systemError($e); } catch (\Throwable $_) {} }
        $csrf_token = null;
    }
}

/* ---------------------------
 * 4) Load user (using SessionManager result if available) + profile decrypt + roles + purchases
 * --------------------------- */
try {
    $user = null;
    $userId = null;

    // prefer id from SessionManager restore
    if (!empty($userIdFromSession) && is_int($userIdFromSession) && $userIdFromSession > 0) {
        $userId = $userIdFromSession;
    }

    // if no SessionManager, try session user_id
    if ($userId === null && session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['user_id'])) {
        $userId = (int)$_SESSION['user_id'];
    }

    if ($userId !== null && $userId > 0 && ($pdo instanceof \PDO || ($dbWrapper !== null && method_exists($dbWrapper,'fetch')))) {
        try {
            // fetch minimal user row (pouzivatelia schema)
            $sql = 'SELECT id, actor_type, is_active, is_locked, email_enc, email_key_version FROM pouzivatelia WHERE id = :id LIMIT 1';
            if ($dbWrapper !== null && method_exists($dbWrapper, 'fetch')) {
                $row = $dbWrapper->fetch($sql, [':id' => $userId]);
            } else {
                $st = $pdo->prepare($sql);
                $st->bindValue(':id', $userId, \PDO::PARAM_INT);
                $st->execute();
                $row = $st->fetch(\PDO::FETCH_ASSOC) ?: null;
            }

            if (empty($row)) {
                $user = null;
            } else {
                $display = '';
                $given = '';
                $family = '';
                $avatar = '';

                // 1) try decrypt profile from user_profiles (latest)
                $profileInfo = null;
                try {
                    $sqlProf = 'SELECT profile_enc, key_version FROM user_profiles WHERE user_id = :id ORDER BY updated_at DESC LIMIT 1';
                    if ($dbWrapper !== null && method_exists($dbWrapper, 'fetch')) {
                        $rprof = $dbWrapper->fetch($sqlProf, [':id' => $userId]);
                    } else {
                        $st2 = $pdo->prepare($sqlProf);
                        $st2->bindValue(':id', $userId, \PDO::PARAM_INT);
                        $st2->execute();
                        $rprof = $st2->fetch(\PDO::FETCH_ASSOC) ?: null;
                    }
                    if (!empty($rprof) && isset($rprof['profile_enc']) && $rprof['profile_enc'] !== null) {
                        $profileKeyVer = (string)($rprof['key_version'] ?? '0');
                        $sessionCacheKey = 'profile_cache_user_' . $userId . '_v' . $profileKeyVer;
                        if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION[$sessionCacheKey]) && is_array($_SESSION[$sessionCacheKey])) {
                            $profileInfo = $_SESSION[$sessionCacheKey];
                        } else {
                            $profileInfo = $decryptProfileBlob($rprof['profile_enc']);
                            if (is_array($profileInfo) && session_status() === PHP_SESSION_ACTIVE) {
                                $_SESSION[$sessionCacheKey] = $profileInfo; // cache in session
                            }
                        }
                    }
                } catch (\Throwable $_) {
                    // ignore missing table/column
                }

                // 2) fallback: try decrypt email_enc from pouzivatelia (if profile missing and email_enc present)
                if ($profileInfo === null && !empty($row['email_enc'])) {
                    try {
                        if (class_exists('Crypto')) {
                            if (method_exists('Crypto', 'initFromKeyManager')) {
                                try { Crypto::initFromKeyManager(defined('KEYS_DIR') ? KEYS_DIR : ($_ENV['KEYS_DIR'] ?? null)); } catch (\Throwable $_) {}
                            }
                            $plain = Crypto::decrypt($row['email_enc']);
                            if (is_string($plain) && $plain !== '') {
                                $maybe = @json_decode($plain, true);
                                if (is_array($maybe)) {
                                    $payload = $maybe['data'] ?? $maybe;
                                    $profileInfo = [
                                        'given_name' => trim((string)($payload['given_name'] ?? $payload['givenName'] ?? '')),
                                        'family_name' => trim((string)($payload['family_name'] ?? $payload['familyName'] ?? '')),
                                        'display_name' => trim((string)($payload['display_name'] ?? $payload['name'] ?? '')),
                                        'avatar_url' => isset($payload['avatar_url']) ? (string)$payload['avatar_url'] : ''
                                    ];
                                } else {
                                    if (filter_var($plain, FILTER_VALIDATE_EMAIL)) {
                                        $profileInfo = ['given_name'=>'','family_name'=>'','display_name'=>$plain,'avatar_url'=>''];
                                    }
                                }
                            }
                        }
                    } catch (\Throwable $_) {
                        // ignore
                    }
                }

                if (is_array($profileInfo)) {
                    $given = $profileInfo['given_name'] ?? $given;
                    $family = $profileInfo['family_name'] ?? $family;
                    $display = $profileInfo['display_name'] ?? $display;
                    $avatar = $profileInfo['avatar_url'] ?? $avatar;
                }

                // 3) final fallbacks
                if ($display === '') {
                    $display = trim($given . ' ' . $family);
                }
                if ($display === '') $display = 'Používateľ';

                // 4) load roles
                $roles = [];
                $isAdmin = false;
                try {
                    $sqlRoles = 'SELECT r.id AS role_id, r.nazov AS nazov FROM user_roles ur JOIN roles r ON ur.role_id = r.id WHERE ur.user_id = :id';
                    if ($dbWrapper !== null && method_exists($dbWrapper, 'fetchAll')) {
                        $rrows = (array)$dbWrapper->fetchAll($sqlRoles, [':id' => $userId]);
                    } else {
                        $s3 = $pdo->prepare($sqlRoles);
                        $s3->bindValue(':id', $userId, \PDO::PARAM_INT);
                        $s3->execute();
                        $rrows = $s3->fetchAll(\PDO::FETCH_ASSOC) ?: [];
                    }
                    foreach ($rrows as $rr) {
                        $roles[] = ['id' => isset($rr['role_id']) ? (int)$rr['role_id'] : null, 'name' => (string)($rr['nazov'] ?? '')];
                        $n = mb_strtolower((string)($rr['nazov'] ?? ''), 'UTF-8');
                        if (in_array($n, ['majiteľ', 'majitel', 'správca', 'spravca', 'admin', 'administrator'], true)) $isAdmin = true;
                    }
                } catch (\Throwable $_) {
                    // ignore
                }

                // 5) purchases: distinct book_ids and sum(quantity) for paid/completed orders (best-effort)
                $purchasedBooks = [];
                $purchasedItemsCount = null;
                try {
                    $sqlPurchase = "SELECT DISTINCT oi.book_id FROM orders o JOIN order_items oi ON oi.order_id = o.id
                                    WHERE o.user_id = :id AND o.status IN ('paid','completed')";
                    if ($dbWrapper !== null && method_exists($dbWrapper, 'fetchAll')) {
                        $rowsPb = (array)$dbWrapper->fetchAll($sqlPurchase, [':id' => $userId]);
                    } else {
                        $s5 = $pdo->prepare($sqlPurchase);
                        $s5->bindValue(':id', $userId, \PDO::PARAM_INT);
                        $s5->execute();
                        $rowsPb = $s5->fetchAll(\PDO::FETCH_ASSOC) ?: [];
                    }
                    foreach ($rowsPb as $rb) {
                        if (isset($rb['book_id'])) $purchasedBooks[] = (int)$rb['book_id'];
                    }

                    // items count
                    $sqlItemsCount = "SELECT COALESCE(SUM(oi.quantity),0) AS cnt FROM orders o JOIN order_items oi ON oi.order_id = o.id
                                      WHERE o.user_id = :id AND o.status IN ('paid','completed')";
                    if ($dbWrapper !== null && method_exists($dbWrapper, 'fetch')) {
                        $rcnt = $dbWrapper->fetch($sqlItemsCount, [':id' => $userId]);
                    } else {
                        $s6 = $pdo->prepare($sqlItemsCount);
                        $s6->bindValue(':id', $userId, \PDO::PARAM_INT);
                        $s6->execute();
                        $rcnt = $s6->fetch(\PDO::FETCH_ASSOC) ?: null;
                    }
                    if (!empty($rcnt) && isset($rcnt['cnt'])) $purchasedItemsCount = (int)$rcnt['cnt'];
                } catch (\Throwable $_) {
                    // ignore: leave as null/empty
                }

                $user = [
                    'id' => (int)$row['id'],
                    'actor_type' => $row['actor_type'] ?? null,
                    'is_active' => isset($row['is_active']) ? (bool)$row['is_active'] : null,
                    'is_locked' => isset($row['is_locked']) ? (bool)$row['is_locked'] : null,
                    'given_name' => $given,
                    'family_name' => $family,
                    'display_name' => $display,
                    'avatar_url' => $avatar,
                    'roles' => $roles,
                    'is_admin' => $isAdmin,
                    'purchased_books' => array_values(array_unique($purchasedBooks)),
                    'purchased_items_count' => $purchasedItemsCount,
                ];
            }
        } catch (\Throwable $e) {
            if (class_exists('Logger')) { try { Logger::systemError($e, $userId); } catch (\Throwable $_) {} }
            $user = null;
        }
    }
} catch (\Throwable $_) {
    $user = $user ?? null;
}

/* 5) Load categories for nav (DB only, no APCu) */
try {
    $categories = [];
    if ($pdo instanceof \PDO || ($dbWrapper !== null && method_exists($dbWrapper, 'fetchAll'))) {
        $sql = 'SELECT id, nazov, slug, parent_id FROM categories ORDER BY nazov ASC';
        if ($dbWrapper !== null && method_exists($dbWrapper, 'fetchAll')) {
            $categories = (array)$dbWrapper->fetchAll($sql, []);
        } else {
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            $categories = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        }
    } else {
            // fallback static categories
            $categories = [
                ['id'=>1,'nazov'=>'Beletria','slug'=>'beletria','parent_id'=>null],
                ['id'=>2,'nazov'=>'Detektívky','slug'=>'detektivky','parent_id'=>null],
                ['id'=>3,'nazov'=>'Eseje & Non-fiction','slug'=>'non-fiction','parent_id'=>null],
            ];
    }
} catch (\Throwable $e) {
    if (class_exists('Logger')) { try { Logger::systemError($e); } catch (\Throwable $_) {} }
    $categories = $categories ?? [];
}

/* ---------------------------
 * 6) Cart count (best-effort)
 * --------------------------- */
try {
    $cart_count = 0;
    if (!empty($user) && isset($user['id']) && ($pdo instanceof \PDO || ($dbWrapper !== null && method_exists($dbWrapper, 'fetch')))) {
        $trySql = 'SELECT COALESCE(SUM(ci.quantity),0) AS cnt FROM carts c JOIN cart_items ci ON ci.cart_id = c.id WHERE c.user_id = :uid AND c.is_active = 1';
        if ($dbWrapper !== null && method_exists($dbWrapper, 'fetch')) {
            $r = $dbWrapper->fetch($trySql, [':uid' => $user['id']]);
            if (!empty($r) && isset($r['cnt'])) $cart_count = (int)$r['cnt'];
        } elseif ($pdo instanceof \PDO) {
            $stmt = $pdo->prepare($trySql);
            $stmt->bindValue(':uid', (int)$user['id'], \PDO::PARAM_INT);
            $stmt->execute();
            $r = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($r && isset($r['cnt'])) $cart_count = (int)$r['cnt'];
        }
    } else {
        if (isset($_COOKIE['cart_id']) && $pdo instanceof \PDO) {
            $cartIdRaw = $_COOKIE['cart_id'];
            // validate cookie: allow hex/uuid-like tokens (6-64 chars)
            if (is_string($cartIdRaw) && preg_match('/^[0-9a-fA-F\-]{6,64}$/', $cartIdRaw)) {
                $stmt = $pdo->prepare('SELECT COALESCE(SUM(quantity),0) AS cnt FROM cart_items WHERE cart_id = :cid');
                $stmt->bindValue(':cid', $cartIdRaw, \PDO::PARAM_STR);
                $stmt->execute();
                $r = $stmt->fetch(\PDO::FETCH_ASSOC);
                if ($r && isset($r['cnt'])) $cart_count = (int)$r['cnt'];
            }
        }
    }
} catch (\Throwable $_) {
    $cart_count = $cart_count ?? 0;
}

/* ---------------------------
 * 7) device / bot detection
 * --------------------------- */
try {
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $uaL = strtolower($ua);
    $is_bot = false;
    $botSignatures = ['googlebot','bingbot','slurp','duckduckgo','baiduspider','yandex','crawler','bot','spider'];
    foreach ($botSignatures as $b) {
        if (strpos($uaL, $b) !== false) { $is_bot = true; break; }
    }
    $device_type = 'desktop';
    if (preg_match('/Mobile|Android|iPhone|iPad|IEMobile|Opera Mini/i', $ua)) $device_type = 'mobile';
    if (preg_match('/iPad|Tablet|Kindle/i', $ua)) $device_type = 'tablet';
} catch (\Throwable $_) {
    $device_type = $device_type ?? 'desktop';
    $is_bot = $is_bot ?? false;
}

/* ---------------------------
 * 8) navActive whitelist
 * --------------------------- */
$allowedNav = ['catalog','home','profile','cart','about','contact'];
$navActive = (is_string($navActive) && in_array($navActive, $allowedNav, true)) ? $navActive : 'catalog';

/* ---------------------------
 * 9) Final surface (type casts, safe defaults)
 * --------------------------- */
$categories = is_array($categories) ? array_values($categories) : [];
$cart_count = (int)($cart_count ?? 0);
$user = is_array($user) ? $user : null;
$device_type = is_string($device_type) ? $device_type : 'desktop';
$is_bot = (bool)($is_bot ?? false);

/* No output; include returns to caller */
return;
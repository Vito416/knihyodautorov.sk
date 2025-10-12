<?php

declare(strict_types=1);

use BlackCat\Core\Security\Crypto;
use BlackCat\Core\Security\CSRF;
use BlackCat\Core\Log\Logger;
use BlackCat\Core\TrustedShared;
use BlackCat\Core\Session\SessionManager;

if (defined('HEADER_LOADER_INCLUDED')) {
    return;
}
define('HEADER_LOADER_INCLUDED', true);
/**
 * inc/loaders/header_loader.php
 *
 * Funkční refaktor původního header_loader.php — exportuje funkci load_header_data()
 * která vrací asociativní pole s klíči:
 *   user, categories, cart_count, csrf_token, device_type, is_bot, meta
 *
 * Volání (v templates/partials/header.php):
 *   require_once __DIR__ . '/inc/loaders/header_loader.php';
 *   $hdr = load_header_data($db ?? ($GLOBALS['pdo'] ?? null), $currentUserId ?? null);
 *   $user = $hdr['user']; $categories = $hdr['categories']; // ...
 */

function load_header_data($db = null, ?int $currentUserId = null, array $opts = []): array
{
    $out = [
        'user' => null,
        'categories' => [],
        'cart_count' => 0,
        'csrf_token' => null,
        'device_type' => 'desktop',
        'is_bot' => false,
        'meta' => ['title' => $opts['pageTitle'] ?? ''],
    ];

    // determine PDO / DB wrapper
    $pdo = null;
    $dbWrapper = null;

    try {
        // 1) explicit $db parameter (preferred)
        if (is_object($db)) {
            if (method_exists($db, 'getPdo')) {
                $dbWrapper = $db;
                $pdo = $db->getPdo();
            } elseif ($db instanceof \PDO) {
                $pdo = $db;
            } else {
                // wrapper-like object (with fetch/fetchAll)
                $dbWrapper = $db;
            }
        }

        // 2) trustedShared passed via opts (recommended): opts['trustedShared']['db']
        if ($pdo === null && isset($opts['trustedShared']) && is_array($opts['trustedShared'])) {
            $tsDb = $opts['trustedShared']['db'] ?? null;
            if (is_object($tsDb)) {
                if (method_exists($tsDb, 'getPdo')) {
                    $dbWrapper = $tsDb;
                    $pdo = $tsDb->getPdo();
                } elseif ($tsDb instanceof \PDO) {
                    $pdo = $tsDb;
                } else {
                    $dbWrapper = $tsDb;
                }
            }
        }

        // 3) fallback: try TrustedShared::create() to obtain db (best-effort, no exceptions)
        if ($pdo === null && class_exists(TrustedShared::class, true)) {
            try {
                $ts = TrustedShared::create(); // safe, best-effort
                if (!empty($ts['db']) && is_object($ts['db'])) {
                    $tsDb = $ts['db'];
                    if (method_exists($tsDb, 'getPdo')) {
                        $dbWrapper = $tsDb;
                        $pdo = $tsDb->getPdo();
                    } elseif ($tsDb instanceof \PDO) {
                        $pdo = $tsDb;
                    } else {
                        $dbWrapper = $tsDb;
                    }
                }
            } catch (\Throwable $_) {
                // ignore — TrustedShared::create should not throw, but be defensive
            }
        }

        // 4) last-resort legacy fallback to $GLOBALS['pdo'] (kept for backward compat)
        if (!($pdo instanceof \PDO) && isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof \PDO) {
            // log once so we can find callers that rely on globals
            if (class_exists(Logger::class, true)) {
                try { Logger::systemMessage('warning', 'Using legacy $GLOBALS[\'pdo\'] fallback in header_loader'); } catch (\Throwable $_) {}
            }
            $pdo = $GLOBALS['pdo'];
        }
    } catch (\Throwable $e) {
        if (class_exists(Logger::class, true)) { try { Logger::systemError($e); } catch (\Throwable $_) {} }
    }

    // helper: read blob
    $readBlob = function ($b) {
        if ($b === null) return null;
        if (is_resource($b)) {
            try { return stream_get_contents($b); } catch (\Throwable $_) { return null; }
        }
        if (is_string($b)) return $b;
        return null;
    };

    // decrypt helper (best-effort using Crypto if available)
    $decryptProfileBlob = function ($blobBin) use ($readBlob) : ?array {
        $blob = $readBlob($blobBin);
        if ($blob === null || $blob === '') return null;
        if (class_exists(Crypto::class, true)) {
            try {
                if (method_exists(Crypto::class, 'initFromKeyManager')) {
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
                if (class_exists(Logger::class, true)) { try { Logger::systemError($_); } catch (\Throwable $__ ) {} }
                return null;
            }
        }
        return null;
    };

    // ------------------
    // CSRF + session (best-effort)
    // ------------------
    try {
        // ensure session exists for CSRF and caching
        if (session_status() !== PHP_SESSION_ACTIVE) {
            // don't throw if headers already sent — best-effort
            try { session_start(); } catch (\Throwable $_) {}
        }
        if (class_exists(CSRF::class, true)) {
            try {
                if (method_exists(CSRF::class,'init')) CSRF::init($_SESSION);
                if (method_exists(CSRF::class,'token')) {
                    $t = CSRF::token();
                    if (is_string($t) && $t !== '') $out['csrf_token'] = $t;
                }
            } catch (\Throwable $_) { /* ignore */ }
        }
    } catch (\Throwable $_) {}

    // ------------------
    // SessionManager: try to obtain user id (prefer passed $currentUserId)
    // ------------------
    $userIdFromSession = null;
    if (!empty($currentUserId) && is_int($currentUserId) && $currentUserId > 0) {
        $userIdFromSession = $currentUserId;
    } else {
        try {
            $dbForSession = $dbWrapper ?? $pdo;
            if (class_exists(SessionManager::class, true) && $dbForSession !== null) {
                try {
                    $userIdFromSession = SessionManager::validateSession($dbForSession);
                } catch (\Throwable $e) {
                    if (class_exists(Logger::class, true)) { try { Logger::systemError($e); } catch (\Throwable $_) {} }
                    $userIdFromSession = null;
                }
            }
        } catch (\Throwable $_) { /* ignore */ }
    }

    // ------------------
    // Load user profile if userId available
    // ------------------
    if (!empty($userIdFromSession) && ($pdo instanceof \PDO || ($dbWrapper !== null && method_exists($dbWrapper,'fetch')))) {
        try {
            $sql = 'SELECT id, actor_type, is_active, is_locked, email_enc, email_key_version FROM pouzivatelia WHERE id = :id LIMIT 1';
            if ($dbWrapper !== null && method_exists($dbWrapper, 'fetch')) {
                $row = $dbWrapper->fetch($sql, [':id' => $userIdFromSession]);
            } else {
                $st = $pdo->prepare($sql);
                $st->bindValue(':id', $userIdFromSession, \PDO::PARAM_INT);
                $st->execute();
                $row = $st->fetch(\PDO::FETCH_ASSOC) ?: null;
            }
            if (!empty($row)) {
                $display = '';
                $given = '';
                $family = '';
                $avatar = '';

                // try user_profiles
                try {
                    $sqlProf = 'SELECT profile_enc, key_version FROM user_profiles WHERE user_id = :id ORDER BY updated_at DESC LIMIT 1';
                    if ($dbWrapper !== null && method_exists($dbWrapper, 'fetch')) {
                        $rprof = $dbWrapper->fetch($sqlProf, [':id' => $userIdFromSession]);
                    } else {
                        $st2 = $pdo->prepare($sqlProf);
                        $st2->bindValue(':id', $userIdFromSession, \PDO::PARAM_INT);
                        $st2->execute();
                        $rprof = $st2->fetch(\PDO::FETCH_ASSOC) ?: null;
                    }
                    if (!empty($rprof) && isset($rprof['profile_enc']) && $rprof['profile_enc'] !== null) {
                        $profileInfo = null;
                        // check session cache
                        $profileKeyVer = (string)($rprof['key_version'] ?? '0');
                        $sessionCacheKey = 'profile_cache_user_' . $userIdFromSession . '_v' . $profileKeyVer;
                        if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION[$sessionCacheKey]) && is_array($_SESSION[$sessionCacheKey])) {
                            $profileInfo = $_SESSION[$sessionCacheKey];
                        } else {
                            $profileInfo = $decryptProfileBlob($rprof['profile_enc']);
                            if (is_array($profileInfo) && session_status() === PHP_SESSION_ACTIVE) {
                                $_SESSION[$sessionCacheKey] = $profileInfo;
                            }
                        }
                        if (is_array($profileInfo)) {
                            $given = $profileInfo['given_name'] ?? '';
                            $family = $profileInfo['family_name'] ?? '';
                            $display = $profileInfo['display_name'] ?? '';
                            $avatar = $profileInfo['avatar_url'] ?? '';
                        }
                    }
                } catch (\Throwable $_) { /* ignore */ }

                // fallback decrypt email_enc
                if ($display === '' && !empty($row['email_enc']) && class_exists(Crypto::class, true)) {
                    try {
                        if (method_exists(Crypto::class, 'initFromKeyManager')) {
                            try { Crypto::initFromKeyManager(defined('KEYS_DIR') ? KEYS_DIR : ($_ENV['KEYS_DIR'] ?? null)); } catch (\Throwable $_) {}
                        }
                        $plain = Crypto::decrypt($row['email_enc']);
                        if (is_string($plain) && $plain !== '') {
                            $maybe = @json_decode($plain, true);
                            if (is_array($maybe)) {
                                $payload = $maybe['data'] ?? $maybe;
                                $given = trim((string)($payload['given_name'] ?? ''));
                                $family = trim((string)($payload['family_name'] ?? ''));
                                $display = trim((string)($payload['display_name'] ?? ($payload['name'] ?? '')));
                                $avatar = isset($payload['avatar_url']) ? (string)$payload['avatar_url'] : '';
                            } else {
                                if (filter_var($plain, FILTER_VALIDATE_EMAIL)) {
                                    $display = $plain;
                                }
                            }
                        }
                    } catch (\Throwable $_) { /* ignore */ }
                }

                if ($display === '') {
                    $display = trim($given . ' ' . $family);
                }
                if ($display === '') $display = 'Používateľ';

                $out['user'] = [
                    'id' => (int)$row['id'],
                    'actor_type' => $row['actor_type'] ?? null,
                    'is_active' => isset($row['is_active']) ? (bool)$row['is_active'] : null,
                    'is_locked' => isset($row['is_locked']) ? (bool)$row['is_locked'] : null,
                    'given_name' => $given,
                    'family_name' => $family,
                    'display_name' => $display,
                    'avatar_url' => $avatar,
                ];
            }
        } catch (\Throwable $e) {
            if (class_exists(Logger::class, true)) { try { Logger::systemError($e, $userIdFromSession); } catch (\Throwable $_) {} }
        }
    }

    // ------------------
    // categories (DB or fallback)
    // ------------------
    try {
        if ($dbWrapper !== null && method_exists($dbWrapper,'fetchAll')) {
            $cats = (array)$dbWrapper->fetchAll('SELECT id, nazov, slug, parent_id FROM categories ORDER BY nazov ASC', []);
            $out['categories'] = $cats;
        } elseif ($pdo instanceof \PDO) {
            $stmt = $pdo->prepare('SELECT id, nazov, slug, parent_id FROM categories ORDER BY nazov ASC');
            $stmt->execute();
            $out['categories'] = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } else {
            $out['categories'] = [
                ['id'=>1,'nazov'=>'Beletria','slug'=>'beletria','parent_id'=>null],
                ['id'=>2,'nazov'=>'Detektívky','slug'=>'detektivky','parent_id'=>null],
            ];
        }
    } catch (\Throwable $e) {
        if (class_exists(Logger::class, true)) { try { Logger::systemError($e); } catch (\Throwable $_) {} }
        $out['categories'] = $out['categories'] ?? [];
    }

    // ------------------
    // cart_count (best-effort)
    // ------------------
    try {
        $out['cart_count'] = 0;
        if (!empty($out['user']) && isset($out['user']['id']) && ($pdo instanceof \PDO || ($dbWrapper !== null && method_exists($dbWrapper,'fetch')))) {
            $uid = (int)$out['user']['id'];
            $sql = 'SELECT COALESCE(SUM(ci.quantity),0) AS cnt FROM carts c JOIN cart_items ci ON ci.cart_id = c.id WHERE c.user_id = :uid AND c.is_active = 1';
            if ($dbWrapper !== null && method_exists($dbWrapper,'fetch')) {
                $r = $dbWrapper->fetch($sql, [':uid' => $uid]);
                if (!empty($r) && isset($r['cnt'])) $out['cart_count'] = (int)$r['cnt'];
            } else {
                $stmt = $pdo->prepare($sql);
                $stmt->bindValue(':uid', $uid, \PDO::PARAM_INT);
                $stmt->execute();
                $r = $stmt->fetch(\PDO::FETCH_ASSOC);
                if ($r && isset($r['cnt'])) $out['cart_count'] = (int)$r['cnt'];
            }
        } else {
            if (isset($_COOKIE['cart_id']) && $pdo instanceof \PDO) {
                $cartIdRaw = $_COOKIE['cart_id'];
                if (is_string($cartIdRaw) && preg_match('/^[0-9a-fA-F\-]{6,64}$/', $cartIdRaw)) {
                    $stmt = $pdo->prepare('SELECT COALESCE(SUM(quantity),0) AS cnt FROM cart_items WHERE cart_id = :cid');
                    $stmt->bindValue(':cid', $cartIdRaw, \PDO::PARAM_STR);
                    $stmt->execute();
                    $r = $stmt->fetch(\PDO::FETCH_ASSOC);
                    if ($r && isset($r['cnt'])) $out['cart_count'] = (int)$r['cnt'];
                }
            }
        }
    } catch (\Throwable $_) {
        $out['cart_count'] = $out['cart_count'] ?? 0;
    }

    // ------------------
    // device / bot detection
    // ------------------
    try {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $uaL = strtolower($ua);
        $is_bot = false;
        $botSignatures = ['googlebot','bingbot','slurp','duckduckgo','baiduspider','yandex','crawler','bot','spider'];
        foreach ($botSignatures as $b) { if (strpos($uaL, $b) !== false) { $is_bot = true; break; } }
        $device_type = 'desktop';
        if (preg_match('/Mobile|Android|iPhone|iPad|IEMobile|Opera Mini/i', $ua)) $device_type = 'mobile';
        if (preg_match('/iPad|Tablet|Kindle/i', $ua)) $device_type = 'tablet';
        $out['device_type'] = $device_type;
        $out['is_bot'] = (bool)$is_bot;
    } catch (\Throwable $_) {
        $out['device_type'] = $out['device_type'] ?? 'desktop';
        $out['is_bot'] = $out['is_bot'] ?? false;
    }

    return $out;
}
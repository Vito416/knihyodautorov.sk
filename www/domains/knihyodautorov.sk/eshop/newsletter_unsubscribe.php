<?php
declare(strict_types=1);
// zvážit nullování i enc, aby se už nedal znovu dešifrovat email, ale prover jak dlouho uchovavat GDPR...
/**
 * newsletter_unsubscribe.php
 *
 * One-click unsubscribe handler intended to be included by frontcontroller.
 * Returns: ['template'=>'pages/newsletter_unsubscribe.php','vars'=>[...], 'status'=>int?]
 *
 * Expects selected shared vars injected by frontcontroller (via TrustedShared::prepareForHandler),
 * e.g. $pdo or $db/$database (PDO or wrapper), $KEYS_DIR or constant KEYS_DIR, $KeyManager (FQCN|string or object),
 * optional $Logger (FQCN|string or object).
 */

// response builder (same shape as confirm)
$makeResp = function(array $vars = [], int $status = 200, ?string $template = 'pages/newsletter_unsubscribe.php') {
    $ret = ['template' => $template, 'vars' => $vars];
    if ($status !== 200) $ret['status'] = $status;
    return $ret;
};

/** call method whether $target is class name or object; returns null on failure */
$call = function($target, string $method, array $args = []) {
    try {
        if (is_string($target) && class_exists($target) && method_exists($target, $method)) {
            return forward_static_call_array([$target, $method], $args);
        }
        if (is_object($target) && method_exists($target, $method)) {
            return call_user_func_array([$target, $method], $args);
        }
    } catch (\Throwable $_) {}
    return null;
};

/** resolve injected target or fallback to global class if available */
$resolveTarget = function($injected, string $fallbackClass) {
    if (!empty($injected)) {
        if (is_string($injected) && class_exists($injected)) return $injected;
        if (is_object($injected)) return $injected;
    }
    if (class_exists($fallbackClass)) return $fallbackClass;
    return null;
};

/** safe logger invoker: accepts class-name (static) or object with methods */
$loggerInvoke = function(?string $method, string $msg, $userId = null, array $ctx = []) use (&$Logger, $call, $resolveTarget) {
    if (empty($Logger)) return;
    try {
        $target = $resolveTarget($Logger, \BlackCat\Core\Log\Logger::class ?? 'BlackCat\Core\Log\Logger');
        if ($target === null) return;
        if ($method === 'systemMessage') {
            if (is_string($target)) {
                if (method_exists($target, 'systemMessage')) {
                    return $call($target, 'systemMessage', [$ctx['level'] ?? 'notice', $msg, $userId, $ctx]);
                }
            } else {
                if (method_exists($target, 'systemMessage')) {
                    return $target->systemMessage($ctx['level'] ?? 'notice', $msg, $userId, $ctx);
                }
            }
            return;
        }
        if (is_string($target) && method_exists($target, $method)) {
            return $call($target, $method, [$msg, $userId, $ctx]);
        }
        if (is_object($target) && method_exists($target, $method)) {
            return $target->{$method}($msg, $userId, $ctx);
        }
    } catch (\Throwable $_) {}
    return null;
};

/** safe memzero helper */
$safeMemzero = function(&$buf) use (&$KeyManager, $call) : void {
    try {
        if ($buf === null) return;
        if (!empty($KeyManager)) {
            $km = $KeyManager;
            if (is_string($km) && class_exists($km) && method_exists($km, 'memzero')) {
                $call($km, 'memzero', [$buf]);
                $buf = null;
                return;
            }
            if (is_object($km) && method_exists($km, 'memzero')) {
                $km->memzero($buf);
                $buf = null;
                return;
            }
        }
        if (function_exists('sodium_memzero')) {
            @sodium_memzero($buf);
            $buf = null;
            return;
        }
        if (is_string($buf)) {
            $len = strlen($buf);
            $buf = str_repeat("\0", $len);
            $buf = null;
        }
    } catch (\Throwable $_) {
        // intentionally silent
        $buf = null;
    }
};

// normalize injected KEYS_DIR / KeyManager
$keysDir = $KEYS_DIR ?? (defined('KEYS_DIR') ? KEYS_DIR : null);
$KeyManager = $KeyManager ?? (\BlackCat\Core\Security\KeyManager::class ?? null);

// validate preconditions (return responses — frontcontroller will render)
if (!($keysDir && is_string($keysDir))) {
    $loggerInvoke('error', 'newsletter_unsubscribe: KEYS_DIR missing', null, []);
    return $makeResp(['error' => 'Interná chyba: kľúče nie sú nakonfigurované.'], 500);
}
if (!($KeyManager && (is_string($KeyManager) ? class_exists($KeyManager) : is_object($KeyManager)))) {
    $loggerInvoke('error', 'newsletter_unsubscribe: KeyManager missing', null, []);
    return $makeResp(['error' => 'Interná chyba: KeyManager nie je dostupný.'], 500);
}

// resolve PDO from injected shared variables ($db, $database, or TrustedShared-provided 'db' / 'Database')
$pdo = null;
try {
    if (isset($db) && $db !== null) {
        if ($db instanceof \PDO) $pdo = $db;
        elseif (is_object($db) && method_exists($db, 'getPdo')) {
            $maybe = $db->getPdo();
            if ($maybe instanceof \PDO) $pdo = $maybe;
        }
    }
    if ($pdo === null && isset($database) && $database !== null) {
        if ($database instanceof \PDO) $pdo = $database;
        elseif (is_object($database) && method_exists($database, 'getPdo')) {
            $maybe = $database->getPdo();
            if ($maybe instanceof \PDO) $pdo = $maybe;
        }
    }
    if ($pdo === null && class_exists(\BlackCat\Core\Database::class, true) && method_exists(\BlackCat\Core\Database::class, 'getInstance')) {
        $dbInst = \BlackCat\Core\Database::getInstance();
        if ($dbInst instanceof \PDO) $pdo = $dbInst;
        elseif (is_object($dbInst) && method_exists($dbInst, 'getPdo')) {
            $maybe = $dbInst->getPdo();
            if ($maybe instanceof \PDO) $pdo = $maybe;
        }
    }
    if (!($pdo instanceof \PDO)) {
        $loggerInvoke('error', 'newsletter_unsubscribe: PDO not available', null, []);
        return $makeResp(['error' => 'Interná chyba (DB).'], 500);
    }
} catch (\Throwable $e) {
    $loggerInvoke('error', 'newsletter_unsubscribe: DB getPdo failed', null, ['ex' => (string)$e]);
    return $makeResp(['error' => 'Interná chyba (DB).'], 500);
}

// read token param from GET (already normalized by frontcontroller's routing but be defensive)
$tokenHex = isset($_GET['token']) ? trim((string)$_GET['token']) : '';
if ($tokenHex === '' || !preg_match('/^[0-9a-fA-F]{64}$/', $tokenHex)) {
    return $makeResp(['error' => 'Neplatný odhlašovací token.'], 400);
}
$tokenBin = @hex2bin($tokenHex);
if ($tokenBin === false) {
    return $makeResp(['error' => 'Neplatný token.'], 400);
}

// derive candidate hashes (rotation-aware). CLEAR PATH: prefer deriveHmacCandidates/withLatest,
// fallback only to explicit getUnsubscribeKeyInfo (no generic pepper fallback).
$candidateHashes = [];
try {
    if (is_string($KeyManager) && method_exists($KeyManager, 'deriveHmacCandidates')) {
        $candidateHashes = $KeyManager::deriveHmacCandidates('UNSUBSCRIBE_KEY', $keysDir, 'unsubscribe_key', $tokenBin);
    } elseif (is_string($KeyManager) && method_exists($KeyManager, 'deriveHmacWithLatest')) {
        $v = $KeyManager::deriveHmacWithLatest('UNSUBSCRIBE_KEY', $keysDir, 'unsubscribe_key', $tokenBin);
        if (!empty($v['hash'])) $candidateHashes[] = $v;
    } elseif (is_object($KeyManager) && method_exists($KeyManager, 'deriveHmacCandidates')) {
        $candidateHashes = $KeyManager->deriveHmacCandidates('UNSUBSCRIBE_KEY', $keysDir, 'unsubscribe_key', $tokenBin);
    } elseif (is_object($KeyManager) && method_exists($KeyManager, 'deriveHmacWithLatest')) {
        $v = $KeyManager->deriveHmacWithLatest('UNSUBSCRIBE_KEY', $keysDir, 'unsubscribe_key', $tokenBin);
        if (!empty($v['hash'])) $candidateHashes[] = $v;
    }
} catch (\Throwable $e) {
    $loggerInvoke('error', 'deriveHmacCandidates failed on unsubscribe', null, ['ex' => (string)$e]);
}

// explicit fallback: only if KeyManager exposes unsubscribe key info
if (empty($candidateHashes)) {
    try {
        if (is_string($KeyManager) && method_exists($KeyManager, 'getUnsubscribeKeyInfo')) {
            $uv = $KeyManager::getUnsubscribeKeyInfo($keysDir);
            if (!empty($uv['raw']) && is_string($uv['raw'])) {
                $h = hash_hmac('sha256', $tokenBin, $uv['raw'], true);
                $candidateHashes[] = ['hash' => $h, 'version' => $uv['version'] ?? null];
                if (method_exists($KeyManager, 'memzero')) {
                    try { $KeyManager::memzero($uv['raw']); } catch (\Throwable $_) {}
                }
            }
        } elseif (is_object($KeyManager) && method_exists($KeyManager, 'getUnsubscribeKeyInfo')) {
            $uv = $KeyManager->getUnsubscribeKeyInfo($keysDir);
            if (!empty($uv['raw']) && is_string($uv['raw'])) {
                $h = hash_hmac('sha256', $tokenBin, $uv['raw'], true);
                $candidateHashes[] = ['hash' => $h, 'version' => $uv['version'] ?? null];
                if (method_exists($KeyManager, 'memzero')) {
                    try { $KeyManager->memzero($uv['raw']); } catch (\Throwable $_) {}
                }
            }
        }
    } catch (\Throwable $e) {
        $loggerInvoke('error', 'getUnsubscribeKeyInfo failed on unsubscribe', null, ['ex' => (string)$e]);
    }
}

// If still empty => configuration problem (do not accept sha256 pepper fallback)
if (empty($candidateHashes)) {
    $safeMemzero($tokenBin);
    $loggerInvoke('error', 'No unsubscribe key available (deriveHmacCandidates/withLatest/getUnsubscribeKeyInfo missing)', null, []);
    return $makeResp(['error' => 'Interná chyba: kľúč pre odhlásenie nie je dostupný.'], 500);
}

// gather unique binary hashes
$hashes = [];
$uniq = [];
foreach ($candidateHashes as $c) {
    if (!isset($c['hash'])) continue;
    $hex = bin2hex($c['hash']);
    if (isset($uniq[$hex])) continue;
    $uniq[$hex] = $c['hash'];
    $hashes[] = $c['hash'];
}

// nothing to compare?
if (empty($hashes)) {
    $safeMemzero($tokenBin);
    foreach ($candidateHashes as $c) { if (isset($c['hash'])) { $safeMemzero($c['hash']); } }
    return $makeResp(['error' => 'Token neplatný alebo už ste odhlásení.'], 400);
}

// perform transactional lookup + update with FOR UPDATE to avoid races
try {
    $placeholders = implode(',', array_fill(0, count($hashes), '?'));
    $selectSql = 'SELECT * FROM newsletter_subscribers WHERE unsubscribe_token_hash IN (' . $placeholders . ') LIMIT 1 FOR UPDATE';

    $pdo->beginTransaction();

    $stmt = $pdo->prepare($selectSql);
    $i = 1;
    foreach ($hashes as $h) {
        $stmt->bindValue($i++, $h, \PDO::PARAM_LOB);
    }
    $stmt->execute();
    $foundRow = $stmt->fetch(\PDO::FETCH_ASSOC);

    if (empty($foundRow)) {
        // commit empty (no change) and return friendly message
        $pdo->commit();
        $safeMemzero($tokenBin);
        foreach ($candidateHashes as $c) { if (isset($c['hash'])) { $safeMemzero($c['hash']); } }
        return $makeResp(['error' => 'Token neplatný alebo už ste odhlásení.'], 404);
    }

    // mark unsubscribed
    $upd = $pdo->prepare("UPDATE newsletter_subscribers
        SET unsubscribe_token_hash = null,
            unsubscribe_token_key_version = null,
            unsubscribed_at = UTC_TIMESTAMP(6),
            updated_at = UTC_TIMESTAMP(6)
        WHERE id = :id");
    $upd->bindValue(':id', (int)$foundRow['id'], \PDO::PARAM_INT);
    $upd->execute();

    $pdo->commit();

    // log
    $loggerInvoke('systemMessage', 'Newsletter unsubscribe', $foundRow['user_id'] ?? null, ['subscriber_id' => (int)$foundRow['id']]);

    // cleanup sensitive buffers
    $safeMemzero($tokenBin);
    foreach ($candidateHashes as $c) { if (isset($c['hash'])) { $safeMemzero($c['hash']); } }

    return $makeResp(['status' => 'Unsubscribed', 'subscriber_id' => (int)$foundRow['id']], 200);

} catch (\Throwable $e) {
    try { if ($pdo->inTransaction()) $pdo->rollBack(); } catch (\Throwable $_) {}
    $loggerInvoke('error', 'newsletter_unsubscribe exception', null, ['ex' => (string)$e]);
    // cleanup sensitive
    $safeMemzero($tokenBin);
    foreach ($candidateHashes as $c) { if (isset($c['hash'])) { $safeMemzero($c['hash']); } }
    return $makeResp(['error' => 'Serverová chyba pri odhlasovaní.'], 500);
}
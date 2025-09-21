<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';

/**
 * newsletter_unsubscribe.php
 * One-click unsubscribe (production-ready, efficient).
 *
 * GET param: token (hex, 64 chars)
 */

$required = ['KeyManager', 'Logger'];
$missing = [];
foreach ($required as $c) {
    if (!class_exists($c)) $missing[] = $c;
}
if (!empty($missing)) {
    $msg = 'Interná chyba: chýbajú knižnice: ' . implode(', ', $missing) . '.';
    if (class_exists('Logger')) { try { Logger::systemError(new \RuntimeException($msg)); } catch (\Throwable $_) {} }
    http_response_code(500);
    echo Templates::render('pages/error.php', ['message' => $msg]);
    exit;
}

if (!defined('KEYS_DIR') || !is_string(KEYS_DIR) || KEYS_DIR === '') {
    $msg = 'Interná chyba: KEYS_DIR nie je nastavený.';
    try { Logger::systemError(new \RuntimeException($msg)); } catch (\Throwable $_) {}
    http_response_code(500);
    echo Templates::render('pages/error.php', ['message' => $msg]);
    exit;
}

$tokenHex = isset($_GET['token']) ? trim((string)$_GET['token']) : '';
if ($tokenHex === '' || !preg_match('/^[0-9a-fA-F]{64}$/', $tokenHex)) {
    echo Templates::render('pages/newsletter_unsubscribe.php', ['error' => 'Neplatný odhlašovací token.']);
    exit;
}

$tokenBin = @hex2bin($tokenHex);
if ($tokenBin === false) {
    echo Templates::render('pages/newsletter_unsubscribe.php', ['error' => 'Neplatný token.']);
    exit;
}

// get PDO
try {
    $pdo = null;
    if (class_exists('Database') && method_exists('Database', 'getInstance')) {
        $dbInst = Database::getInstance();
        if ($dbInst instanceof \PDO) {
            $pdo = $dbInst;
        } elseif (is_object($dbInst) && method_exists($dbInst, 'getPdo')) {
            $pdo = $dbInst->getPdo();
        }
    }
    if ($pdo === null && isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof \PDO) {
        $pdo = $GLOBALS['pdo'];
    }
    if (!($pdo instanceof \PDO)) {
        throw new \RuntimeException('Databázové pripojenie nie je dostupné vo forme PDO.');
    }
} catch (\Throwable $e) {
    try { Logger::systemError($e); } catch (\Throwable $_) {}
    http_response_code(500);
    echo Templates::render('pages/error.php', ['message' => 'Interná chyba (DB).']);
    exit;
}

// build candidate hashes for token (support key rotation)
$candidateHashes = [];
try {
    if (method_exists('KeyManager', 'deriveHmacCandidates')) {
        $candidateHashes = KeyManager::deriveHmacCandidates('UNSUBSCRIBE_KEY', KEYS_DIR, 'unsubscribe_key', $tokenBin);
    } elseif (method_exists('KeyManager', 'deriveHmacWithLatest')) {
        $v = KeyManager::deriveHmacWithLatest('UNSUBSCRIBE_KEY', KEYS_DIR, 'unsubscribe_key', $tokenBin);
        if (!empty($v['hash'])) $candidateHashes[] = $v;
    }
} catch (\Throwable $e) {
    try { Logger::error('deriveHmacCandidates failed on unsubscribe', null, ['exception' => (string)$e]); } catch (\Throwable $_) {}
}

// fallback raw key / pepper / sha256
if (empty($candidateHashes) && method_exists('KeyManager', 'getUnsubscribeKeyInfo')) {
    try {
        $uv = KeyManager::getUnsubscribeKeyInfo(KEYS_DIR);
        if (!empty($uv['raw']) && is_string($uv['raw'])) {
            $h = hash_hmac('sha256', $tokenBin, $uv['raw'], true);
            $candidateHashes[] = ['hash' => $h, 'version' => $uv['version'] ?? null];
            try { KeyManager::memzero($uv['raw']); } catch (\Throwable $_) {}
        }
    } catch (\Throwable $_) {}
}
if (empty($candidateHashes) && method_exists('KeyManager', 'getPasswordPepperInfo')) {
    try {
        $pinfo = KeyManager::getPasswordPepperInfo(KEYS_DIR);
        if (!empty($pinfo['raw']) && is_string($pinfo['raw'])) {
            $h = hash_hmac('sha256', $tokenBin, $pinfo['raw'], true);
            $candidateHashes[] = ['hash' => $h, 'version' => $pinfo['version'] ?? null];
            try { KeyManager::memzero($pinfo['raw']); } catch (\Throwable $_) {}
        }
    } catch (\Throwable $_) {}
}
if (empty($candidateHashes)) {
    $candidateHashes[] = ['hash' => hash('sha256', $tokenBin, true), 'version' => null];
    try { Logger::warn('Unsubscribe: using sha256 fallback for token hash comparison'); } catch (\Throwable $_) {}
}

// if no candidates, token invalid
$hashes = [];
foreach ($candidateHashes as $c) {
    if (isset($c['hash'])) $hashes[] = $c['hash'];
}
if (empty($hashes)) {
    // memzero token before exit
    try { KeyManager::memzero($tokenBin); } catch (\Throwable $_) {}
    echo Templates::render('pages/newsletter_unsubscribe.php', ['error' => 'Token neplatný alebo už ste odhlásení.']);
    exit;
}

// dedupe by hex to avoid duplicate placeholders
$uniq = [];
foreach ($hashes as $h) {
    $k = bin2hex($h);
    if (!isset($uniq[$k])) $uniq[$k] = $h;
}
$hashes = array_values($uniq);

// IN(...) query
try {
    $placeholders = implode(',', array_fill(0, count($hashes), '?'));
    $sql = 'SELECT * FROM newsletter_subscribers WHERE unsubscribe_token_hash IN (' . $placeholders . ') LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $i = 1;
    foreach ($hashes as $h) {
        $stmt->bindValue($i++, $h, \PDO::PARAM_LOB);
    }
    $stmt->execute();
    $foundRow = $stmt->fetch(\PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    try { Logger::error('Error querying newsletter_subscribers (IN) for unsubscribe', null, ['exception' => (string)$e]); } catch (\Throwable $_) {}
    // memzero sensitive
    try { KeyManager::memzero($tokenBin); } catch (\Throwable $_) {}
    if (!empty($candidateHashes)) {
        foreach ($candidateHashes as $c) { try { if (isset($c['hash'])) KeyManager::memzero($c['hash']); } catch (\Throwable $_) {} }
    }
    http_response_code(500);
    echo Templates::render('pages/error.php', ['message' => 'Interná chyba.']);
    exit;
}

if (empty($foundRow)) {
    // memzero sensitive
    try { KeyManager::memzero($tokenBin); } catch (\Throwable $_) {}
    if (!empty($candidateHashes)) {
        foreach ($candidateHashes as $c) { try { if (isset($c['hash'])) KeyManager::memzero($c['hash']); } catch (\Throwable $_) {} }
    }
    echo Templates::render('pages/newsletter_unsubscribe.php', ['error' => 'Token neplatný alebo už ste odhlásení.']);
    exit;
}

// mark unsubscribed
try {
    $pdo->beginTransaction();
    $upd = $pdo->prepare("UPDATE newsletter_subscribers
        SET unsubscribed_at = UTC_TIMESTAMP(6),
            updated_at = UTC_TIMESTAMP(6)
        WHERE id = :id");
    $upd->bindValue(':id', (int)$foundRow['id'], \PDO::PARAM_INT);
    $upd->execute();
    $pdo->commit();

    try { Logger::systemMessage('notice', 'Newsletter unsubscribe', $foundRow['user_id'] ?? null, ['subscriber_id' => (int)$foundRow['id']]); } catch (\Throwable $_) {}

    // memzero sensitive (token and candidate hashes)
    try { KeyManager::memzero($tokenBin); } catch (\Throwable $_) {}
    if (!empty($candidateHashes)) {
        foreach ($candidateHashes as $c) { try { if (isset($c['hash'])) KeyManager::memzero($c['hash']); } catch (\Throwable $_) {} }
    }

    echo Templates::render('pages/newsletter_unsubscribe_success.php', ['email_enc' => $foundRow['email_enc'] ?? null]);
    exit;
} catch (\Throwable $e) {
    try { if ($pdo->inTransaction()) $pdo->rollBack(); } catch (\Throwable $_) {}
    try { Logger::systemError($e); } catch (\Throwable $_) {}
    // memzero sensitive
    try { KeyManager::memzero($tokenBin); } catch (\Throwable $_) {}
    if (!empty($candidateHashes)) {
        foreach ($candidateHashes as $c) { try { if (isset($c['hash'])) KeyManager::memzero($c['hash']); } catch (\Throwable $_) {} }
    }
    http_response_code(500);
    echo Templates::render('pages/newsletter_unsubscribe.php', ['error' => 'Serverová chyba pri odhlasovaní.']);
    exit;
}
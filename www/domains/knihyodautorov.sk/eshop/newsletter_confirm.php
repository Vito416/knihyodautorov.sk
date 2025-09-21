<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';

/**
 * newsletter_confirm.php
 * Double-opt-in confirmation endpoint (production-ready).
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

$selector = isset($_GET['selector']) ? trim((string)$_GET['selector']) : '';
$validatorHex = isset($_GET['validator']) ? trim((string)$_GET['validator']) : '';

if ($selector === '' || $validatorHex === '') {
    echo Templates::render('pages/newsletter_confirm.php', ['error' => 'Chýbajú potrebné parametre.']);
    exit;
}

if (!preg_match('/^[0-9a-fA-F]{12}$/', $selector) || !preg_match('/^[0-9a-fA-F]{64}$/', $validatorHex)) {
    echo Templates::render('pages/newsletter_confirm.php', ['error' => 'Neplatný token.']);
    exit;
}

$validatorBin = @hex2bin($validatorHex);
if ($validatorBin === false) {
    echo Templates::render('pages/newsletter_confirm.php', ['error' => 'Neplatný validator (hex).']);
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

// --- atomické načtení + validace tokenu v rámci transakcie (SELECT ... FOR UPDATE) ---
try {
    $pdo->beginTransaction();

    $q = $pdo->prepare('SELECT * FROM newsletter_subscribers WHERE confirm_selector = :sel LIMIT 1 FOR UPDATE');
    $q->bindValue(':sel', $selector, \PDO::PARAM_STR);
    $q->execute();
    $row = $q->fetch(\PDO::FETCH_ASSOC);

    if (!$row) {
        $pdo->rollBack();
        echo Templates::render('pages/newsletter_confirm.php', ['error' => 'Neplatný alebo už použitý token.']);
        exit;
    }

    // expiry check
    $nowUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    $expiresAt = null;
    if (!empty($row['confirm_expires'])) {
        $expiresAt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $row['confirm_expires'], new \DateTimeZone('UTC'));
        if (!$expiresAt) {
            $expiresAt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $row['confirm_expires'], new \DateTimeZone('UTC'));
        }
    }
    if ($expiresAt !== null && $nowUtc > $expiresAt) {
        $pdo->rollBack();
        echo Templates::render('pages/newsletter_confirm.php', ['error' => 'Potvrdzovací token expiroval. Požiadajte o nový potvrzovací e-mail.']);
        exit;
    }

    $storedValidatorHash = $row['confirm_validator_hash'];
    if ($storedValidatorHash === null || $storedValidatorHash === '') {
        $pdo->rollBack();
        echo Templates::render('pages/newsletter_confirm.php', ['error' => 'Neplatný token (chýba uložený hash).']);
        exit;
    }

    // derive candidate hashes (support key rotation)
    $candidates = [];
    try {
        if (method_exists('KeyManager', 'deriveHmacCandidates')) {
            $candidates = KeyManager::deriveHmacCandidates('EMAIL_VERIFICATION_KEY', KEYS_DIR, 'email_verification_key', $validatorBin);
        } elseif (method_exists('KeyManager', 'deriveHmacWithLatest')) {
            $v = KeyManager::deriveHmacWithLatest('EMAIL_VERIFICATION_KEY', KEYS_DIR, 'email_verification_key', $validatorBin);
            if (!empty($v['hash'])) $candidates[] = $v;
        }
    } catch (\Throwable $e) {
        try { Logger::error('deriveHmacCandidates failed on confirm', $row['id'] ?? null, ['exception' => (string)$e]); } catch (\Throwable $_) {}
    }

    // fallback raw key / pepper / sha256
    if (empty($candidates) && method_exists('KeyManager', 'getEmailVerificationKeyInfo')) {
        try {
            $ev = KeyManager::getEmailVerificationKeyInfo(KEYS_DIR);
            if (!empty($ev['raw']) && is_string($ev['raw'])) {
                $h = hash_hmac('sha256', $validatorBin, $ev['raw'], true);
                $candidates[] = ['hash' => $h, 'version' => $ev['version'] ?? null];
                try { KeyManager::memzero($ev['raw']); } catch (\Throwable $_) {}
            }
        } catch (\Throwable $_) {}
    }
    if (empty($candidates) && method_exists('KeyManager', 'getPasswordPepperInfo')) {
        try {
            $pinfo = KeyManager::getPasswordPepperInfo(KEYS_DIR);
            if (!empty($pinfo['raw']) && is_string($pinfo['raw'])) {
                $h = hash_hmac('sha256', $validatorBin, $pinfo['raw'], true);
                $candidates[] = ['hash' => $h, 'version' => $pinfo['version'] ?? null];
                try { KeyManager::memzero($pinfo['raw']); } catch (\Throwable $_) {}
            }
        } catch (\Throwable $_) {}
    }
    if (empty($candidates)) {
        $candidates[] = ['hash' => hash('sha256', $validatorBin, true), 'version' => null];
        try { Logger::warn('Confirm: using sha256 fallback for validator hash comparison'); } catch (\Throwable $_) {}
    }

    // constant-time compare
    $matched = false;
    $stored = (string)$storedValidatorHash;
    foreach ($candidates as $cand) {
        if (!isset($cand['hash'])) continue;
        $candHash = (string)$cand['hash'];
        if (strlen($stored) !== strlen($candHash)) continue;
        if (hash_equals($stored, $candHash)) { $matched = true; break; }
    }

    if (!$matched) {
        $pdo->rollBack();
        echo Templates::render('pages/newsletter_confirm.php', ['error' => 'Neplatný token alebo bol už použitý.']);
        // memzero sensitive before exit
        try { KeyManager::memzero($validatorBin); } catch (\Throwable $_) {}
        if (!empty($candidates)) {
            foreach ($candidates as $c) { try { if (isset($c['hash'])) KeyManager::memzero($c['hash']); } catch (\Throwable $_) {} }
        }
        exit;
    }

    // update and commit
    $upd = $pdo->prepare("UPDATE newsletter_subscribers
        SET confirmed_at = UTC_TIMESTAMP(6),
            confirm_validator_hash = NULL,
            confirm_key_version = NULL,
            confirm_expires = NULL,
            confirm_selector = NULL,
            updated_at = UTC_TIMESTAMP(6)
        WHERE id = :id");
    $upd->bindValue(':id', (int)$row['id'], \PDO::PARAM_INT);
    $upd->execute();

    $pdo->commit();

    try { Logger::systemMessage('notice', 'Newsletter subscription confirmed', $row['user_id'] ?? null, ['subscriber_id' => (int)$row['id']]); } catch (\Throwable $_) {}

    // memzero sensitive buffers
    try {
        if (function_exists('sodium_memzero')) {
            @sodium_memzero($validatorBin);
        } else {
            $validatorBin = str_repeat("\0", strlen($validatorBin));
        }
        unset($validatorBin);
    } catch (\Throwable $_) {}
    if (!empty($candidates)) {
        foreach ($candidates as $c) {
            try { if (isset($c['hash'])) KeyManager::memzero($c['hash']); } catch (\Throwable $_) {}
        }
        unset($candidates);
    }

    // optional: decrypt email and enqueue welcome mail (outside transaction)
    try {
        $decryptedEmail = null;
        if (!empty($row['email_enc']) && class_exists('Crypto') && method_exists('Crypto', 'initFromKeyManager')) {
            try {
                Crypto::initFromKeyManager(KEYS_DIR);
                $decryptedEmail = Crypto::decrypt($row['email_enc']); // Crypto::decrypt handles versioned/compact formats
            } catch (\Throwable $e) {
                try { Logger::error('Email decrypt failed for welcome mail', $row['user_id'] ?? null, ['exception' => (string)$e]); } catch (\Throwable $_) {}
                $decryptedEmail = null;
            } finally {
                try { Crypto::clearKey(); } catch (\Throwable $_) {}
            }
        }

        if ($decryptedEmail !== null && filter_var($decryptedEmail, FILTER_VALIDATE_EMAIL) && class_exists('Mailer') && method_exists('Mailer', 'enqueue')) {
            $base = rtrim((string)($_ENV['BASE_URL'] ?? ''), '/');
            $payload = [
                'target' => 'newsletter',
                'subscriber_id' => (int)$row['id'],
                'to' => $decryptedEmail,
                'subject' => 'Vitajte — potvrdenie odberu noviniek',
                'template' => 'newsletter_welcome',
                'vars' => [],
            ];
            try {
                $notifId = Mailer::enqueue($payload);
                try { Logger::systemMessage('notice', 'Newsletter welcome enqueued', $row['user_id'] ?? null, ['subscriber_id' => (int)$row['id'], 'notification_id' => $notifId]); } catch (\Throwable $_) {}
            } catch (\Throwable $e) {
                try { Logger::error('Mailer::enqueue failed for welcome', $row['user_id'] ?? null, ['exception' => (string)$e]); } catch (\Throwable $_) {}
            }
        }
    } catch (\Throwable $_) {
        // swallow, only log earlier
    }

    echo Templates::render('pages/newsletter_confirm_success.php', ['email_enc' => $row['email_enc'] ?? null]);
    exit;

} catch (\Throwable $e) {
    try { if ($pdo->inTransaction()) $pdo->rollBack(); } catch (\Throwable $_) {}
    try { Logger::systemError($e); } catch (\Throwable $_) {}
    http_response_code(500);
    echo Templates::render('pages/newsletter_confirm.php', ['error' => 'Interná chyba.']);
    exit;
}
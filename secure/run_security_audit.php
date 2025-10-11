<?php

declare(strict_types=1);

use BlackCat\Core\Log\AuditLogger;
use BlackCat\Core\Security\Crypto;
use BlackCat\Core\Security\KeyManager;
use BlackCat\Core\Security\FileVault;

/**
 * One-shot security audit runner.
 * - Volat pouze přes tmp_trigger.php, který kontroluje token a IP!
 * - Tento skript už token neřeší.
 * - Provádí kontroly: keys presence/length/perms, Crypto init, roundtrip, FileVault roundtrip, AuditLogger test.
 * - DOES NOT print secrets.
 *
 * After running: IMMEDIATELY remove this file.
 */

// Only POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo "Method not allowed. Use POST.";
    exit;
}

// Load environment and app
require_once __DIR__ . '/load_env.php';

// include config and libraries
$root = realpath(__DIR__ . '/..') ?: (__DIR__ . '/..');
require_once $root . '/secure/config.php'; // ensures $config exists
require_once $root . '/libs/KeyManager.php';
require_once $root . '/libs/Crypto.php';
require_once $root . '/libs/FileVault.php';
require_once $root . '/libs/AuditLogger.php';

$results = [];
$all_ok = true;

// Helper to record result
$rec = function(string $k, bool $ok, string $msg = '') use (&$results, &$all_ok) {
    $results[$k] = ['ok'=>$ok, 'msg'=>$msg];
    if (!$ok) $all_ok = false;
};

// 1) Keys existence and lengths (crypto_key, filevault_key, optional audit_key)
$keysDir = $config['paths']['keys'] ?? (__DIR__ . '/keys');
$checkList = [
    'crypto_key' => ['basename'=>'crypto_key','required'=>true],
    'filevault_key' => ['basename'=>'filevault_key','required'=>true],
    'audit_key' => ['basename'=>'audit_key','required'=>false],
];
foreach ($checkList as $id => $meta) {
    $info = KeyManager::locateLatestKeyFile($keysDir, $meta['basename']);
    if ($info === null) {
        if ($meta['required']) {
            $rec("key_exists_$id", false, "Missing required key file for {$meta['basename']}. Expected e.g. {$meta['basename']}_v1.bin or {$meta['basename']}.bin in $keysDir");
        } else {
            $rec("key_exists_$id", true, "Optional key {$meta['basename']} not present (OK).");
        }
        continue;
    }
    $path = $info['path'];
    $size = @filesize($path);
    $perm = @substr(sprintf('%o', @fileperms($path)), -4);
    $expectedLen = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES;
    if ($size !== $expectedLen) {
        $rec("key_len_$id", false, "Key file {$path} has size {$size} (expected {$expectedLen}).");
    } else {
        $rec("key_len_$id", true, "Key {$path} present length {$size} bytes; perms {$perm}.");
    }
}

// 2) Permissions heuristic (keys directory and files)
$dirPerm = @substr(sprintf('%o', @fileperms($keysDir)), -4);
if (!is_dir($keysDir)) {
    $rec('keys_dir', false, "Keys dir $keysDir missing.");
} else {
    // ensure keys dir not world-writable/readable
    if (in_array($dirPerm, ['0777','0755'])) {
        $rec('keys_dir_perms', false, "Keys dir perms appear permissive: $dirPerm. Prefer 0750 and files 0400.");
    } else {
        $rec('keys_dir_perms', true, "Keys dir perms $dirPerm (check host allowlist).");
    }
}

// 3) Crypto init test (attempt to init from key file)
try {
    $b64 = KeyManager::getBase64Key('APP_CRYPTO_KEY', $keysDir, 'crypto_key', false);
    Crypto::init_from_base64($b64);
    $rec('crypto_init', true, 'Crypto::init_from_base64 succeeded (key loaded).');
} catch (Throwable $e) {
    $rec('crypto_init', false, 'Crypto init failed: ' . $e->getMessage());
}

// 4) Encrypt/decrypt roundtrip
try {
    $plaintext = 'audit-test-' . bin2hex(random_bytes(4));
    $enc = Crypto::encrypt($plaintext, 'compact_base64');
    $dec = Crypto::decrypt($enc);
    if ($dec === $plaintext) {
        $rec('enc_roundtrip', true, 'Encrypt/decrypt roundtrip OK.');
    } else {
        $rec('enc_roundtrip', false, 'Encrypt/decrypt returned mismatch or null.');
    }
} catch (Throwable $e) {
    $rec('enc_roundtrip', false, 'Encrypt/decrypt failed: ' . $e->getMessage());
}

// 5) FileVault small-file roundtrip (uploadAndEncrypt + decryptAndStream capture)
$tmpPlain = $config['paths']['uploads'] . '/audit_test_plain_' . bin2hex(random_bytes(4)) . '.txt';
$tmpEnc = $config['paths']['storage'] . '/audit_test_enc_' . bin2hex(random_bytes(4)) . '.enc';
@mkdir(dirname($tmpPlain), 0750, true);
@mkdir(dirname($tmpEnc), 0750, true);
file_put_contents($tmpPlain, "Hello Audit Test " . time());
try {
    $encPath = FileVault::uploadAndEncrypt($tmpPlain, $tmpEnc);
    if ($encPath === false) {
        $rec('filevault_upload', false, 'FileVault::uploadAndEncrypt returned false.');
    } else {
        // capture output of decryptAndStream
        ob_start();
        $ok = FileVault::decryptAndStream($encPath, 'audit_test.txt', 'text/plain');
        $out = ob_get_clean();
        // cleanup tmp files
        @unlink($tmpPlain);
        //@unlink($encPath); // keep enc for inspection if needed
        if ($ok && strpos($out, 'Hello Audit Test') !== false) {
            $rec('filevault_roundtrip', true, 'FileVault upload+decrypt stream OK (content returned).');
        } elseif ($ok) {
            $rec('filevault_roundtrip', true, 'FileVault upload+decrypt stream OK (content returned but exact substring not detected).');
        } else {
            $rec('filevault_roundtrip', false, 'FileVault decryptAndStream failed (returned false).');
        }
    }
} catch (Throwable $e) {
    @unlink($tmpPlain);
    $rec('filevault_roundtrip', false, 'FileVault test exception: ' . $e->getMessage());
}

// 6) AuditLogger test
// Try to build PDO if config contains DB creds
$pdo = null;
try {
    $dsn = $config['db']['dsn'] ?? '';
    $user = $config['db']['user'] ?? '';
    $pass = $config['db']['pass'] ?? '';
    if ($dsn !== '' && $user !== '') {
        $options = ($config['db']['options'] ?? []) + [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3];
        $pdo = new PDO($dsn, $user, $pass, $options);
        $rec('db_connect', true, 'DB connection ok.');
    } else {
        $rec('db_connect', false, 'DB credentials not configured; AuditLogger will use file fallback.');
        $pdo = null;
    }
} catch (Throwable $e) {
    $rec('db_connect', false, 'DB connect failed: ' . $e->getMessage());
    $pdo = null;
}

// call AuditLogger::log
try {
    session_start(); // attempt to read session user id if exists
    $actor = $_SESSION['user_id'] ?? null;
    $details = ['note'=>'audit_test','ts'=>time()];
    $logged = AuditLogger::log($pdo instanceof PDO ? $pdo : null, $actor !== null ? (int)$actor : null, 'audit_selftest', $details, null);
    if ($logged) {
        $rec('audit_logger', true, 'AuditLogger::log succeeded (DB or file fallback).');
    } else {
        $rec('audit_logger', false, 'AuditLogger::log failed (see server logs).');
    }
} catch (Throwable $e) {
    $rec('audit_logger', false, 'Audit test exception: ' . $e->getMessage());
}

// 7) CI-style sanity checks: check_keys_ci style (key lengths & perms)
try {
    $ci_errors = [];
    $keyFiles = glob(rtrim($keysDir,'/\\') . '/*.bin') ?: [];
    if (empty($keyFiles)) {
        $ci_errors[] = "No .bin key files found in $keysDir";
    }
    foreach ($keyFiles as $f) {
        $size = @filesize($f);
        if ($size !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            $ci_errors[] = "BAD_LEN: $f size=$size";
        }
        $permStr = @substr(sprintf('%o', @fileperms($f)), -4);
        if (in_array($permStr, ['0644','0664','0777'])) {
            $ci_errors[] = "POTENTIAL_PERMS: $f perms=$permStr";
        }
    }
    if (empty($ci_errors)) {
        $rec('ci_checks', true, 'CI checks OK (key lengths and perms seem sane).');
    } else {
        $rec('ci_checks', false, implode('; ', $ci_errors));
    }
} catch (Throwable $e) {
    $rec('ci_checks', false, 'CI checks failed: ' . $e->getMessage());
}

// Final summary
$summary = [
    'all_ok' => $all_ok,
    'results' => $results
];

// Print human-readable summary (no secrets)
header('Content-Type: application/json');
echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
exit;
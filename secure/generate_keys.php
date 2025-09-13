<?php
// /secure/generate_keys.php
declare(strict_types=1);

// Tento skript generuje versionované key files pomocí veřejného API KeyManager.
// PO VYGENEROVÁNÍ SKRIPT SMAŽ! (a také odstraň KEY_GEN_TOKEN z .env)

// Pouze POST + token
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo "Method not allowed";
    exit;
}

require_once __DIR__ . '/load_env.php';
require_once __DIR__ . '/../libs/KeyManager.php';

$expected = $_ENV['KEY_GEN_TOKEN'] ?? '';
$sent = $_POST['token'] ?? '';

if (!is_string($expected) || $expected === '') {
    http_response_code(403);
    echo "Key generation disabled (no token configured).";
    exit;
}
if (!is_string($sent) || !hash_equals($expected, $sent)) {
    http_response_code(403);
    echo "Invalid token.";
    exit;
}

$keysDir = __DIR__ . '/keys';
@mkdir($keysDir, 0750, true);

$created = [];

try {
    // for each desired key basename: if none found -> generate (via KeyManager public API)
    $toCreate = [
        ['env'=>'APP_CRYPTO_KEY', 'basename'=>'crypto_key', 'required'=>true],
        ['env'=>'FILEVAULT_KEY', 'basename'=>'filevault_key', 'required'=>true],
        ['env'=>'PASSWORD_PEPPER', 'basename'=>'password_pepper', 'required'=>true],
        ['env'=>'APP_SALT', 'basename'=>'app_salt', 'required'=>true],
        // optional: audit key - uncomment if you want to generate automatically
        // ['env'=>'AUDIT_KEY', 'basename'=>'audit_key', 'required'=>false],
    ];

    foreach ($toCreate as $spec) {
        $basename = $spec['basename'];
        $info = KeyManager::locateLatestKeyFile($keysDir, $basename);
        if ($info === null) {
            // generate via KeyManager public method (this will write a versioned file, e.g. basename_v1.bin)
            // we call getBase64Key with generateIfMissing = true
            $b64 = KeyManager::getBase64Key($spec['env'], $keysDir, $basename, true);
            // confirm file exists now
            $newInfo = KeyManager::locateLatestKeyFile($keysDir, $basename);
            if ($newInfo === null) {
                throw new RuntimeException('KeyManager reported generation succeeded but file not found for ' . $basename);
            }
            $created[] = basename($newInfo['path']);
        } else {
            // already present - report existing (but do not consider as created)
            // skip
        }
    }

    header('Content-Type: application/json');
    echo json_encode(['status'=>'ok','created'=>$created], JSON_UNESCAPED_SLASHES);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    error_log('[keygen] ' . $e->getMessage());
    echo "Key generation failed (see server log).";
    exit;
}
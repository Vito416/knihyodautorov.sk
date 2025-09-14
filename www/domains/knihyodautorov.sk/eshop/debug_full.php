<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/inc/bootstrap.php';

echo "=== SESSION MANAGER FULL TEST START ===\n";

try {
    // --- Vyber režim DB ---
    // Pokud chceš dočasnou DB v paměti použij 'sqlite::memory:'
    // Pokud chceš souborovou DB (nevypíná se při ukončení procesu) použij cestu níže.
    $useFileDb = false;

    if ($useFileDb) {
        $dbPath = __DIR__ . '/test_sessions.sqlite';
        $db = new PDO('sqlite:' . $dbPath);
        echo "[OK] SQLite file DB at: $dbPath\n";
    } else {
        $db = new PDO('sqlite::memory:');
        echo "[OK] SQLite in-memory DB ready.\n";
    }

    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // --- Důležité: NEVYTVÁŘET žádné tabulky zde ---
    // SessionManager by měl sám zapisovat / vytvářet potřebné struktury.

    // --- Create session ---
    $userId = 42;
    $token = SessionManager::createSession($db, $userId);
    $_COOKIE['session_token'] = $token;
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['HTTP_USER_AGENT'] = 'UnitTest-Agent/1.0';
    echo "[OK] createSession executed, token: $token\n";

    // --- Validate session ---
    $validatedUserId = SessionManager::validateSession($db);
    echo $validatedUserId === $userId ? "[OK] validateSession correct\n" : "[ERROR] validateSession failed\n";

    // --- last_seen_at updated ---
    // Předpokládáme, že SessionManager už zapsal řádek; tady jen čteme.
    $stmt = $db->prepare("SELECT last_seen_at FROM sessions WHERE token_hash = :token_hash");
    $tokenHash = hash('sha256', $token, true);
    // bindParam s PDO::PARAM_LOB pro binární sloupce
    $stmt->bindParam(':token_hash', $tokenHash, PDO::PARAM_LOB);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo $row ? "[OK] last_seen_at exists: {$row['last_seen_at']}\n" : "[ERROR] last_seen_at missing (table/row may not exist)\n";

    // --- Test user-agent protection ---
    $_SERVER['HTTP_USER_AGENT'] = 'Wrong-Agent/2.0';
    echo SessionManager::validateSession($db) === null ? "[OK] user-agent protection working\n" : "[ERROR] user-agent protection failed\n";
    $_SERVER['HTTP_USER_AGENT'] = 'UnitTest-Agent/1.0';

    // --- Test IP protection ---
    $_SERVER['REMOTE_ADDR'] = '192.168.1.100';
    echo SessionManager::validateSession($db) === null ? "[OK] IP protection working\n" : "[ERROR] IP protection failed\n";
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

    // --- Test allowMultiple=false revocation ---
    $token2 = SessionManager::createSession($db, $userId, 30, false);
    $_COOKIE['session_token'] = $token2;
    echo SessionManager::validateSession($db) === $userId ? "[OK] validateSession after revoking old sessions\n" : "[ERROR] validateSession failed after revoking\n";

    // --- Old token should be revoked ---
    $_COOKIE['session_token'] = $token;
    echo SessionManager::validateSession($db) === null ? "[OK] old token correctly revoked\n" : "[ERROR] old token still valid\n";

    // --- Test expired session ---
    $stmt = $db->prepare("UPDATE sessions SET expires_at = :expired WHERE token_hash = :token_hash");
    $expired = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-1 day')->format('Y-m-d H:i:s.u');
    $tokenHash2 = hash('sha256', $token2, true);
    $stmt->bindParam(':expired', $expired);
    $stmt->bindParam(':token_hash', $tokenHash2, PDO::PARAM_LOB);
    $stmt->execute();

    $_COOKIE['session_token'] = $token2;
    echo SessionManager::validateSession($db) === null ? "[OK] expired session correctly rejected\n" : "[ERROR] expired session still valid\n";

    // --- Destroy session ---
    $_COOKIE['session_token'] = $token2;
    SessionManager::destroySession($db);
    echo "[OK] destroySession executed\n";

    echo SessionManager::validateSession($db) === null ? "[OK] session destroyed correctly\n" : "[ERROR] session still exists after destroy\n";

    echo "=== SESSION MANAGER FULL TEST END ===\n";

} catch (\Throwable $e) {
    echo "[EXCEPTION] " . get_class($e) . ": " . $e->getMessage() . "\n";
}
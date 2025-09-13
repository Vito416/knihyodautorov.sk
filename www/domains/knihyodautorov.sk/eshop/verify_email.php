<?php
require __DIR__ . '/inc/bootstrap.php';
if (!function_exists('e')) { function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); } }

$uid = isset($_GET['uid']) ? (int)$_GET['uid'] : 0;
$token = $_GET['token'] ?? '';

/**
 * Přesměrování na login s číselným stavem verifikace
 */
function redirectWithVerificationStatus(int $statusCode): void {
    header('Location: login.php?verified=' . $statusCode);
    exit;
}

if ($uid && $token) {
    // základní validace tokenu (čekáme 64 hex znaků pro 32 bytes -> bin2hex(random_bytes(32)))
    if (!is_string($token) || strlen($token) !== 64 || !ctype_xdigit($token)) {
        if (class_exists('Logger')) {
            Logger::verify('verify_failure', $uid, ['reason'=>'token_malformed']);
        }
        redirectWithVerificationStatus(4);
    }

    // Najdi nejnovější verifikaci podle UID (pokud jich může být více, vezmeme poslední)
    $stmt = $db->prepare('SELECT id, expires_at, used_at, token_hash, key_version, created_at 
                          FROM email_verifications 
                          WHERE user_id = ? 
                          ORDER BY created_at DESC
                          LIMIT 1');
    $stmt->execute([$uid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    // limit 5 neúspěšných pokusů za hodinu pro jedno UID
    $limitStmt = $db->prepare('SELECT COUNT(*) FROM verify_events WHERE user_id = ? AND type = ? AND occurred_at >= (NOW() - INTERVAL 1 HOUR)');
    $limitStmt->execute([$uid, 'verify_failure']);
    $failCount = (int)$limitStmt->fetchColumn();
    if ($failCount >= 5) {
        if (class_exists('Logger')) {
            Logger::verify('verify_failure', $uid, ['reason'=>'rate_limit_exceeded']);
        }
        redirectWithVerificationStatus(3);
    }

    if ($row) {
        try {
            if (!class_exists('KeyManager')) {
                throw new RuntimeException('KeyManager not available; cannot verify token.');
            }

            // načti pepper podle key_version pokud existuje (pokud máš soubory s verzemi)
            $keyVer = $row['key_version'] ?? null;
            $pepperRaw = null;

            if ($keyVer !== null && $keyVer !== '') {
                $keyVerInt = (int) filter_var((string)$keyVer, FILTER_SANITIZE_NUMBER_INT);
                if ($keyVerInt > 0 && defined('KEYS_DIR')) {
                    $candidate = rtrim(KEYS_DIR, '/\\') . '/password_pepper_v' . $keyVerInt . '.bin';
                    if (is_readable($candidate)) {
                        $pepperRaw = @file_get_contents($candidate);
                        if ($pepperRaw === false) $pepperRaw = null;
                    }
                }
            }

            // fallback: KeyManager - konzistentní s registrem
            if ($pepperRaw === null) {
                $pepperInfo = KeyManager::getRawKeyBytes('PASSWORD_PEPPER', defined('KEYS_DIR') ? KEYS_DIR : null, 'password_pepper', false);
                $pepperRaw = $pepperInfo['raw'] ?? '';
            }

            if (empty($pepperRaw)) {
                throw new RuntimeException('No pepper found for key_version ' . var_export($keyVer, true));
            }

            // Převod tokenu z hex (URL) na raw bytes a HMAC nad raw bytes
            $tokenBytes = @hex2bin($token);
            if ($tokenBytes === false) {
                if (class_exists('Logger')) {
                    Logger::verify('verify_failure', $uid, ['reason'=>'token_hex_invalid']);
                }
                redirectWithVerificationStatus(4);
            }

            // hash_hmac vrací hex string; v insertu jsme uložili hex string -> porovnání přes hash_equals
            $tokenHash = hash_hmac('sha256', $tokenBytes, $pepperRaw);

            if (!hash_equals((string)$row['token_hash'], $tokenHash)) {
                if (class_exists('Logger')) {
                    Logger::verify('verify_failure', $uid, ['reason'=>'token_mismatch']);
                }
                redirectWithVerificationStatus(4);
            }

            // token matches — teď zkontrolujeme used/expiration
            if ($row['used_at'] !== null) {
                if (class_exists('Logger')) {
                    Logger::verify('verify_failure', $uid, ['reason'=>'used_token']);
                }
                redirectWithVerificationStatus(5);
            }

            if (new DateTime($row['expires_at']) < new DateTime()) {
                if (class_exists('Logger')) {
                    Logger::verify('verify_failure', $uid, ['reason'=>'expired_token']);
                }
                redirectWithVerificationStatus(6);
            }

            // vše ok -> aktivujeme uživatele a označíme token jako použitý atomicky
            $db->beginTransaction();
            try {
                $updateUser = $db->prepare('UPDATE pouzivatelia SET is_active = 1, updated_at = NOW() WHERE id = ? AND is_active = 0');
                $updateUser->execute([$uid]);
                if ($updateUser->rowCount() === 0) {
                    if (class_exists('Logger')) {
                        Logger::verify('verify_failure', $uid, ['reason'=>'already_active']);
                    }
                    $db->rollBack();
                    redirectWithVerificationStatus(7);
                }

                $db->prepare('UPDATE email_verifications SET used_at = NOW() WHERE id = ?')
                   ->execute([$row['id']]);

                if (class_exists('Logger')) {
                    Logger::verify('verify_success', $uid, ['message' => 'Email verified successfully']);
                }

                $db->commit();
                redirectWithVerificationStatus(0);
            } catch (\Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                Logger::systemError($e, $uid);
                redirectWithVerificationStatus(8);
            }
        } catch (Throwable $e) {
            Logger::systemError($e, $uid);
            redirectWithVerificationStatus(4);
        }
    } else {
        if (class_exists('Logger')) {
            Logger::verify('verify_failure', $uid, ['reason'=>'no_row_found']);
        }
        redirectWithVerificationStatus(4);
    }
}
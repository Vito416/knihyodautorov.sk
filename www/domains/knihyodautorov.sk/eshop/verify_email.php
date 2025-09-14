<?php
declare(strict_types=1);

require __DIR__ . '/inc/bootstrap.php';
if (!function_exists('e')) { function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); } }

$uid = isset($_GET['uid']) ? (int)$_GET['uid'] : 0;
$token = $_GET['token'] ?? '';

function redirectWithVerificationStatus(int $statusCode): void {
    header('Location: login.php?verified=' . $statusCode);
    exit;
}

/**
 * Zaznamená push notifikaci pro uživatele
 */
function recordPushNotification(PDO $db, int $userId, string $message): void {
    $stmt = $db->prepare(
        'INSERT INTO user_notifications (user_id, type, message, created_at) 
         VALUES (?, ?, ?, NOW())'
    );
    $stmt->execute([$userId, 'push', $message]);
}

if ($uid && $token) {
    if (!is_string($token) || strlen($token) !== 64 || !ctype_xdigit($token)) {
        Logger::verify('verify_failure', $uid, ['reason'=>'token_malformed']);
        redirectWithVerificationStatus(4);
    }

    $stmt = $db->prepare('SELECT id, expires_at, used_at, token_hash, key_version, created_at 
                          FROM email_verifications 
                          WHERE user_id = ? 
                          ORDER BY created_at DESC
                          LIMIT 1');
    $stmt->execute([$uid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    // limit 5 neúspěšných pokusů za hodinu
    $limitStmt = $db->prepare('SELECT COUNT(*) FROM verify_events WHERE user_id = ? AND type = ? AND occurred_at >= (NOW() - INTERVAL 1 HOUR)');
    $limitStmt->execute([$uid, 'verify_failure']);
    $failCount = (int)$limitStmt->fetchColumn();
    if ($failCount >= 5) {
        Logger::verify('verify_failure', $uid, ['reason'=>'rate_limit_exceeded']);
        redirectWithVerificationStatus(3);
    }

    if ($row) {
        try {
            // načtení pepper z KeyManageru
            if (!class_exists('KeyManager')) {
                throw new RuntimeException('KeyManager not available; cannot verify token.');
            }
            $pepperInfo = KeyManager::getRawKeyBytes('PASSWORD_PEPPER', defined('KEYS_DIR') ? KEYS_DIR : null, 'password_pepper', false);
            $pepperRaw = $pepperInfo['raw'] ?? '';
            if (empty($pepperRaw)) throw new RuntimeException('No pepper found');

            $tokenBytes = @hex2bin($token);
            if ($tokenBytes === false) {
                Logger::verify('verify_failure', $uid, ['reason'=>'token_hex_invalid']);
                redirectWithVerificationStatus(4);
            }

            $tokenHash = hash_hmac('sha256', $tokenBytes, $pepperRaw);

            if (!hash_equals((string)$row['token_hash'], $tokenHash)) {
                Logger::verify('verify_failure', $uid, ['reason'=>'token_mismatch']);
                redirectWithVerificationStatus(4);
            }

            if ($row['used_at'] !== null) {
                Logger::verify('verify_failure', $uid, ['reason'=>'used_token']);
                redirectWithVerificationStatus(5);
            }

            if (new DateTime($row['expires_at']) < new DateTime()) {
                Logger::verify('verify_failure', $uid, ['reason'=>'expired_token']);
                redirectWithVerificationStatus(6);
            }

            // aktivace uživatele a označení tokenu jako použitého atomicky
            $db->beginTransaction();
            try {
                $updateUser = $db->prepare('UPDATE pouzivatelia SET is_active = 1, updated_at = NOW() WHERE id = ? AND is_active = 0');
                $updateUser->execute([$uid]);
                if ($updateUser->rowCount() === 0) {
                    Logger::verify('verify_failure', $uid, ['reason'=>'already_active']);
                    $db->rollBack();
                    redirectWithVerificationStatus(7);
                }

                $db->prepare('UPDATE email_verifications SET used_at = NOW() WHERE id = ?')->execute([$row['id']]);

                // push notifikace místo emailu
                recordPushNotification($db, $uid, 'Vaše emailová adresa byla ověřena.');

                Logger::verify('verify_success', $uid, ['message' => 'Email verified successfully']);
                $db->commit();
                redirectWithVerificationStatus(0);
            } catch (\Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                Logger::systemError($e, $uid);
                redirectWithVerificationStatus(8);
            }

        } catch (\Throwable $e) {
            Logger::systemError($e, $uid);
            redirectWithVerificationStatus(4);
        }
    } else {
        Logger::verify('verify_failure', $uid, ['reason'=>'no_row_found']);
        redirectWithVerificationStatus(4);
    }
}
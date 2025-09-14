<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php'; // Auth, KeyManager, Logger, $db, $config
use PHPMailer\PHPMailer\PHPMailer;

header('Content-Type: application/json; charset=utf-8');

$response = ['success' => false, 'errors' => []];

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Invalid request method');
    }

    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!\CSRF::validate($csrf)) {
        $response['errors']['csrf'] = 'Neplatný formulár (CSRF)';
        echo json_encode($response);
        exit;
    }

    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $password = (string)($_POST['password'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $response['errors']['email'] = 'Neplatný email';
    }

    if (mb_strlen($password) < 12) {
        $response['errors']['password'] = 'Heslo musí mať aspoň 12 znakov';
    }

    if (!empty($response['errors'])) {
        echo json_encode($response);
        exit;
    }

    $db->beginTransaction();

    // Kontrola existence emailu
    $chk = $db->prepare('SELECT id FROM pouzivatelia WHERE email = ? LIMIT 1');
    $chk->execute([$email]);
    if ($chk->fetchColumn()) {
        $db->rollBack();
        $response['errors']['email'] = 'Účet s týmto e-mailom už existuje';
        echo json_encode($response);
        exit;
    }

    // Hash hesla s pepper
    $pepper = KeyManager::getPasswordPepperInfo()['raw'];
    $pwdForHash = hash_hmac('sha256', $password, $pepper, true);
    $hash = password_hash($pwdForHash, PASSWORD_ARGON2ID);
    $pwAlgo = password_get_info($hash)['algoName'] ?? 'argon2id';

    // Vložení uživatele
    $stmt = $db->prepare('INSERT INTO pouzivatelia (email, heslo_hash, heslo_algo, is_active, actor_type, created_at, updated_at) VALUES (?, ?, ?, 0, ?, NOW(), NOW())');
    $stmt->execute([$email, $hash, $pwAlgo, 'zakaznik']);
    $userId = (int)$db->lastInsertId();

    // Role
    $roleName = 'Zákazník';
    $rsel = $db->prepare('SELECT id FROM roles WHERE nazov = ? LIMIT 1 FOR UPDATE');
    $rsel->execute([$roleName]);
    $roleId = $rsel->fetchColumn();
    if (!$roleId) {
        $rins = $db->prepare('INSERT INTO roles (nazov, popis, created_at, updated_at) VALUES (?, ?, NOW(), NOW())');
        $rins->execute([$roleName, 'Automaticky vytvorená rola pre nových používateľov']);
        $roleId = (int)$db->lastInsertId();
    } else {
        $roleId = (int)$roleId;
    }

    $uassign = $db->prepare('INSERT INTO user_roles (user_id, role_id, assigned_at) VALUES (?, ?, NOW())');
    $uassign->execute([$userId, $roleId]);

    // Profil
    $pcreate = $db->prepare('INSERT INTO user_profiles (user_id, full_name, updated_at) VALUES (?, ?, NOW())');
    $pcreate->execute([$userId, '']);

    // Token pro verifikaci e-mailu
    $tokenRaw = random_bytes(32);
    $expiresAt = (new DateTime('+7 days'))->format('Y-m-d H:i:s');
    $appSalt = KeyManager::getSaltInfo()['raw'];
    $tokenHash = hash_hmac('sha256', $tokenRaw, $appSalt);

    $tins = $db->prepare('INSERT INTO email_verifications (user_id, token_hash, expires_at, key_version, created_at) VALUES (?, ?, ?, ?, NOW())');
    $pepperVersion = KeyManager::getSaltInfo()['version'];
    $tins->execute([$userId, $tokenHash, $expiresAt, $pepperVersion]);

    // Notification payload
    $verifyUrl = rtrim(APP_URL ?? 'https://example.com', '/') . '/verify_email.php?uid=' . $userId . '&token=' . bin2hex($tokenRaw);
    $subject = (APP_NAME ?? 'Naša služba') . ': potvrďte svoj e-mail';
    $payloadArr = [
        'to' => $email,
        'subject' => $subject,
        'template' => 'verify_email',
        'vars' => ['verify_url' => $verifyUrl, 'expires_at' => $expiresAt, 'site' => APP_NAME ?? null]
    ];
    $payload = json_encode($payloadArr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $nins = $db->prepare('INSERT INTO notifications (user_id, channel, template, payload, status, scheduled_at, created_at, retries, max_retries) VALUES (?, ?, ?, ?, ?, NOW(), NOW(), 0, ?)');
    $nins->execute([$userId, 'email', 'verify_email', $payload, 'pending', 6]);

    $db->commit();
    $response['success'] = true;
} catch (\Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    $response['errors']['general'] = 'Registrácia zlyhala. Skúste to prosím neskôr.';
    Logger::systemError($e);
}

echo json_encode($response);
exit;
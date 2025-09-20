<?php
declare(strict_types=1);
// /www/eshop/actions/request_password_reset.php
// Požiadavka na reset hesla (POST) - očakáva email

require_once __DIR__ . '/../../bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success'=>false,'message'=>'Použite POST.']);
        exit;
    }
    if (!class_exists('CSRF')) throw new RuntimeException('CSRF missing.');
    if (!CSRF::validate($_POST['csrf'] ?? null)) {
        echo json_encode(['success'=>false,'message'=>'Neplatný CSRF token.']);
        exit;
    }

    $email = trim((string)($_POST['email'] ?? ''));
    if (!class_exists('Validator') || !Validator::validateEmail($email)) {
        echo json_encode(['success'=>false,'message'=>'Neplatný e-mail.']);
        exit;
    }

    // Hľadať používateľa podľa email_hash v DB
    $db = Database::getInstance()->getPdo();
    $q = $db->prepare('SELECT id FROM pouzivatelia WHERE email_hash = :h LIMIT 1');
    // email_hash = HMAC/derivovaný hash — musíte použiť rovnakú funkciu ako pri uložení
    // Používam KeyManager::deriveHmacWithLatest predpoklad (podľa dokumentácie)
    if (!class_exists('KeyManager') || !method_exists('KeyManager', 'deriveHmacWithLatest')) {
        // ak chýba, logujeme a ticho nepovedieme presný dôvod
        echo json_encode(['success'=>false,'message'=>'Serverová chyba.']);
        exit;
    }
    $emailHashRaw = KeyManager::deriveHmacWithLatest('email_hash', $email);
    $q->execute([':h' => $emailHashRaw]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        // Nechceme prezradiť, že e-mail nie je registrovaný
        echo json_encode(['success'=>true,'message'=>'Ak je e-mail registrovaný, obdržíte inštrukcie na reset hesla.']);
        exit;
    }
    $userId = (int)$row['id'];

    // Vytvoriť záznam v email_verifications (alebo password_reset table) - tu používame email_verifications ako zjednodušenie
    $selector = bin2hex(random_bytes(6));
    $validator = bin2hex(random_bytes(32));
    $validatorHash = hash_hmac('sha256', $validator, KeyManager::getPasswordPepperInfo()['raw'] ?? random_bytes(32), true); // raw binary
    $expires = (new DateTime('+1 hour'))->format('Y-m-d H:i:s');

    $ins = $db->prepare('INSERT INTO email_verifications (user_id, token_hash, selector, validator_hash, key_version, expires_at) VALUES (:uid, :token, :sel, :vhash, :kv, :exp)');
    // token_hash: store hex of token or combined; tu uložíme hex(selector+validator) len ako placeholder - implementujte robustne
    $tokenHashHex = hash('sha256', $selector . $validator);
    $keyVersion = KeyManager::getCurrentPasswordPepperVersion() ?? 0;
    $ins->execute([
        ':uid' => $userId,
        ':token' => $tokenHashHex,
        ':sel' => $selector,
        ':vhash' => $validatorHash,
        ':kv' => $keyVersion,
        ':exp' => $expires,
    ]);

    // Pošleme e-mail s linkom (link bude obsahovať selector + validator v URL, validator sa neukladá v DB, len hash)
    if (class_exists('EmailTemplates') && class_exists('Mailer')) {
        $resetUrl = (string)($_ENV['APP_BASE_URL'] ?? '') . '/eshop/reset_password.php?selector=' . rawurlencode($selector) . '&validator=' . rawurlencode($validator);
        $payload = [
            'to' => $email,
            'subject' => 'Obnovenie hesla',
            'template' => 'password_reset_request.php',
            'vars' => ['reset_url' => $resetUrl],
        ];
        try { Mailer::enqueue($payload); } catch (\Throwable $e) { Logger::systemError($e); }
    }

    echo json_encode(['success'=>true,'message'=>'Ak je e-mail registrovaný, obdržíte inštrukcie na reset hesla.']);
    exit;

} catch (\Throwable $e) {
    if (class_exists('Logger')) Logger::systemError($e);
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Serverová chyba.']);
    exit;
}
<?php
declare(strict_types=1);
// /www/eshop/actions/resend_verification.php
// POST: email -> znovu pošle verifikačný e-mail (bez odhalenia, či existuje účet)

require_once __DIR__ . '/../../bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success'=>false]); exit; }
    if (!class_exists('CSRF')) throw new RuntimeException('CSRF missing.');
    if (!CSRF::validate($_POST['csrf'] ?? null)) { echo json_encode(['success'=>false,'message'=>'Neplatný CSRF']); exit; }

    $email = trim((string)($_POST['email'] ?? ''));
    if (!class_exists('Validator') || !Validator::validateEmail($email)) {
        echo json_encode(['success'=>true,'message'=>'Ak je e-mail registrovaný, obdržíte overovací e-mail.']);
        exit;
    }

    // nájdi používateľa a ak nie je aktívny, vygeneruj a pošli e-mail (bez errorov pre klienta)
    $db = Database::getInstance()->getPdo();
    $h = KeyManager::deriveHmacWithLatest('email_hash', $email);
    $q = $db->prepare('SELECT id, is_active FROM pouzivatelia WHERE email_hash = :h LIMIT 1');
    $q->execute([':h' => $h]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if (!$row || (int)$row['is_active'] === 1) {
        echo json_encode(['success'=>true,'message'=>'Ak je e-mail registrovaný, obdržíte overovací e-mail.']);
        exit;
    }
    $userId = (int)$row['id'];

    // create verification record + send email (similarly ako v register)
    $selector = bin2hex(random_bytes(6));
    $validator = bin2hex(random_bytes(32));
    $key = KeyManager::getPasswordPepperInfo()['raw'] ?? random_bytes(32);
    $vhash = hash_hmac('sha256',$validator, $key, true);
    $tokenHashHex = hash('sha256', $selector . $validator);
    $ins = $db->prepare('INSERT INTO email_verifications (user_id, token_hash, selector, validator_hash, key_version, expires_at) VALUES (:uid,:token,:sel,:vhash,:kv, DATE_ADD(NOW(), INTERVAL 1 HOUR))');
    $ins->execute([':uid'=>$userId,':token'=>$tokenHashHex,':sel'=>$selector,':vhash'=>$vhash,':kv'=>KeyManager::getCurrentPasswordPepperVersion() ?? 0]);

    if (class_exists('EmailTemplates') && class_exists('Mailer')) {
        $verifyUrl = (string)($_ENV['APP_BASE_URL'] ?? '') . '/eshop/verify.php?selector=' . rawurlencode($selector) . '&validator=' . rawurlencode($validator);
        $payload = ['to' => $email, 'subject' => 'Potvrďte e-mail', 'template' => 'verify_email.php', 'vars' => ['verify_url'=>$verifyUrl]];
        try { Mailer::enqueue($payload); } catch (\Throwable $e) { Logger::systemError($e); }
    }

    echo json_encode(['success'=>true,'message'=>'Ak je e-mail registrovaný, obdržíte overovací e-mail.']);
    exit;

} catch (\Throwable $e) {
    if (class_exists('Logger')) Logger::systemError($e);
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Serverová chyba.']);
    exit;
}
<?php
declare(strict_types=1);
// /www/eshop/actions/reset_password.php
// Potvrdenie resetu hesla (POST) - očakáva selector, validator, new_password, new_password_confirm

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

    $selector = (string)($_POST['selector'] ?? '');
    $validator = (string)($_POST['validator'] ?? '');
    $pw = (string)($_POST['new_password'] ?? '');
    $pw2 = (string)($_POST['new_password_confirm'] ?? '');

    if ($pw !== $pw2) {
        echo json_encode(['success'=>false,'message'=>'Heslá sa nezhodujú.']);
        exit;
    }
    if (!class_exists('Validator') || !Validator::validatePassword($pw)) {
        echo json_encode(['success'=>false,'message'=>'Heslo nespĺňa pravidlá.']);
        exit;
    }

    $db = Database::getInstance()->getPdo();
    $q = $db->prepare('SELECT * FROM email_verifications WHERE selector = :sel AND used_at IS NULL AND expires_at > NOW() LIMIT 1');
    $q->execute([':sel'=>$selector]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        echo json_encode(['success'=>false,'message'=>'Neplatný alebo expirovaný token.']);
        exit;
    }

    // overenie validatoru: porovnanie hash(validator) so stored validator_hash (raw binary)
    $storedVHash = $row['validator_hash'];
    $key = KeyManager::getPasswordPepperInfo()['raw'] ?? null;
    if ($key === null) {
        echo json_encode(['success'=>false,'message'=>'Serverová chyba.']);
        exit;
    }
    $calc = hash_hmac('sha256', $validator, $key, true);
    // compare raw binary
    if (!hash_equals($storedVHash, $calc)) {
        echo json_encode(['success'=>false,'message'=>'Neplatný token.']);
        exit;
    }

    $userId = (int)$row['user_id'];

    // zmena hesla: použiť Auth::hashPassword / Auth::setPassword
    if (!class_exists('Auth')) throw new RuntimeException('Auth missing.');
    // Predpokladaná func: Auth::setPassword($userId, $newPassword)
    $ok = Auth::setPassword($userId, $pw); // ak neexistuje, implementujte pomocou KeyManager + password_hash
    if (!$ok) {
        echo json_encode(['success'=>false,'message'=>'Nepodarilo sa nastaviť nové heslo.']);
        exit;
    }

    // označiť token ako použitý
    $u = $db->prepare('UPDATE email_verifications SET used_at = NOW() WHERE id = :id');
    $u->execute([':id' => $row['id']]);

    if (class_exists('Logger')) Logger::auth('password_reset', $userId, ['method'=>'reset_by_email']);

    echo json_encode(['success'=>true,'message'=>'Heslo bolo úspešne zmenené. Prihláste sa prosím.']);
    exit;

} catch (\Throwable $e) {
    if (class_exists('Logger')) Logger::systemError($e);
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Serverová chyba.']);
    exit;
}
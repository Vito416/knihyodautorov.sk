<?php
declare(strict_types=1);
// /www/eshop/actions/change_password.php
// Zmena hesla pre prihláseného používateľa (POST) - očakáva: current_password, new_password, new_password_confirm

require_once __DIR__ . '/../../bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success'=>false]); exit; }
    if (!class_exists('CSRF')) throw new RuntimeException('CSRF missing.');
    if (!CSRF::validate($_POST['csrf'] ?? null)) { echo json_encode(['success'=>false,'message'=>'Neplatný CSRF']); exit; }

    // Kontrola prihlásenia - predpoklad: SessionManager::currentUserId()
    if (!class_exists('SessionManager') || !method_exists('SessionManager','currentUserId')) {
        echo json_encode(['success'=>false,'message'=>'Autentifikácia nie je dostupná.']);
        exit;
    }
    $userId = SessionManager::currentUserId();
    if (!$userId) { echo json_encode(['success'=>false,'message'=>'Nie ste prihlásený.']); exit; }

    $current = (string)($_POST['current_password'] ?? '');
    $new = (string)($_POST['new_password'] ?? '');
    $new2 = (string)($_POST['new_password_confirm'] ?? '');
    if ($new !== $new2) { echo json_encode(['success'=>false,'message'=>'Heslá sa nezhodujú.']); exit; }
    if (!class_exists('Validator') || !Validator::validatePassword($new)) { echo json_encode(['success'=>false,'message'=>'Nové heslo nespĺňa pravidlá.']); exit; }

    if (!class_exists('Auth')) throw new RuntimeException('Auth missing.');
    // Predpoklad: Auth::verifyPassword($userId, $password) -> bool
    if (!Auth::verifyPassword($userId, $current)) {
        echo json_encode(['success'=>false,'message'=>'Aktuálne heslo nie je správne.']);
        exit;
    }

    $ok = Auth::setPassword($userId, $new);
    if (!$ok) { echo json_encode(['success'=>false,'message'=>'Nepodarilo sa zmeniť heslo.']); exit; }

    if (class_exists('Logger')) Logger::auth('password_change', $userId);

    echo json_encode(['success'=>true,'message'=>'Heslo bolo zmenené.']);
    exit;

} catch (\Throwable $e) {
    if (class_exists('Logger')) Logger::systemError($e);
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Serverová chyba.']);
    exit;
}
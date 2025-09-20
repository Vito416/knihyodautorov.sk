<?php
declare(strict_types=1);
// /www/eshop/actions/login.php
// Prihlásenie (POST) - očakáva: email, password, remember (optional)

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
    $password = (string)($_POST['password'] ?? '');
    $remember = (!empty($_POST['remember'])) ? true : false;

    if (!class_exists('Validator') || !Validator::validateEmail($email)) {
        echo json_encode(['success'=>false,'message'=>'Neplatný e-mail alebo heslo.']);
        exit;
    }

    if (!class_exists('Auth')) throw new RuntimeException('Auth missing.');

    // Predpoklad: Auth::login($email, $password, $opts = []): array { success, user_id, errors, session_token? }
    $res = Auth::login($email, $password, ['remember' => $remember]);

    if (!is_array($res) || empty($res['success'])) {
        // logovanie neúspešného pokusu
        if (class_exists('Logger')) Logger::auth('login_failure', null, ['email' => $email]);
        echo json_encode(['success'=>false,'message'=>$res['message'] ?? 'Prihlásenie zlyhalo.']);
        exit;
    }

    // úspech
    $userId = $res['user_id'] ?? null;
    if (class_exists('Logger')) Logger::auth('login_success', $userId, ['ip'=>$_SERVER['REMOTE_ADDR'] ?? null]);

    // Ak Auth nevytvoril session/cookie, môžeme použiť SessionManager
    if (empty($res['session_created']) && class_exists('SessionManager')) {
        SessionManager::create($userId, ['remember' => $remember]); // predpokladaná signatúra
    }

    echo json_encode(['success'=>true,'message'=>'Prihlásenie prebehlo úspešne.']);
    exit;

} catch (\Throwable $e) {
    if (class_exists('Logger')) Logger::systemError($e);
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Serverová chyba.']);
    exit;
}
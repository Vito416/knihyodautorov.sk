<?php
declare(strict_types=1);
// /www/eshop/actions/logout.php
// Logout (POST)

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

    // Ak máte SessionManager, použite ho
    if (class_exists('SessionManager')) {
        SessionManager::destroyCurrentSession();
    } else {
        // fallback: klasické vymazanie PHP session
        session_start();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'], $params['secure'], $params['httponly']
            );
        }
        session_destroy();
    }

    echo json_encode(['success'=>true,'message'=>'Odhlásenie úspešné.']);
    exit;

} catch (\Throwable $e) {
    if (class_exists('Logger')) Logger::systemError($e);
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Serverová chyba.']);
    exit;
}
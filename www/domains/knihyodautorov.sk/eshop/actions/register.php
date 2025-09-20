<?php
declare(strict_types=1);
// /www/eshop/actions/register.php
// Registrácia používateľa (POST)
// Očakáva: email, password, password_confirm, (optionálne: full_name)

require_once __DIR__ . '/../../bootstrap.php'; // alebo cesta k vašemu bootstrapu
// bootstrap musí inicializovať Database, KeyManager, CSRF, Logger, Auth, Validator, EmailTemplates, Mailer

header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Metóda nenájdená. Použite POST.']);
        exit;
    }

    if (!class_exists('CSRF')) throw new RuntimeException('CSRF knižnica chýba.');
    if (!CSRF::validate($_POST['csrf'] ?? null)) {
        echo json_encode(['success' => false, 'message' => 'Neplatný CSRF token.']);
        exit;
    }

    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $passwordConfirm = (string)($_POST['password_confirm'] ?? '');
    $fullName = trim((string)($_POST['full_name'] ?? ''));

    // Validácia
    $errors = [];
    if (!class_exists('Validator') || !Validator::validateEmail($email)) {
        $errors['email'] = 'Neplatný e-mail.';
    }
    if (!class_exists('Validator') || !Validator::validatePassword($password)) {
        $errors['password'] = 'Heslo nespĺňa požiadavky.';
    }
    if ($password !== $passwordConfirm) {
        $errors['password_confirm'] = 'Heslá sa nezhodujú.';
    }
    if (!empty($errors)) {
        echo json_encode(['success' => false, 'errors' => $errors]);
        exit;
    }

    // použijeme Auth knižnicu ak existuje
    if (!class_exists('Auth')) {
        throw new RuntimeException('Auth knižnica chýba.');
    }

    // predpoklad: Auth::register(array $data): array {success,user_id,errors}
    $regData = [
        'email' => $email,
        'password' => $password,
        'full_name' => $fullName,
    ];

    $result = Auth::register($regData); // dokumentácia uvádza Auth knižnicu — upraví sa keď treba

    if (!is_array($result) || empty($result['success'])) {
        // ak Auth neposkytuje detaily, pokúsime sa vyradiť chyby
        $msg = $result['message'] ?? 'Registrácia zlyhala.';
        if (!empty($result['errors'])) {
            echo json_encode(['success' => false, 'errors' => $result['errors']]);
        } else {
            echo json_encode(['success' => false, 'message' => $msg]);
        }
        exit;
    }

    $userId = (int)$result['user_id'];

    // Vytvoriť email verification záznam (ak sa Auth nepostará)
    // Predpokladáme, že Auth môže vyrobiť aj záznam v email_verifications, ale ak nie:
    if (function_exists('createEmailVerificationForUser')) {
        createEmailVerificationForUser($userId, $email);
    } else {
        // fallback: pokus o vloženie a zaslanie e-mailu cez Mailer + EmailTemplates
        if (class_exists('EmailTemplates') && class_exists('Mailer')) {
            // vytvoríme jednoduchú notifikáciu typu verify_email
            $tokenData = bin2hex(random_bytes(16));
            // V produkcii: uložte do email_verifications so selector/validator/hmac atď. (pokročilé)
            // Tu len pošleme e-mail s linkom (implementujte bezpečne na produkcii)
            $verifyUrl = (string)($_ENV['APP_BASE_URL'] ?? '') . '/eshop/verify.php?token=' . rawurlencode($tokenData);
            $payload = [
                'to' => $email,
                'subject' => 'Potvrďte váš e-mail',
                'template' => 'verify_email.php',
                'vars' => ['verify_url' => $verifyUrl, 'name' => $fullName],
            ];
            try {
                Mailer::enqueue($payload);
            } catch (\Throwable $e) {
                Logger::systemError($e);
            }
        }
    }

    Logger::info('register_success', null, ['user_id' => $userId]);
    echo json_encode(['success' => true, 'message' => 'Registrácia úspešná. Skontrolujte e-mail pre potvrdenie.']);
    exit;

} catch (\Throwable $e) {
    if (class_exists('Logger')) Logger::systemError($e);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Serverová chyba.']);
    exit;
}
<?php
declare(strict_types=1);
// /www/eshop/actions/verify_email.php
// Potvrdenie e-mailu (GET) - očakáva token alebo selector+validator
// Tento súbor môže načítať šablónu výsledku; tu vrátime JSON (AJAX) alebo redirect.

require_once __DIR__ . '/../../bootstrap.php';

try {
    // Podporujeme GET link s selector+validator
    $selector = (string)($_GET['selector'] ?? '');
    $validator = (string)($_GET['validator'] ?? '');
    if ($selector === '' || $validator === '') {
        // zobraziť stránku s chybou
        header('Content-Type: text/html; charset=utf-8');
        echo Templates::render('eshop/emails/verify_result.php', ['success' => false, 'message' => 'Neplatný odkaz.']);
        exit;
    }

    $db = Database::getInstance()->getPdo();
    $q = $db->prepare('SELECT * FROM email_verifications WHERE selector = :sel AND used_at IS NULL AND expires_at > NOW() LIMIT 1');
    $q->execute([':sel'=>$selector]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        echo Templates::render('eshop/emails/verify_result.php', ['success' => false, 'message' => 'Token expiroval alebo je neplatný.']);
        exit;
    }

    $key = KeyManager::getPasswordPepperInfo()['raw'] ?? null;
    if ($key === null) {
        echo Templates::render('eshop/emails/verify_result.php', ['success' => false, 'message' => 'Server error.']);
        exit;
    }

    $calc = hash_hmac('sha256', $validator, $key, true);
    if (!hash_equals($row['validator_hash'], $calc)) {
        echo Templates::render('eshop/emails/verify_result.php', ['success' => false, 'message' => 'Neplatný token.']);
        exit;
    }

    // označíme používateľa ako aktívneho
    $u = $db->prepare('UPDATE pouzivatelia SET is_active = 1 WHERE id = :id');
    $u->execute([':id' => $row['user_id']]);
    $db->prepare('UPDATE email_verifications SET used_at = NOW() WHERE id = :id')->execute([':id'=>$row['id']]);

    if (class_exists('Logger')) Logger::auth('verify_success', $row['user_id']);

    echo Templates::render('eshop/emails/verify_result.php', ['success' => true, 'message' => 'E-mail bol úspešne potvrdený.']);
    exit;

} catch (\Throwable $e) {
    if (class_exists('Logger')) Logger::systemError($e);
    echo Templates::render('eshop/emails/verify_result.php', ['success' => false, 'message' => 'Serverová chyba.']);
    exit;
}
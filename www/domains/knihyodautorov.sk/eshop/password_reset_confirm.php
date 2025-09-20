<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';

/**
 * password_reset_confirm.php
 *
 * Krok 2: Uživatel přijde s odkazem (selector + validator).
 * - zkontroluje se token
 * - pokud OK, zobrazí formulář pro nové heslo
 * - po POSTu změní heslo v DB, zneplatní token a přihlásí uživatele
 */

$selector = $_GET['selector'] ?? $_POST['selector'] ?? '';
$validatorHex = $_GET['validator'] ?? $_POST['validator'] ?? '';

if ($selector === '' || $validatorHex === '' || !ctype_xdigit($validatorHex)) {
    echo Templates::render('pages/password_reset_confirm.php', ['status' => 'invalid']);
    exit;
}

$validator = hex2bin($validatorHex);
if ($validator === false) {
    echo Templates::render('pages/password_reset_confirm.php', ['status' => 'invalid']);
    exit;
}

try {
    $db = Database::getInstance();

    // Najít token
    $stmt = $db->prepare("SELECT ev.id, ev.user_id, ev.validator_hash, ev.expires_at, ev.used_at, u.is_locked
                          FROM email_verifications ev
                          JOIN pouzivatelia u ON u.id = ev.user_id
                          WHERE ev.selector = :selector
                          LIMIT 1");
    $stmt->bindValue(':selector', $selector, \PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);

    if (!$row) {
        echo Templates::render('pages/password_reset_confirm.php', ['status' => 'invalid']);
        exit;
    }

    if ($row['used_at'] !== null) {
        echo Templates::render('pages/password_reset_confirm.php', ['status' => 'already_used']);
        exit;
    }

    $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    $exp = new \DateTimeImmutable($row['expires_at'], new \DateTimeZone('UTC'));
    if ($exp < $now) {
        echo Templates::render('pages/password_reset_confirm.php', ['status' => 'expired']);
        exit;
    }

    // Ověřit validator
    $calcHash = hash('sha256', $validator, true);
    if (!hash_equals($row['validator_hash'], $calcHash)) {
        echo Templates::render('pages/password_reset_confirm.php', ['status' => 'invalid']);
        exit;
    }

    // Pokud POST -> změna hesla
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $password = $_POST['password'] ?? '';
        $password2 = $_POST['password2'] ?? '';

        if ($password === '' || $password !== $password2) {
            echo Templates::render('pages/password_reset_confirm.php', [
                'status' => 'form_error',
                'selector' => $selector,
                'validator' => $validatorHex,
            ]);
            exit;
        }

        // Hash hesla
        $hash = password_hash($password, PASSWORD_DEFAULT);

        $db->beginTransaction();
        $upd1 = $db->prepare("UPDATE pouzivatelia SET heslo_hash = :hash, must_change_password = 0, updated_at = NOW() WHERE id = :uid");
        $upd1->bindValue(':hash', $hash, \PDO::PARAM_STR);
        $upd1->bindValue(':uid', (int)$row['user_id'], \PDO::PARAM_INT);
        $upd1->execute();

        $upd2 = $db->prepare("UPDATE email_verifications SET used_at = NOW() WHERE id = :id");
        $upd2->bindValue(':id', (int)$row['id'], \PDO::PARAM_INT);
        $upd2->execute();
        $db->commit();

        if (class_exists('Logger')) {
            try { Logger::systemMessage('info', 'password_reset_success', (int)$row['user_id']); } catch (\Throwable $_) {}
        }

        // Automaticky přihlásit
        try {
            SessionManager::createSession($db, (int)$row['user_id']);
        } catch (\Throwable $_) {}

        echo Templates::render('pages/password_reset_confirm.php', ['status' => 'success']);
        exit;
    }

    // GET -> zobrazit formulář
    echo Templates::render('pages/password_reset_confirm.php', [
        'status' => 'form',
        'selector' => $selector,
        'validator' => $validatorHex,
    ]);
    exit;

} catch (\Throwable $e) {
    if (class_exists('Logger')) { try { Logger::systemError($e); } catch (\Throwable $_) {} }
    echo Templates::render('pages/password_reset_confirm.php', ['status' => 'error']);
    exit;
}
<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';

/**
 * password_reset.php
 *
 * Krok 1: Uživatel zadá e-mail.
 * - pokud existuje, vygeneruje se reset token (selector + validator)
 * - uloží se do tabulky email_verifications (případně samostatné reset_tokens, zde reuse)
 * - odešle se e-mail s odkazem
 * - bezpečnostně: vždy vrací "pokud e-mail existuje, odeslali jsme odkaz"
 */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim((string)($_POST['email'] ?? '')));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo Templates::render('pages/password_reset.php', ['message' => 'Pokud e-mail existuje, poslali jsme odkaz.']);
        exit;
    }

    try {
        $db = Database::getInstance();

        // Najít uživatele podle e-mailu (hash porovnání)
        $emailHash = null;
        try {
            $h = KeyManager::deriveHmacWithLatest('EMAIL_HASH_KEY', KEYS_DIR, 'email_hash_key', $email);
            $emailHash = $h['hash'] ?? null;
        } catch (\Throwable $_) {}

        $user = null;
        if ($emailHash) {
            $stmt = $db->prepare("SELECT id, is_active, is_locked FROM pouzivatelia WHERE email_hash = :email_hash LIMIT 1");
            $stmt->bindValue(':email_hash', $emailHash, \PDO::PARAM_LOB);
            $stmt->execute();
            $user = $stmt->fetch(\PDO::FETCH_ASSOC);
        }

        if ($user && (int)$user['is_active'] === 1 && (int)$user['is_locked'] === 0) {
            // Token
            $selector = bin2hex(random_bytes(6));
            $validator = random_bytes(32);
            $validatorHash = hash('sha256', $validator, true);
            $expiresAt = (new \DateTimeImmutable('+1 hour'))->format('Y-m-d H:i:s.u');

            $stmt = $db->prepare("INSERT INTO email_verifications
                (user_id, token_hash, selector, validator_hash, key_version, expires_at, created_at)
                VALUES (:uid, :token_hash, :selector, :validator_hash, 0, :expires_at, NOW())");
            $stmt->bindValue(':uid', (int)$user['id'], \PDO::PARAM_INT);
            $stmt->bindValue(':token_hash', hash('sha256', $validator), \PDO::PARAM_STR);
            $stmt->bindValue(':selector', $selector, \PDO::PARAM_STR);
            $stmt->bindValue(':validator_hash', $validatorHash, \PDO::PARAM_LOB);
            $stmt->bindValue(':expires_at', $expiresAt, \PDO::PARAM_STR);
            $stmt->execute();

            // E-mail
            if (class_exists('Mailer')) {
                $resetUrl = $_ENV['BASE_URL'] . "/password_reset_confirm.php?selector={$selector}&validator=" . bin2hex($validator);
                Mailer::send($email, 'Obnovení hesla', Templates::render('emails/password_reset.php', [
                    'reset_url' => $resetUrl,
                ]));
            }

            if (class_exists('Logger')) {
                try { Logger::systemMessage('info', 'password_reset_requested', (int)$user['id'], ['selector' => $selector]); } catch (\Throwable $_) {}
            }
        }

        echo Templates::render('pages/password_reset_sent.php', ['email' => $email]);
        exit;
    } catch (\Throwable $e) {
        if (class_exists('Logger')) { try { Logger::systemError($e); } catch (\Throwable $_) {} }
        echo Templates::render('pages/password_reset.php', ['message' => 'Pokud e-mail existuje, poslali jsme odkaz.']);
        exit;
    }
}

// GET => formulář
echo Templates::render('pages/password_reset.php', ['message' => null]);
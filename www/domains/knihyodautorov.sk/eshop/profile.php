<?php
declare(strict_types=1);

/** @var Database|\PDO $db */
/** @var int|null $currentUserId */

// --- load user ---
if ($currentUserId === null) {
    http_response_code(403);
    echo "Access denied";
    exit;
}

$stmt = $db->prepare("SELECT id, email, display_name FROM users WHERE id = :id");
$stmt->execute([':id' => $currentUserId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    http_response_code(404);
    echo "User not found";
    exit;
}

// --- CSRF token ---
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// --- helper ---
function checkCsrf(string $tokenFromForm, string $tokenSession): bool {
    return hash_equals($tokenSession, $tokenFromForm);
}

// --- update name ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_name') {
    if (!checkCsrf($_POST['csrf_token'] ?? '', $csrf_token)) {
        addFlash('error', 'Neplatný bezpečnostný token.');
    } else {
        $displayNameNew = trim((string)($_POST['display_name'] ?? ''));
        if ($displayNameNew === '') {
            addFlash('error', 'Meno nesmie byť prázdne.');
        } else {
            try {
                $stmt = $db->prepare("UPDATE user_profiles SET full_name = :fn, updated_at = NOW() WHERE user_id = :id");
                $stmt->execute([':fn' => $displayNameNew, ':id' => $user['id']]);
                if ($stmt->rowCount() === 0) {
                    $ins = $db->prepare("INSERT INTO user_profiles (user_id, full_name, updated_at) VALUES (:id, :fn, NOW())");
                    $ins->execute([':id' => $user['id'], ':fn' => $displayNameNew]);
                }
                $user['display_name'] = $displayNameNew;
                addFlash('success', 'Meno úspešne aktualizované.');
            } catch (\Throwable $e) {
                addFlash('error', 'Nepodarilo sa uložiť meno.');
                if (class_exists('Logger')) { try { Logger::systemError($e, $user['id']); } catch (\Throwable $_) {} }
            }
        }
    }
}

// --- update email ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_email') {
    if (!checkCsrf($_POST['csrf_token'] ?? '', $csrf_token)) {
        addFlash('error', 'Neplatný bezpečnostný token.');
    } else {
        $emailNew = strtolower(trim((string)($_POST['email'] ?? '')));
        if (!filter_var($emailNew, FILTER_VALIDATE_EMAIL)) {
            addFlash('error', 'Neplatný formát e-mailu.');
        } else {
            try {
                $check = $db->prepare("SELECT id FROM users WHERE email = :em AND id != :id");
                $check->execute([':em' => $emailNew, ':id' => $user['id']]);
                if ($check->fetch()) addFlash('warning', 'Tento e-mail už je používaný.');
                else {
                    $stmt = $db->prepare("UPDATE users SET email = :em, updated_at = NOW() WHERE id = :id");
                    $stmt->execute([':em' => $emailNew, ':id' => $user['id']]);
                    $user['email'] = $emailNew;
                    addFlash('success', 'E-mail úspešne aktualizovaný.');
                }
            } catch (\Throwable $e) {
                addFlash('error', 'Nepodarilo sa uložiť e-mail.');
                if (class_exists('Logger')) { try { Logger::systemError($e, $user['id']); } catch (\Throwable $_) {} }
            }
        }
    }
}

// --- update password ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_password') {
    if (!checkCsrf($_POST['csrf_token'] ?? '', $csrf_token)) {
        addFlash('error', 'Neplatný bezpečnostný token.');
    } else {
        $oldPass  = (string)($_POST['old_password'] ?? '');
        $newPass  = (string)($_POST['new_password'] ?? '');
        $newPass2 = (string)($_POST['new_password2'] ?? '');

        if ($newPass === '' || $newPass2 === '') addFlash('error', 'Nové heslo nesmie byť prázdne.');
        elseif ($newPass !== $newPass2) addFlash('error', 'Nové heslá sa nezhodujú.');
        elseif (strlen($newPass) < 8) addFlash('warning', 'Heslo musí mať aspoň 8 znakov.');
        else {
            try {
                $stmt = $db->prepare("SELECT password_hash FROM users WHERE id = :id");
                $stmt->execute([':id' => $user['id']]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$row || !password_verify($oldPass, $row['password_hash'])) addFlash('error', 'Staré heslo nesedí.');
                else {
                    $newHash = password_hash($newPass, PASSWORD_DEFAULT);
                    $upd = $db->prepare("UPDATE users SET password_hash = :ph, updated_at = NOW() WHERE id = :id");
                    $upd->execute([':ph' => $newHash, ':id' => $user['id']]);
                    addFlash('success', 'Heslo úspešne aktualizované.');
                }
            } catch (\Throwable $e) {
                addFlash('error', 'Nepodarilo sa uložiť heslo.');
                if (class_exists('Logger')) { try { Logger::systemError($e, $user['id']); } catch (\Throwable $_) {} }
            }
        }
    }
}

// --- render ---
echo Templates::render('pages/profile.php', [
    'user' => $user,
    'csrf_token' => $csrf_token,
]);
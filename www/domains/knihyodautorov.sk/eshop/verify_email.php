<?php
require __DIR__ . '/inc/bootstrap.php';
if (!function_exists('e')) { function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); } }

$uid = isset($_GET['uid']) ? (int)$_GET['uid'] : 0;
$token = $_GET['token'] ?? '';

$msg = 'Neplatný alebo expirovaný odkaz.';

if ($uid && $token) {
    $tokenHash = hash('sha256', $token);
    $stmt = $db->prepare('SELECT id, expires_at, used_at FROM email_verifications WHERE user_id = ? AND token_hash = ? LIMIT 1');
    $stmt->execute([$uid, $tokenHash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        if ($row['used_at'] !== null) {
            $msg = 'Tento odkaz už bol použitý.';
        } elseif (new DateTime($row['expires_at']) < new DateTime()) {
            $msg = 'Odkaz vypršal.';
        } else {
            // activate user
            $db->beginTransaction();
            try {
                $db->prepare('UPDATE pouzivatelia SET is_active = 1, updated_at = NOW() WHERE id = ?')
                   ->execute([$uid]);

                $db->prepare('UPDATE email_verifications SET used_at = NOW() WHERE id = ?')
                   ->execute([$row['id']]);

                // optional: log auth_event
                $db->prepare('INSERT INTO auth_events (user_id, type, ip, user_agent, occurred_at) VALUES (?, ?, ?, ?, NOW())')
                   ->execute([$uid, 'login_success', $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '']);

                $db->commit();
                $msg = 'E-mail bol potvrdený. Môžete sa prihlásiť.';
            } catch (Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                error_log('[verify_email] ' . $e->getMessage());
                $msg = 'Došlo k chybe. Skúste neskôr.';
            }
        }
    }
}
?>
<!doctype html>
<html lang="sk">
<head><meta charset="utf-8"><title>Overenie e-mailu</title></head>
<body>
  <h1>Overenie e-mailu</h1>
  <p><?= e($msg) ?></p>
  <p><a href="login.php">Prihlásiť sa</a></p>
</body>
</html>
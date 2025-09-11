<?php
require __DIR__ . '/inc/bootstrap.php';
if (!function_exists('e')) { function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); } }

$err = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $err = 'Neplatný email.';
    } else {
        // find user
        $u = $db->prepare('SELECT id, is_active FROM pouzivatelia WHERE email = ? LIMIT 1');
        $u->execute([$email]);
        $row = $u->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            // don't reveal existence
            $success = 'Ak účet existuje, overovací e-mail bude zaslaný.';
        } else {
            $uid = (int)$row['id'];
            if ((int)$row['is_active'] === 1) {
                $success = 'Účet už je aktívny.';
            } else {
                // simple rate limit: poslední token vytvořen v posledních 30 minutách?
                $r = $db->prepare('SELECT created_at FROM email_verifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 1');
                $r->execute([$uid]);
                $last = $r->fetchColumn();
                if ($last && (new DateTime($last) > (new DateTime('-30 minutes')))) {
                    $success = 'Posledný overovací e-mail bol zaslaný pred menej než 30 minútami. Skúste neskôr.';
                } else {
                    // create token + notification (re-using logic)
                    $tokenRaw = bin2hex(random_bytes(32));
                    $tokenHash = hash('sha256', $tokenRaw);
                    $expiresAt = (new DateTime('+7 days'))->format('Y-m-d H:i:s');

                    $db->beginTransaction();
                    try {
                        $db->prepare('INSERT INTO email_verifications (user_id, token_hash, expires_at, key_version, created_at) VALUES (?, ?, ?, ?, NOW())')
                           ->execute([$uid, $tokenHash, $expiresAt, 0]);

                        $base = rtrim(defined('APP_URL') ? APP_URL : 'https://example.com', '/');
                        $verifyUrl = $base . '/verify_email.php?uid=' . $uid . '&token=' . $tokenRaw;
                        $payloadArr = [
                            'to' => $email,
                            'subject' => sprintf('%s: potvrďte svoj e-mail', defined('APP_NAME') ? APP_NAME : 'Naša služba'),
                            'template' => 'verify_email',
                            'vars' => [
                                'verify_url' => $verifyUrl,
                                'expires_at' => $expiresAt,
                                'site' => defined('APP_NAME') ? APP_NAME : null
                            ]
                        ];
                        $payload = json_encode($payloadArr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                        $nins = $db->prepare('INSERT INTO notifications (user_id, channel, template, payload, status, scheduled_at, created_at, retries, max_retries)
                                              VALUES (?, ?, ?, ?, ?, NOW(), NOW(), 0, ?)');
                        $nins->execute([$uid, 'email', 'verify_email', $payload, 'pending', 6]);

                        $db->commit();
                        $success = 'Overovací e-mail bol naplánovaný k odoslaniu.';
                    } catch (Exception $e) {
                        if ($db->inTransaction()) $db->rollBack();
                        error_log('[resend_verif] ' . $e->getMessage());
                        $err = 'Nepodarilo sa naplánovať overovací e-mail. Skúste neskôr.';
                    }
                }
            }
        }
    }
}
?>
<!doctype html>
<html lang="sk">
<head><meta charset="utf-8"><title>Poslať overovací e-mail znova</title></head>
<body>
  <h1>Poslať overovací e-mail znova</h1>
  <?php if ($err): ?><p style="color:red"><?= e($err) ?></p><?php endif; ?>
  <?php if ($success): ?><p style="color:green"><?= e($success) ?></p><?php endif; ?>
  <form method="post">
    <label>Email: <input type="email" name="email" required></label>
    <button type="submit">Poslať</button>
  </form>
</body>
</html>
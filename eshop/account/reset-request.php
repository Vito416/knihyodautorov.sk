<?php
declare(strict_types=1);
/**
 * /eshop/account/reset-request.php
 * Pošle resetovací e-mail s tokenom.
 * POST: _csrf (kľúč 'auth'), email
 *
 * Ukladá do users.reset_token hodnotu token|expiry (expiry = time + 3600*2)
 */

require_once __DIR__ . '/../_init.php';
$pdoLocal = $GLOBALS['pdo'] ?? null;
if (!($pdoLocal instanceof PDO)) {
    eshop_log('ERROR','PDO nie je dostupné v account/reset-request.php');
    flash_set('error','Interná chyba.');
    redirect('/eshop/');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify_token($_POST['_csrf'] ?? null, 'auth')) {
        flash_set('error','Neplatný CSRF token.');
        redirect('/eshop/account/reset-request.php');
    }

    $email = filter_var(trim((string)($_POST['email'] ?? '')), FILTER_VALIDATE_EMAIL);
    if ($email === false) {
        flash_set('error','Zadajte platný email.');
        redirect('/eshop/account/reset-request.php');
    }

    try {
        $stmt = $pdoLocal->prepare("SELECT id, meno FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            // Nebudeme prezrádzať existenciu účtu — len logujeme a zobrazíme neutrálne hlásenie
            eshop_log('WARN',"Reset hesla požiadavka pre neexistujúci email {$email}");
            flash_set('success','Ak tento email existuje v našej databáze, poslali sme inštrukcie na obnovu hesla.');
            redirect('/eshop/account/login.php');
        }

        $token = bin2hex(random_bytes(20));
        $expiry = time() + 60 * 60 * 2; // 2 hodiny
        $stored = $token . '|' . $expiry;

        $stmt = $pdoLocal->prepare("UPDATE users SET reset_token = ? WHERE id = ?");
        $stmt->execute([$stored, (int)$user['id']]);

        // send email
        try {
            if (class_exists('\PHPMailer\PHPMailer\PHPMailer')) {
                $smtpCfg = $GLOBALS['smtp'] ?? null;
                $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
                if (is_array($smtpCfg)) {
                    $mail->isSMTP();
                    $mail->Host = $smtpCfg['host'] ?? '';
                    $mail->Port = $smtpCfg['port'] ?? 587;
                    $mail->SMTPAuth = true;
                    $mail->Username = $smtpCfg['username'] ?? '';
                    $mail->Password = $smtpCfg['password'] ?? '';
                    if (!empty($smtpCfg['secure'])) $mail->SMTPSecure = $smtpCfg['secure'];
                    $fromEmail = $smtpCfg['from_email'] ?? ($smtpCfg['username'] ?? 'no-reply@localhost');
                    $fromName  = $smtpCfg['from_name'] ?? 'Knihy od Autorov';
                    $mail->setFrom($fromEmail, $fromName);
                }
                $mail->addAddress($email);
                $mail->isHTML(true);
                $resetUrl = site_base_url() . '/eshop/account/reset-confirm.php?token=' . rawurlencode($token) . '&uid=' . $user['id'];
                $mail->Subject = 'Obnova hesla — Knihy od Autorov';
                $mail->Body = "<p>Dobrý deň, {$user['meno']}.</p><p>Pre obnovu hesla kliknite na odkaz: <a href=\"{$resetUrl}\">Obnoviť heslo</a></p><p>Odkaz je platný 2 hodiny.</p>";
                $mail->send();
                eshop_log('INFO',"Reset email odoslaný pre user_id={$user['id']}");
            } else {
                eshop_log('WARN','PHPMailer neexistuje — reset email neodoslaný.');
            }
        } catch (Throwable $e) {
            eshop_log('ERROR','Chyba pri odoslaní reset emailu: '.$e->getMessage());
        }

        flash_set('success','Ak tento email existuje v našej databáze, poslali sme inštrukcie na obnovu hesla.');
        redirect('/eshop/account/login.php');

    } catch (Throwable $e) {
        eshop_log('ERROR','Chyba pri reset-request: '.$e->getMessage());
        flash_set('error','Chyba pri spracovaní požiadavky.');
        redirect('/eshop/account/reset-request.php');
    }
}

// GET -> zobraz form
?>
<!doctype html>
<html lang="sk">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Obnova hesla</title><link rel="stylesheet" href="/eshop/css/eshop.css"></head>
<body>
  <div class="wrap paper-wrap">
    <h1>Obnova hesla</h1>
    <?php foreach (flash_all() as $m) echo '<div class="note">'.htmlspecialchars((string)$m,ENT_QUOTES|ENT_HTML5).'</div>'; ?>
    <form method="post" action="">
      <?php csrf_field('auth'); ?>
      <p><label>Email:<br><input type="email" name="email" required></label></p>
      <p><button class="btn" type="submit">Odoslať inštrukcie</button></p>
    </form>
    <p><a href="/eshop/account/login.php">Späť na prihlásenie</a></p>
  </div>
</body>
</html>
<?php
declare(strict_types=1);
/**
 * /eshop/account/register.php
 * Registrácia používateľa.
 * POST: _csrf (kľúč 'auth'), meno, email, heslo, heslo_confirm
 */

require_once __DIR__ . '/../_init.php';

$pdoLocal = $GLOBALS['pdo'] ?? null;
if (!($pdoLocal instanceof PDO)) {
    eshop_log('ERROR', 'PDO nie je dostupné v account/register.php');
    flash_set('error', 'Interná chyba (DB).');
    redirect('/eshop/');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify_token($_POST['_csrf'] ?? null, 'auth')) {
        flash_set('error', 'Neplatný CSRF token.');
        redirect('/eshop/account/register.php');
    }

    $meno = trim((string)($_POST['meno'] ?? ''));
    $email = filter_var(trim((string)($_POST['email'] ?? '')), FILTER_VALIDATE_EMAIL);
    $heslo = (string)($_POST['heslo'] ?? '');
    $heslo2 = (string)($_POST['heslo_confirm'] ?? '');

    if ($meno === '' || $email === false || $heslo === '' || $heslo !== $heslo2) {
        flash_set('error', 'Skontrolujte vyplnené polia a heslá.');
        redirect('/eshop/account/register.php');
    }

    try {
        // existuje email?
        $stmt = $pdoLocal->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            flash_set('error', 'Užívateľ s týmto emailom už existuje.');
            redirect('/eshop/account/register.php');
        }

        $pwHash = password_hash($heslo, PASSWORD_DEFAULT);
        $verifyToken = bin2hex(random_bytes(20));
        $expiry = time() + 60 * 60 * 24; // 24h platnosť
        $verifyStored = $verifyToken . '|' . $expiry;
        $now = date('Y-m-d H:i:s');

        $stmt = $pdoLocal->prepare("INSERT INTO users (meno, email, heslo, verify_token, datum_registracie) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$meno, $email, $pwHash, $verifyStored, $now]);
        $userId = (int)$pdoLocal->lastInsertId();

        eshop_log('INFO', "Nová registrácia user_id={$userId} email={$email}");

        // Poslať overovací email ak je PHPMailer
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
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'] ?? '';
                $verifyUrl = site_base_url() . '/eshop/account/verify.php?token=' . rawurlencode($verifyToken) . '&uid=' . $userId;
                $mail->Subject = 'Overenie emailu — Knihy od Autorov';
                $mail->Body = "<p>Vitajte, $meno!</p><p>Prosím overte svoj email kliknutím na odkaz: <a href=\"{$verifyUrl}\">Overiť email</a></p><p>Odkaz je platný 24 hodín.</p>";
                $mail->send();
                eshop_log('INFO', "Poslaný verify email pre user_id={$userId}");
            } else {
                eshop_log('WARN', 'PHPMailer nie je dostupný — overovací email neodoslaný.');
            }
        } catch (Throwable $e) {
            eshop_log('ERROR', 'Chyba pri odoslaní verify emailu: ' . $e->getMessage());
        }

        flash_set('success', 'Registrácia prebehla úspešne. Skontrolujte svoj email pre overenie účtu (ak ste email nedostali, môžete sa prihlásiť aj bez overenia).');
        redirect('/eshop/account/login.php');

    } catch (Throwable $e) {
        eshop_log('ERROR', 'Chyba pri registrácii: ' . $e->getMessage());
        flash_set('error', 'Došlo k chybe pri registrácii.');
        redirect('/eshop/account/register.php');
    }
}

// GET -> zobraz formulár
?>
<!doctype html>
<html lang="sk">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Registrácia</title><link rel="stylesheet" href="/eshop/css/eshop.css"></head>
<body>
  <div class="wrap paper-wrap">
    <h1>Vytvoriť účet</h1>
    <?php foreach (flash_all() as $m) echo '<div class="note">'.htmlspecialchars((string)$m,ENT_QUOTES|ENT_HTML5).'</div>'; ?>
    <form method="post" action="">
      <?php csrf_field('auth'); ?>
      <p><label>Meno:<br><input type="text" name="meno" required></label></p>
      <p><label>Email:<br><input type="email" name="email" required></label></p>
      <p><label>Heslo:<br><input type="password" name="heslo" required></label></p>
      <p><label>Heslo znova:<br><input type="password" name="heslo_confirm" required></label></p>
      <p><button class="btn" type="submit">Registrovať sa</button></p>
    </form>
    <p>Už máte účet? <a href="/eshop/account/login.php">Prihlásiť sa</a></p>
  </div>
</body>
</html>
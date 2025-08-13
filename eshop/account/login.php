<?php
declare(strict_types=1);
/**
 * /eshop/account/login.php
 * Prihlásenie užívateľa.
 * POST: _csrf (kľúč 'auth'), email, heslo
 *
 * Základný rate-limiter per IP/email (uložené v session).
 */

require_once __DIR__ . '/../_init.php';
$pdoLocal = $GLOBALS['pdo'] ?? null;
if (!($pdoLocal instanceof PDO)) {
    eshop_log('ERROR','PDO nie je dostupné v account/login.php');
    flash_set('error','Interná chyba.');
    redirect('/eshop/');
}

$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$loginKey = 'login_attempts_'.$ip;
if (!isset($_SESSION[$loginKey])) $_SESSION[$loginKey] = ['count'=>0, 'first'=>time()];

$maxAttempts = 6;
$window = 60 * 5; // 5 minút

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify_token($_POST['_csrf'] ?? null, 'auth')) {
        flash_set('error','Neplatný CSRF token.');
        redirect('/eshop/account/login.php');
    }

    // reset counter ak window vypršal
    if (time() - $_SESSION[$loginKey]['first'] > $window) {
        $_SESSION[$loginKey] = ['count'=>0, 'first'=>time()];
    }

    if ($_SESSION[$loginKey]['count'] >= $maxAttempts) {
        flash_set('error','Príliš veľa neúspešných pokusov. Skúste to o niekoľko minút.');
        redirect('/eshop/account/login.php');
    }

    $email = filter_var(trim((string)($_POST['email'] ?? '')), FILTER_VALIDATE_EMAIL);
    $heslo = (string)($_POST['heslo'] ?? '');
    if ($email === false || $heslo === '') {
        flash_set('error','Vyplňte email a heslo.');
        $_SESSION[$loginKey]['count']++;
        redirect('/eshop/account/login.php');
    }

    try {
        $stmt = $pdoLocal->prepare("SELECT id, heslo, email_verified FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user || !isset($user['heslo']) || !password_verify($heslo, $user['heslo'])) {
            $_SESSION[$loginKey]['count']++;
            eshop_log('WARN', "Neúspešné prihlásenie pre email={$email} ip={$ip}");
            flash_set('error','Neplatné prihlasovacie údaje.');
            redirect('/eshop/account/login.php');
        }

        // Optional: require email_verified => currently allow login but notify user
        if ((int)$user['email_verified'] !== 1) {
            flash_set('info','Váš email ešte nie je overený. Niektoré funkcie môžu byť obmedzené.');
        }

        // success: reset attempts, set session user
        $_SESSION[$loginKey] = ['count'=>0, 'first'=>time()];
        auth_login((int)$user['id']);
        // update last_login
        $stmt = $pdoLocal->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
        $stmt->execute([(int)$user['id']]);

        eshop_log('INFO', "Užívateľ prihlásený id={$user['id']} email={$email}");
        flash_set('success','Prihlásenie prebehlo úspešne.');
        redirect('/eshop/account/account.php');

    } catch (Throwable $e) {
        eshop_log('ERROR','Chyba pri prihlasovaní: '.$e->getMessage());
        flash_set('error','Chyba pri prihlasovaní.');
        redirect('/eshop/account/login.php');
    }
}

// GET -> zobraz form
?>
<!doctype html>
<html lang="sk">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Prihlásenie</title><link rel="stylesheet" href="/eshop/css/eshop.css"></head>
<body>
  <div class="wrap paper-wrap">
    <h1>Prihlásiť sa</h1>
    <?php foreach (flash_all() as $m) echo '<div class="note">'.htmlspecialchars((string)$m,ENT_QUOTES|ENT_HTML5).'</div>'; ?>
    <form method="post" action="">
      <?php csrf_field('auth'); ?>
      <p><label>Email:<br><input type="email" name="email" required></label></p>
      <p><label>Heslo:<br><input type="password" name="heslo" required></label></p>
      <p><button class="btn" type="submit">Prihlásiť sa</button></p>
    </form>
    <p><a href="/eshop/account/reset-request.php">Zabudli ste heslo?</a></p>
    <p>Nemáte účet? <a href="/eshop/account/register.php">Registrovať sa</a></p>
  </div>
</body>
</html>
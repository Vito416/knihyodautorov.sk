<?php
declare(strict_types=1);
/**
 * /eshop/account/reset-confirm.php
 * Potvrdí token a umožní zmenu hesla.
 * GET: token, uid -> zobraz form
 * POST: _csrf (kľúč 'auth'), uid, token, heslo, heslo_confirm -> vykoná reset
 *
 * Tokeny sú uložené ako token|expiry v users.reset_token
 */

require_once __DIR__ . '/../_init.php';
$pdoLocal = $GLOBALS['pdo'] ?? null;
if (!($pdoLocal instanceof PDO)) {
    eshop_log('ERROR','PDO nie je dostupné v account/reset-confirm.php');
    flash_set('error','Interná chyba.');
    redirect('/eshop/');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify_token($_POST['_csrf'] ?? null, 'auth')) {
        flash_set('error','Neplatný CSRF token.');
        redirect('/eshop/account/reset-confirm.php');
    }

    $uid = isset($_POST['uid']) ? (int)$_POST['uid'] : 0;
    $token = trim((string)($_POST['token'] ?? ''));
    $heslo = (string)($_POST['heslo'] ?? '');
    $heslo2 = (string)($_POST['heslo_confirm'] ?? '');

    if ($uid <= 0 || $token === '' || $heslo === '' || $heslo !== $heslo2) {
        flash_set('error','Neplatné údaje.');
        redirect('/eshop/account/reset-confirm.php?uid=' . $uid . '&token=' . rawurlencode($token));
    }

    try {
        $stmt = $pdoLocal->prepare("SELECT reset_token FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$uid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || empty($row['reset_token'])) {
            flash_set('error','Neplatný alebo už použitý token.');
            redirect('/eshop/account/reset-request.php');
        }
        $parts = explode('|', $row['reset_token'], 2);
        $storedToken = $parts[0] ?? '';
        $expiry = isset($parts[1]) ? (int)$parts[1] : 0;
        if (!hash_equals((string)$storedToken, $token) || (int)$expiry < time()) {
            flash_set('error','Token expiroval alebo je neplatný.');
            redirect('/eshop/account/reset-request.php');
        }

        $pwHash = password_hash($heslo, PASSWORD_DEFAULT);
        $stmt = $pdoLocal->prepare("UPDATE users SET heslo = ?, reset_token = NULL WHERE id = ?");
        $stmt->execute([$pwHash, $uid]);

        eshop_log('INFO', "Užívateľ id={$uid} úspešne zmenil heslo pomocou reset tokenu.");
        flash_set('success','Heslo bolo úspešne zmenené. Prihláste sa.');
        redirect('/eshop/account/login.php');

    } catch (Throwable $e) {
        eshop_log('ERROR','Chyba pri reset-confirm: '.$e->getMessage());
        flash_set('error','Chyba pri obnove hesla.');
        redirect('/eshop/account/reset-request.php');
    }
}

// GET -> zobraz form ak token validny
$uid = isset($_GET['uid']) ? (int)$_GET['uid'] : 0;
$token = trim((string)($_GET['token'] ?? ''));
if ($uid <= 0 || $token === '') {
    flash_set('error','Neplatný odkaz.');
    redirect('/eshop/account/reset-request.php');
}

try {
    $stmt = $pdoLocal->prepare("SELECT reset_token FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$uid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || empty($row['reset_token'])) {
        flash_set('error','Neplatný alebo už použitý token.');
        redirect('/eshop/account/reset-request.php');
    }
    [$storedToken, $expiry] = explode('|', $row['reset_token']) + [null, 0];
    if (!hash_equals((string)$storedToken, $token) || (int)$expiry < time()) {
        flash_set('error','Token expiroval alebo je neplatný.');
        redirect('/eshop/account/reset-request.php');
    }
} catch (Throwable $e) {
    eshop_log('ERROR','Chyba pri overení reset tokenu: '.$e->getMessage());
    flash_set('error','Interná chyba.');
    redirect('/eshop/account/reset-request.php');
}

?>
<!doctype html>
<html lang="sk">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Obnova hesla</title><link rel="stylesheet" href="/eshop/css/eshop.css"></head>
<body>
  <div class="wrap paper-wrap">
    <h1>Obnoviť heslo</h1>
    <?php foreach (flash_all() as $m) echo '<div class="note">'.htmlspecialchars((string)$m,ENT_QUOTES|ENT_HTML5).'</div>'; ?>
    <form method="post" action="">
      <?php csrf_field('auth'); ?>
      <input type="hidden" name="uid" value="<?php echo (int)$uid; ?>">
      <input type="hidden" name="token" value="<?php echo htmlspecialchars($token, ENT_QUOTES|ENT_HTML5); ?>">
      <p><label>Nové heslo:<br><input type="password" name="heslo" required></label></p>
      <p><label>Nové heslo znova:<br><input type="password" name="heslo_confirm" required></label></p>
      <p><button class="btn" type="submit">Zmeniť heslo</button></p>
    </form>
  </div>
</body>
</html>
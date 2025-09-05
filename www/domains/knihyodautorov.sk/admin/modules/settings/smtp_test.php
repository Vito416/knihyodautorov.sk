<?php
require __DIR__ . '/../../inc/bootstrap.php';
$cfgAll = require __DIR__ . '/../../../../secure/config.php';
$smtp = $cfgAll['smtp'] ?? [];
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $to = $_POST['to'] ?? ($smtp['from_email'] ?? 'test@example.com');
    // Try simple PHP mail() for basic test
    $sub = 'SMTP test z ' . $_SERVER['SERVER_NAME'];
    $body = "Testovacia správa. Ak vidíte túto správu, mail() funguje.\n";
    $ok = @mail($to, $sub, $body, 'From: ' . ($smtp['from_name'] ?? 'Test') . ' <' . ($smtp['from_email'] ?? 'noreply@example.com') . '>');
    if ($ok) $message = 'mail() funkcia úspešne odoslala správu na ' . e($to);
    else $message = 'mail() zlyhala. Skontrolujte PHP mail/SMTP nastavenie. Môžete tiež použiť externý SMTP test.';
}
?>
<!doctype html><html lang="sk"><head><meta charset="utf-8"><title>SMTP test</title></head><body>
<?php include __DIR__ . '/../templates/admin-header.php'; ?>
<main>
  <h1>SMTP test</h1>
  <?php if ($message) echo '<p>'.e($message).'</p>'; ?>
  <form method="post">
    <label>Adresa príjemcu<input name="to" value="<?=e($smtp['from_email'] ?? '')?>"></label><br>
    <input type="hidden" name="csrf_token" value="<?=e(CSRF::token())?>">
    <button type="submit">Odoslať testovací e-mail (mail())</button>
  </form>
  <p>Poznámka: tento endpoint používa PHP mail(). Ak chcete autentifikovaný SMTP test (STARTTLS/AUTH LOGIN), nainštalujte a použite PHPMailer alebo iný SMTP klient.</p>
</main>
</body></html>
<?php
require __DIR__ . '/../bootstrap.php';
$pdoLocal = $pdo;
$notice = null;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? '')) $errors[] = 'Neplatný CSRF token.';
    else {
        $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Neplatný e-mail.';
        if (empty($errors)) {
            $stmt = $pdoLocal->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
            $stmt->execute([$email]); $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                // nevypisujeme detailne info z bezpečnosti
                $notice = 'Ak existuje účet s touto e-mailovou adresou, bol odoslaný odkaz na reset.';
            } else {
                $token = bin2hex(random_bytes(24));
                $pdoLocal->prepare("UPDATE users SET reset_token = ? WHERE id = ?")->execute([$token, (int)$row['id']]);
                // tady poslat email. Ak nie je SMTP, zobrazíme link (len dev)
                $link = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '') . '/eshop/auth/reset.php?token=' . urlencode($token);
                // pokus o mail()
                $subject = "Obnovenie hesla - Knihy od Autorov";
                $message = "Pre obnovu hesla kliknite na tento odkaz:\n\n{$link}\n\nAk ste nežiadali obnovu, ignorujte tento e-mail.";
                @mail($email, $subject, $message, "From: noreply@{$_SERVER['HTTP_HOST']}");
                $notice = 'Odkaz na reset bol odoslaný (ak je nastavené mailovanie). Pre testovanie tu je odkaz:';
                $notice .= "\n\n" . $link;
            }
        }
    }
}
?><!doctype html><html lang="sk"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Obnovenie hesla</title><link rel="stylesheet" href="/eshop/css/eshop-auth.css"></head><body class="eshop">
<div class="eshop-wrap"><div class="card">
  <h1>Obnovenie hesla</h1>
  <?php if (!empty($errors)): ?><div class="msg error"><?php foreach($errors as $e) echo '<div>'.esc($e).'</div>'; ?></div><?php endif; ?>
  <?php if ($notice): ?><div class="msg success"><pre style="white-space:pre-wrap"><?php echo esc($notice); ?></pre></div><?php endif; ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?php echo esc(csrf_token()); ?>">
    <div class="form-row"><label for="email">E-mail</label><input id="email" name="email" type="email" required></div>
    <div class="form-row"><button class="btn" type="submit">Odoslať odkaz</button></div>
  </form>
</div></div>
</body></html>

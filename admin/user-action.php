<?php
// /admin/user-action.php  (upravené)
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
if (!admin_is_logged()) { header('Location: login.php'); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: users.php'); exit; }

$csrf = $_POST['csrf'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'],$csrf)) { die('CSRF token invalid'); }

$id = (int)($_POST['id'] ?? 0);
$action = $_POST['action'] ?? '';

try {
  if ($action === 'delete') {
    $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
    $_SESSION['flash_success'] = 'Užívateľ vymazaný.';
    header('Location: users.php'); exit;
  }

  if ($action === 'toggle_news') {
    $pdo->prepare("UPDATE users SET newsletter = IF(newsletter=1,0,1) WHERE id = ?")->execute([$id]);
    $_SESSION['flash_success'] = 'Newsletter preferencie upravené.';
    header('Location: users.php'); exit;
  }

  if ($action === 'update') {
    $meno = trim((string)($_POST['meno'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $telefon = trim((string)($_POST['telefon'] ?? null));
    $adresa = trim((string)($_POST['adresa'] ?? null));
    $newsletter = isset($_POST['newsletter']) ? 1 : 0;
    $stmt = $pdo->prepare("UPDATE users SET meno=?, email=?, telefon=?, adresa=?, newsletter=? WHERE id=?");
    $stmt->execute([$meno,$email,$telefon,$adresa,$newsletter,$id]);
    $_SESSION['flash_success'] = 'Užívateľ aktualizovaný.';
    header('Location: user-edit.php?id=' . $id); exit;
  }

  if ($action === 'set_password') {
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['new_password_confirm'] ?? '';
    if ($new === '' || $new !== $confirm) {
      $_SESSION['flash_error'] = 'Heslá sa nezhodujú alebo sú prázdne.';
      header('Location: user-edit.php?id=' . $id); exit;
    }
    $hash = password_hash($new, PASSWORD_DEFAULT);
    $pdo->prepare("UPDATE users SET heslo = ? WHERE id = ?")->execute([$hash,$id]);
    $_SESSION['flash_success'] = 'Heslo nastavené.';
    header('Location: user-edit.php?id=' . $id); exit;
  }

  if ($action === 'send_reset_token' || $action === 'send_verify') {
    // generate token
    $token = bin2hex(random_bytes(16));
    if ($action === 'send_reset_token') {
      $pdo->prepare("UPDATE users SET reset_token = ? WHERE id = ?")->execute([$token, $id]);
      $subject = 'Reset hesla — Knihy od autorov';
      $link = (isset($_SERVER['REQUEST_SCHEME']) ? $_SERVER['REQUEST_SCHEME'] : 'https') . '://' . $_SERVER['HTTP_HOST'] . '/reset.php?token=' . $token;
      $body = "Žiadosť o obnovenie hesla.\nKliknite sem: $link\nAk ste o to nežiadali, ignorujte tento e-mail.";
      $_SESSION['flash_success'] = 'Resetovací e-mail pripravený na odoslanie (skúste skontrolovať poštu).';
    } else {
      $pdo->prepare("UPDATE users SET verify_token = ? WHERE id = ?")->execute([$token, $id]);
      $subject = 'Overenie e-mailu — Knihy od autorov';
      $link = (isset($_SERVER['REQUEST_SCHEME']) ? $_SERVER['REQUEST_SCHEME'] : 'https') . '://' . $_SERVER['HTTP_HOST'] . '/verify.php?token=' . $token;
      $body = "Kliknite sem pre overenie e-mailu: $link\nĎakujeme.";
      $_SESSION['flash_success'] = 'Verifikačný e-mail pripravený.';
    }

    // send (simple)
    // try SMTP settings from DB
    $s = $pdo->prepare("SELECT k,v FROM settings WHERE k IN ('smtp_host','smtp_port','smtp_user','smtp_pass','smtp_from')");
    $s->execute();
    $cfg = [];
    while($r=$s->fetch(PDO::FETCH_ASSOC)) $cfg[$r['k']] = $r['v'];
    $to = $pdo->prepare("SELECT email FROM users WHERE id = ? LIMIT 1");
    $to->execute([$id]);
    $toEmail = $to->fetchColumn();

    $from = $cfg['smtp_from'] ?? 'noreply@' . ($_SERVER['SERVER_NAME'] ?? 'localhost');
    // use local mail() as fallback
    $headers = "From: $from\r\nMIME-Version: 1.0\r\nContent-type: text/plain; charset=utf-8\r\n";
    @mail($toEmail, $subject, $body, $headers);

    header('Location: user-edit.php?id=' . $id); exit;
  }

  // unknown action
  $_SESSION['flash_error'] = 'Neznáma akcia.';
} catch (Throwable $e) {
  error_log("user-action.php ERROR: ".$e->getMessage());
  $_SESSION['flash_error'] = 'Chyba pri vykonávaní akcie.';
}

header('Location: users.php');
exit;
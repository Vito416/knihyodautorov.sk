<?php
// pages/email_templates/contact_admin.php
// Available (escaped) variables from payload: $name, $email, $message, $ip, $site
// Optional image variables: $logo_cid, $logo_url
// Also accepts Mailer-generated names: $__img_logo_cid, $__img_logo_url

$logoCid = $logo_cid ?? ($__img_logo_cid ?? null);
$logoUrl = $logo_url ?? ($__img_logo_url ?? null);

if (!empty($logoCid)) {
    $logoSrc = 'cid:' . $logoCid;
} elseif (!empty($logoUrl)) {
    $logoSrc = $logoUrl;
} else {
    $logoSrc = '/assets/logo.png';
}

// safe display values (Templates engine already escapes values, but be defensive)
$senderName = ($name ?? '') !== '' ? ($name) : 'Neznámy odosielateľ';
$senderEmail = ($email ?? '') !== '' ? ($email) : 'neznama@domena';
$site = ($site ?? '') ?: ($_SERVER['SERVER_NAME'] ?? '');
$ip = ($ip ?? '') ?: 'neznáme';
$msgRaw = $message ?? '';
// preserve newlines in HTML
$msgHtml = nl2br(htmlspecialchars($msgRaw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
?>
<!doctype html>
<html lang="sk">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Nová správa z kontaktného formulára</title>
</head>
<body style="font-family:Arial,Helvetica,sans-serif;color:#222;line-height:1.4;background:#fff;padding:0;margin:0;">
  <div style="max-width:680px;margin:0 auto;padding:20px;border:1px solid #f0f0f0;">
    <div style="text-align:left;margin-bottom:18px;">
      <img src="<?= $logoSrc ?>" alt="Logo" style="max-height:80px;display:block;margin:0 0 12px 0;">
    </div>

    <h1 style="font-size:20px;margin:0 0 10px 0;color:#111;">Nová správa z kontaktného formulára</h1>

    <p style="margin:0 0 8px 0;">
      <strong>Stránka:</strong> <?= htmlspecialchars($site, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
    </p>

    <p style="margin:0 0 8px 0;">
      <strong>Meno:</strong> <?= htmlspecialchars($senderName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
      &nbsp;|&nbsp;
      <strong>E-mail:</strong>
      <a href="mailto:<?= htmlspecialchars($senderEmail, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars($senderEmail, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
    </p>

    <p style="margin:0 0 8px 0;color:#666;">
      <strong>IP adresa:</strong> <?= htmlspecialchars($ip, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
    </p>

    <hr style="border:none;border-top:1px solid #eee;margin:12px 0;">

    <h2 style="font-size:16px;margin:0 0 8px 0;color:#111;">Správa</h2>
    <div style="background:#fafafa;border:1px solid #f0f0f0;padding:12px;border-radius:6px;margin-bottom:12px;color:#111;">
      <?= $msgHtml ?>
    </div>

    <p style="font-size:12px;color:#777;margin:0;">
      Toto je notifikácia zo stránky <?= htmlspecialchars($site, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>.
    </p>

    <p style="font-size:12px;color:#777;margin-top:12px;">
      &copy; <?= date('Y') ?> <?= htmlspecialchars($_ENV['APP_NAME'] ?? 'Vaša stránka', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
    </p>
  </div>
</body>
</html>
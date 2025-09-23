<?php
// newsletter_subscribe_confirm.php
// Available (escaped) variables: $confirm_url, $unsubscribe_url
// Optional image vars from Mailer: $logo_cid, $logo_url, $__img_logo_cid, $__img_logo_url

// Resolve logo source (prefer CID, then generated __img, then URL, then fallback)
$logoCid = $logo_cid ?? ($__img_logo_cid ?? null);
$logoUrl = $logo_url ?? ($__img_logo_url ?? null);

if (!empty($logoCid)) {
    $logoSrc = 'cid:' . $logoCid;
} elseif (!empty($logoUrl)) {
    $logoSrc = $logoUrl;
} else {
    $logoSrc = '/assets/logo.png';
}

$confirmHref = $confirm_url ?? '#';
$unsubscribeHref = $unsubscribe_url ?? '#';
?>
<!doctype html>
<html lang="sk">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Potvrďte prihlásenie na odber noviniek</title>
</head>
<body style="font-family:Arial,Helvetica,sans-serif;color:#222;line-height:1.4;background:#fff;padding:0;margin:0;">
  <div style="max-width:680px;margin:0 auto;padding:20px;">
    <div style="text-align:left;margin-bottom:18px;">
      <img src="<?= $logoSrc ?>" alt="Logo" style="max-height:80px;display:block;margin:0 0 12px 0;">
    </div>

    <h1 style="font-size:20px;margin:0 0 10px 0;color:#111;">Potvrďte prihlásenie na odber noviniek</h1>

    <p style="margin:0 0 12px 0;">Dobrý deň,</p>

    <p style="margin:0 0 12px 0;">
      Prosím potvrďte prihlásenie na zasielanie noviniek kliknutím na tlačidlo nižšie:
    </p>

    <p style="margin:16px 0;">
      <a href="<?= $confirmHref ?>" style="background:#1e73be;color:#fff;padding:10px 16px;border-radius:6px;text-decoration:none;display:inline-block;">
        Potvrdiť prihlásenie
      </a>
    </p>

    <p style="margin:12px 0 0 0;color:#555;">
      Ak tlačidlo nefunguje, skopírujte a vložte tento odkaz do prehliadača:
    </p>

    <p style="word-break:break-all;color:#1e73be;">
      <a href="<?= $confirmHref ?>"><?= $confirmHref ?></a>
    </p>

    <hr style="border:none;border-top:1px solid #eee;margin:20px 0;">

    <p style="margin:0 0 12px 0;">
      Ak si prihlásenie na odber neželáte alebo chcete zrušiť, kliknite na tento odkaz:
    </p>
    <p style="word-break:break-all;color:#1e73be;">
      <a href="<?= $unsubscribeHref ?>"><?= $unsubscribeHref ?></a>
    </p>

    <p style="font-size:12px;color:#777;margin-top:18px;">
      Prihlásením súhlasíte so zasielaním noviniek. Odhlásiť sa môžete kedykoľvek pomocou odkazu v každom e-maile.
    </p>

    <p style="font-size:12px;color:#777;margin-top:12px;">
      &copy; <?= date('Y') ?> <?= htmlspecialchars($_ENV['APP_NAME'] ?? 'Naša služba', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
    </p>
  </div>
</body>
</html>
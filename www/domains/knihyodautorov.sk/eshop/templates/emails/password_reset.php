<?php
// password_reset.php (HTML)
// Available (escaped) variables: $reset_url, $site
// Optional: $logo_cid, $logo_url
// Also accepts Mailer-generated names: $__img_logo_cid, $__img_logo_url

// Choose logo source: prefer explicit logo_cid, then internal $__img_logo_cid, then logo_url, then $__img_logo_url, then fallback
$logoCid = $logo_cid ?? ($__img_logo_cid ?? null);
$logoUrl = $logo_url ?? ($__img_logo_url ?? null);

// build src (Templates already escaped values)
// note: if $logoCid is present, use cid:...
if (!empty($logoCid)) {
    $logoSrc = 'cid:' . $logoCid;
} elseif (!empty($logoUrl)) {
    $logoSrc = $logoUrl;
} else {
    // relative fallback (adjust path to your public static assets)
    $logoSrc = '/assets/logo.png';
}

// friendly greeting
$greeting = 'Vážený používateľ';
?>
<!doctype html>
<html lang="sk">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Obnovenie hesla</title>
</head>
<body style="font-family:Arial,Helvetica,sans-serif;color:#222;line-height:1.4;background:#fff;padding:0;margin:0;">
  <div style="max-width:680px;margin:0 auto;padding:20px;">
    <div style="text-align:left;margin-bottom:18px;">
      <img src="<?= $logoSrc ?>" alt="Logo" style="max-height:80px;display:block;margin:0 0 12px 0;">
    </div>

    <h1 style="font-size:20px;margin:0 0 10px 0;color:#111;">Obnovenie hesla</h1>

    <p style="margin:0 0 12px 0;"><?= $greeting ?>,</p>

    <p style="margin:0 0 12px 0;">
      Nedávno sme obdržali požiadavku na obnovenie hesla pre účet registrovaný na tejto adrese.
      Ak ste o obnovenie nežiadali, môžete tento e-mail ignorovať — nič sa nezmení.
    </p>

    <p style="margin:0 0 12px 0;">
      Ak chcete heslo obnoviť, kliknite na tlačidlo nižšie:
    </p>

    <p style="margin:16px 0;">
      <a href="<?= ($reset_url ?? '#') ?>" style="background:#1e73be;color:#fff;padding:10px 16px;border-radius:6px;text-decoration:none;display:inline-block;">
        Obnoviť heslo
      </a>
    </p>

    <p style="margin:12px 0 0 0;color:#555;">
      Ak tlačidlo nefunguje, skopírujte a vložte tento odkaz do prehliadača:
    </p>

    <p style="word-break:break-all;color:#1e73be;">
      <a href="<?= ($reset_url ?? '#') ?>"><?= ($reset_url ?? '#') ?></a>
    </p>

    <hr style="border:none;border-top:1px solid #eee;margin:20px 0;">

    <p style="font-size:13px;color:#777;margin:0;">
      Táto žiadosť bola vykonaná pre stránku: <?= htmlspecialchars($site ?? ($_ENV['APP_NAME'] ?? 'Náš web'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>.
      Odkaz na obnovenie platí len krátko, ak je potrebné, žiadosť zopakujte.
    </p>

    <p style="font-size:12px;color:#777;margin-top:10px;">
      &copy; <?= date('Y') ?> <?= htmlspecialchars($_ENV['APP_NAME'] ?? 'Naša služba', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
    </p>
  </div>
</body>
</html>
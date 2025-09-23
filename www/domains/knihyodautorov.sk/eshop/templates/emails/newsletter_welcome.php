<?php
// newsletter_welcome.php
// Available (escaped) variables: $logo_cid, $logo_url
// Optional: $__img_logo_cid, $__img_logo_url
// No personalized names here, newsletter is generic

// Choose logo source: prefer explicit logo_cid, then internal __img_logo_cid, then logo_url, then __img_logo_url, then fallback
$logoCid = $logo_cid ?? ($__img_logo_cid ?? null);
$logoUrl = $logo_url ?? ($__img_logo_url ?? null);

// build src (Templates already escaped values)
// note: if $logoCid is present, use cid:...
if (!empty($logoCid)) {
    $logoSrc = 'cid:' . $logoCid;
} elseif (!empty($logoUrl)) {
    $logoSrc = $logoUrl;
} else {
    $logoSrc = '/assets/logo.png';
}
?>
<!doctype html>
<html lang="sk">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Vitajte — odber noviniek potvrdený</title>
</head>
<body style="font-family:Arial,Helvetica,sans-serif;color:#222;line-height:1.5;background:#fff;padding:0;margin:0;">
  <div style="max-width:680px;margin:0 auto;padding:20px;">
    <div style="text-align:left;margin-bottom:18px;">
      <img src="<?= $logoSrc ?>" alt="Logo" style="max-height:80px;display:block;margin:0 0 12px 0;">
    </div>

    <h1 style="font-size:20px;margin:0 0 10px 0;color:#111;">Vitajte medzi odberateľmi noviniek</h1>

    <p style="margin:0 0 12px 0;">
      Ďakujeme, že ste si prihlásili odber našich noviniek. Odteraz budete pravidelne dostávať informácie o nových knihách, akciách a zaujímavostiach priamo do svojej e-mailovej schránky.
    </p>

    <p style="margin:0 0 12px 0;">
      Sme radi, že ste s nami — vitajte v našej čitateľskej komunite.
    </p>

    <hr style="border:none;border-top:1px solid #eee;margin:20px 0;">

    <p style="font-size:12px;color:#777;margin:0;">
      Tento e-mail bol odoslaný automaticky, prosím neodpovedajte naň.  
      Ak si prajete zrušiť odber, nájdete odkaz na odhlásenie v každom ďalšom e-maile.
    </p>

    <p style="font-size:12px;color:#777;margin-top:8px;">
      &copy; <?= date('Y') ?> <?= htmlspecialchars($_ENV['APP_NAME'] ?? 'Naša služba', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
    </p>
  </div>
</body>
</html>
<?php
// /admin/invoice-template-html.php
// Pre: $invoiceId, $invoiceNumber, $order, $items, $user, $settings, $total, $currency, $note
if (!isset($invoiceNumber)) $invoiceNumber = '—';
$company_name = $settings['company_name'] ?? 'Knihy od autorov';
$company_street = $settings['company_street'] ?? '';
$company_city = $settings['company_city'] ?? '';
$company_ic = $settings['company_ic'] ?? '';
$company_dic = $settings['company_dic'] ?? '';
$company_iban = $settings['company_iban'] ?? '';
$company_bank = $settings['company_bank'] ?? '';
$company_phone = $settings['company_phone'] ?? '';
$company_email = $settings['company_email'] ?? '';
?>
<!doctype html>
<html lang="sk">
<head>
  <meta charset="utf-8">
  <title>Faktúra <?php echo htmlspecialchars($invoiceNumber); ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    /* základný štýl faktúry — vhodné pre PDF i print */
    body{font-family: DejaVu Sans, Arial, sans-serif; color:#222; margin:0; padding:20px; background:#fff;}
    .wrap{max-width:900px;margin:0 auto;padding:20px;border:1px solid #eee;}
    header{display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:20px;}
    .company{font-weight:800; font-size:1.15rem; color:#3b2a1a;}
    .company small{display:block;font-weight:500;color:#6b5130;}
    .meta{ text-align:right; }
    .meta h2{margin:0;color:#6b5130}
    table.items{width:100%; border-collapse:collapse; margin-top:18px;}
    table.items th, table.items td{border:1px solid #ddd; padding:8px; text-align:left;}
    table.items th{background: #fbf6ea; font-weight:800; color:#3e2a12;}
    .total{margin-top:12px; text-align:right; font-weight:900; font-size:1.1rem; color:#3e2a12;}
    .small{font-size:0.9rem; color:#555;}
    footer{margin-top:28px; border-top:1px dashed #ddd; padding-top:12px; color:#6b5130;}
    .paybox{margin-top:10px; padding:10px; background: #fff7e6; border-left:6px solid #cf9b3a;}
    .qr { float:right; width:120px; height:120px; border:1px solid #eee; padding:6px }
  </style>
</head>
<body>
  <div class="wrap">
    <header>
      <div>
        <div class="company"><?php echo htmlspecialchars($company_name); ?></div>
        <div class="small"><?php echo nl2br(htmlspecialchars($company_street . "\n" . $company_city)); ?></div>
        <div class="small">IČ: <?php echo htmlspecialchars($company_ic); ?> • DIČ: <?php echo htmlspecialchars($company_dic); ?></div>
        <div class="small">Bank: <?php echo htmlspecialchars($company_bank); ?> • IBAN: <?php echo htmlspecialchars($company_iban); ?></div>
        <div class="small">Tel: <?php echo htmlspecialchars($company_phone); ?> • E: <?php echo htmlspecialchars($company_email); ?></div>
      </div>

      <div class="meta">
        <h2>Faktúra</h2>
        <div class="small">Číslo: <strong><?php echo htmlspecialchars($invoiceNumber); ?></strong></div>
        <div class="small">Dátum: <?php echo date('Y-m-d H:i'); ?></div>
        <div class="small">Objednávka: #<?php echo (int)$order['id']; ?></div>
      </div>
    </header>

    <section>
      <strong>Dodávateľ:</strong>
      <div class="small"><?php echo htmlspecialchars($company_name); ?></div>
      <div class="small"><?php echo nl2br(htmlspecialchars($company_street . "\n" . $company_city)); ?></div>

      <strong style="margin-top:12px; display:block;">Odberateľ:</strong>
      <div class="small"><?php echo htmlspecialchars($user['meno'] ?? '—'); ?></div>
      <div class="small"><?php echo nl2br(htmlspecialchars($user['adresa'] ?? '')); ?></div>
      <div class="small"><?php echo htmlspecialchars($user['email'] ?? ''); ?></div>
    </section>

    <table class="items" role="table" aria-label="Položky faktúry">
      <thead><tr><th>#</th><th>Názov</th><th>Množstvo</th><th>Cena / ks</th><th>Spolu</th></tr></thead>
      <tbody>
        <?php $i=1; foreach ($items as $it): ?>
          <tr>
            <td><?php echo $i++; ?></td>
            <td><?php echo htmlspecialchars($it['nazov']); ?></td>
            <td><?php echo (int)$it['quantity']; ?></td>
            <td><?php echo number_format((float)$it['unit_price'],2,',','.'); ?> <?php echo htmlspecialchars($currency); ?></td>
            <td><?php echo number_format(((float)$it['unit_price'] * (int)$it['quantity']),2,',','.'); ?> <?php echo htmlspecialchars($currency); ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <div class="total">Celkom: <?php echo number_format($total,2,',','.'); ?> <?php echo htmlspecialchars($currency); ?></div>

    <?php if (!empty($note)): ?>
      <div class="paybox">
        <strong>Poznámka:</strong> <?php echo htmlspecialchars($note); ?>
      </div>
    <?php endif; ?>

    <footer>
      <div class="small">Ďakujeme za Váš nákup. Časť výťažku venujeme na podporu babyboxov.</div>
      <div style="margin-top:8px;">
        <div class="qr" id="invoice-qr"></div>
        <div class="small">QR pre platbu (zobrazené v prehliadači). Pre tlač do PDF je potrebné vygenerovať QR na serveri alebo vložiť SVG.</div>
      </div>
    </footer>
  </div>

  <!-- client-side QR (iba pre náhľad v prehliadači). Ak chýba qrcode.min.js, ticho prejdeme bez QR. -->
  <script>
    (function(){
      if (typeof QRCode === 'function') {
        var payload = '<?php echo addslashes("Invoice {$invoiceNumber} | Amount: {$total} {$currency}"); ?>';
        new QRCode(document.getElementById('invoice-qr'), { text: payload, width: 120, height: 120 });
      }
    })();
  </script>
</body>
</html>
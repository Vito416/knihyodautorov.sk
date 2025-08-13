<?php
// /eshop/prints/invoice-html.php
// Template for invoice HTML. Expects $orderId and $invoiceId in scope and $pdo available.
if (!isset($orderId) && !isset($_GET['order'])) {
    // If direct open, allow order via GET for preview
    $orderId = isset($_GET['order']) ? (int)$_GET['order'] : null;
}
if (!isset($invoiceId)) $invoiceId = null;

if (!isset($pdo)) {
    echo "<p>PDO not available</p>";
    return;
}
if (!$orderId) {
    echo "<p>Order ID missing.</p>"; return;
}

$orderStmt = $pdo->prepare("SELECT o.*, i.id AS invoice_id, i.invoice_number FROM orders o LEFT JOIN invoices i ON i.order_id = o.id WHERE o.id = ? LIMIT 1");
$orderStmt->execute([$orderId]);
$order = $orderStmt->fetch(PDO::FETCH_ASSOC);
if (!$order) { echo "<p>Objednávka nenájdená</p>"; return; }

$items = $pdo->prepare("SELECT oi.*, b.nazov FROM order_items oi LEFT JOIN books b ON b.id = oi.book_id WHERE oi.order_id = ?");
$items->execute([$orderId]);
$itemsList = $items->fetchAll(PDO::FETCH_ASSOC);

$companyName = eshop_settings($pdo, 'company_name') ?: 'Knihy od Autorov s.r.o.';
$companyAddr = nl2br(eshop_settings($pdo, 'company_address') ?? '');
$companyIban = eshop_settings($pdo, 'company_iban') ?? '';

$invoiceNumber = $order['invoice_number'] ?? ('INV-' . $orderId);
$vs = str_pad((string)$orderId, 10, '0', STR_PAD_LEFT);
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Faktúra <?php echo eshop_esc($invoiceNumber); ?></title>
  <style>
    /* tlačová, epická šablóna: ornamenty, pečať */
    body{font-family: DejaVu Sans, Arial, sans-serif; color:#2b1608; background:#fff; padding:28px}
    .paper{max-width:800px;margin:0 auto;border:6px solid #e6d9b2;padding:24px;border-radius:10px;position:relative;box-shadow:0 30px 80px rgba(0,0,0,0.12)}
    .header{display:flex;justify-content:space-between;align-items:center}
    .brand{font-weight:900;font-size:1.4rem;color:#3e2a12}
    .orn{position:absolute;right:-16px;top:-16px;opacity:.18;width:160px}
    h1{margin:0 0 6px}
    .meta{margin-top:10px}
    .items{width:100%;border-collapse:collapse;margin-top:18px}
    .items th,.items td{border-bottom:1px solid rgba(0,0,0,0.06);padding:8px;text-align:left}
    .total{text-align:right;font-size:1.2rem;margin-top:16px}
    .seal{position:absolute;left:24px;bottom:-30px;width:160px;opacity:0.12}
    .qr{position:absolute;right:24px;bottom:-80px}
  </style>
</head>
<body>
  <div class="paper">
    <img class="orn" src="<?php echo eshop_asset('assets/ornament-corner.png'); ?>" alt="">
    <div class="header">
      <div class="brand">
        <?php echo eshop_esc($companyName); ?><br>
        <small><?php echo $companyAddr; ?></small>
      </div>
      <div class="to">
        <strong>Faktúra #<?php echo eshop_esc($invoiceNumber); ?></strong><br>
        Dátum: <?php echo eshop_esc(date('Y-m-d')); ?><br>
        VS: <?php echo eshop_esc($vs); ?>
      </div>
    </div>

    <div class="meta">
      <strong>Fakturované pre:</strong><br>
      <?php echo nl2br(eshop_esc($order['name'] ?? $order['email'] ?? '')); ?><br>
      <?php echo nl2br(eshop_esc($order['address'] ?? '')); ?>
    </div>

    <table class="items">
      <thead>
        <tr><th>Položka</th><th>Množstvo</th><th>Cena</th><th>Spolu</th></tr>
      </thead>
      <tbody>
        <?php foreach ($itemsList as $it): $line = $it['quantity'] * (float)$it['unit_price']; ?>
          <tr>
            <td><?php echo eshop_esc($it['nazov']); ?></td>
            <td><?php echo (int)$it['quantity']; ?></td>
            <td><?php echo number_format((float)$it['unit_price'],2,',','.'); ?> €</td>
            <td><?php echo number_format($line,2,',','.'); ?> €</td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <div class="total">
      Celkom: <strong><?php echo number_format((float)$order['total_price'],2,',','.'); ?> €</strong>
    </div>

    <?php
    // QR embed: try phpqrcode to generate base64
    if (function_exists('QRcode')) {
        ob_start();
        $iban = eshop_settings($pdo, 'company_iban') ?? '';
        $payload = "SPD*1.0*ACC:{$iban}*AM:".number_format((float)$order['total_price'],2,'.','')."*CC:EUR*X-VS:".str_pad((string)$order['id'],10,'0',STR_PAD_LEFT);
        QRcode::png($payload, null, 'L', 4, 2);
        $img = ob_get_clean();
        $b64 = base64_encode($img);
        echo '<div class="qr"><img src="data:image/png;base64,'.$b64.'" width="140" alt="QR"></div>';
    }
    ?>

    <img class="seal" src="<?php echo eshop_asset('assets/seal.png'); ?>" alt="">
  </div>
</body>
</html>
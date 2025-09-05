<?php
require __DIR__ . '/../../inc/bootstrap.php';
require_once __DIR__ . '/../../../../libs/PayBySquare.php';
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: invoices.php'); exit; }
$stmt = $db->prepare('SELECT * FROM invoices WHERE id = ? LIMIT 1'); $stmt->execute([$id]); $inv = $stmt->fetch();
if (!$inv) { http_response_code(404); echo 'Faktúra nenájdená'; exit; }
$payload = PayBySquare::generatePayload($inv);
$img = PayBySquare::qrImageUrl($payload, 300);
?><!doctype html><html lang="sk"><head><meta charset="utf-8"><title>PayBySquare QR</title></head><body>
<?php include __DIR__ . '/../templates/admin-header.php'; ?>
<main>
  <h1>PayBySquare pre faktúru <?=e($inv['invoice_number'])?></h1>
  <p>Payload: <code><?=e($payload)?></code></p>
  <p><img src="<?=e($img)?>" alt="QR"></p>
</main>
</body></html>
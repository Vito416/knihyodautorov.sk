<?php
// /admin/invoice-create.php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
if (!admin_is_logged()) { header('Location: login.php'); exit; }

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));

// načítaj pending/paid orders (pre výber)
$orders = $pdo->query("SELECT o.id, o.user_id, o.total_price, o.currency, o.status, u.meno, u.email FROM orders o LEFT JOIN users u ON o.user_id = u.id ORDER BY o.created_at DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) die('CSRF token invalid.');

    $order_id = (int)($_POST['order_id'] ?? 0);
    if ($order_id <= 0) { $_SESSION['flash_error']='Vyberte objednávku.'; header('Location: invoice-create.php'); exit; }

    // načítaj order a položky
    $stmt = $pdo->prepare("SELECT o.*, u.meno as user_name, u.email as user_email FROM orders o LEFT JOIN users u ON o.user_id = u.id WHERE o.id = ? LIMIT 1");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) { $_SESSION['flash_error']='Objednávka nenájdená.'; header('Location: invoice-create.php'); exit; }

    $items = $pdo->prepare("SELECT oi.*, b.nazov, b.slug FROM order_items oi LEFT JOIN books b ON oi.book_id = b.id WHERE oi.order_id = ?");
    $items->execute([$order_id]);
    $items = $items->fetchAll(PDO::FETCH_ASSOC);

    // generate invoice number (simple)
    $now = new DateTime();
    $invNumber = 'KDA-' . $now->format('Ymd') . '-' . str_pad((string)rand(1000,9999), 4, '0', STR_PAD_LEFT);

    // create html invoice (simple, print-friendly)
    ob_start();
    ?>
    <!doctype html>
    <html lang="sk">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width,initial-scale=1">
      <title>Faktúra <?php echo htmlspecialchars($invNumber); ?></title>
      <style>
        body{font-family:DejaVu Sans,Arial,Helvetica,sans-serif;color:#2b1b12;margin:24px}
        .inv-wrap{max-width:800px;margin:0 auto;border:1px solid #ddd;padding:18px}
        .hdr{display:flex;justify-content:space-between;align-items:center}
        .brand{font-weight:800;color:#3e2a12}
        .meta{text-align:right}
        table{width:100%;border-collapse:collapse;margin-top:18px}
        th,td{padding:8px;border:1px solid #e8e2d6}
        .total{font-weight:900;text-align:right;padding:12px}
        .qr{float:right}
        @media print { .no-print{display:none} }
      </style>
    </head>
    <body>
      <div class="inv-wrap">
        <div class="hdr">
          <div class="brand">
            <h2>Knihy od autorov</h2>
            <div>IČO: __________ | DIČ: __________</div>
            <div>Adresa: Ulica 123, 010 01 Mesto</div>
          </div>
          <div class="meta">
            <div>Faktúra: <strong><?php echo htmlspecialchars($invNumber); ?></strong></div>
            <div>Dátum: <?php echo $now->format('Y-m-d'); ?></div>
            <div>Objednávka: <?php echo (int)$order['id']; ?></div>
          </div>
        </div>

        <h3>Odběratel</h3>
        <div><?php echo htmlspecialchars($order['user_name'] ?? '—'); ?> &lt;<?php echo htmlspecialchars($order['user_email'] ?? '—'); ?>&gt;</div>

        <table>
          <thead><tr><th>#</th><th>Názov</th><th>Množ.</th><th>Cena</th><th>Spolu</th></tr></thead>
          <tbody>
            <?php $i=1; $sum=0; foreach($items as $it): $line = (float)$it['unit_price'] * (int)$it['quantity']; $sum += $line; ?>
            <tr>
              <td><?php echo $i++; ?></td>
              <td><?php echo htmlspecialchars($it['nazov']); ?></td>
              <td><?php echo (int)$it['quantity']; ?></td>
              <td><?php echo number_format((float)$it['unit_price'],2,',','.').' '.$order['currency']; ?></td>
              <td><?php echo number_format($line,2,',','.').' '.$order['currency']; ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <div class="total">Celkom: <?php echo number_format($sum,2,',','.').' '.$order['currency']; ?></div>

        <div style="margin-top:20px">
          <button onclick="window.print()" class="no-print">Stiahnuť / Vytlačiť (PDF)</button>
          <a href="invoice-download.php?inv=<?php echo urlencode($invNumber); ?>" class="no-print">Stiahnuť HTML</a>
        </div>
      </div>
    </body>
    </html>
    <?php
    $html = ob_get_clean();

    // uloz do DB invoices a do suboru
    try {
        $pdo->prepare("INSERT INTO invoices (order_id, invoice_number, amount, currency, file_path, created_at) VALUES (?, ?, ?, ?, ?, NOW())")
            ->execute([$order_id, $invNumber, (float)$sum, $order['currency'], '']);
        $invoiceId = (int)$pdo->lastInsertId();

        $dir = __DIR__ . '/../eshop/invoices/';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $file = "invoice_{$invoiceId}.html";
        file_put_contents($dir . $file, $html);

        $pdo->prepare("UPDATE invoices SET file_path = ? WHERE id = ?")->execute([$file, $invoiceId]);

        $_SESSION['flash_success'] = "Faktúra vygenerovaná (ID: {$invoiceId}).";
        header('Location: invoice-download.php?invoice_id=' . $invoiceId);
        exit;
    } catch (Throwable $e) {
        error_log("invoice-create.php ERROR: " . $e->getMessage());
        $_SESSION['flash_error'] = 'Chyba pri generovaní faktúry.';
        header('Location: invoice-create.php'); exit;
    }
}
?>
<!doctype html>
<html lang="sk"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Vygenerovať faktúru</title><link rel="stylesheet" href="/admin/css/admin.css"></head>
<body>
  <main class="admin-shell">
    <h1>Vygenerovať faktúru</h1>
    <form method="post" action="invoice-create.php">
      <input type="hidden" name="csrf" value="<?php echo $_SESSION['csrf_token']; ?>">
      <label>Objednávka:
        <select name="order_id" required>
          <option value="">— vyber objednávku —</option>
          <?php foreach ($orders as $o): ?>
            <option value="<?php echo (int)$o['id']; ?>">#<?php echo (int)$o['id']; ?> — <?php echo htmlspecialchars($o['meno'] ?? $o['email']); ?> — <?php echo number_format((float)$o['total_price'],2,',','.'); ?> <?php echo htmlspecialchars($o['currency']); ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <div class="form-actions">
        <button class="btn" type="submit">Vygenerovať</button>
        <a class="btn ghost" href="orders.php">Zrušiť</a>
      </div>
    </form>
  </main>
</body>
</html>
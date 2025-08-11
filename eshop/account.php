<?php
require __DIR__ . '/bootstrap.php';
$pdoLocal = $pdo;
require_login();
$user = current_user($pdoLocal);

// načítame objednávky používateľa a stiahnuteľné knihy (paid)
$stmt = $pdoLocal->prepare("SELECT o.id,o.total_price,o.status,o.created_at FROM orders o WHERE o.user_id = ? ORDER BY o.created_at DESC LIMIT 50");
$stmt->execute([(int)$_SESSION['user_id']]);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// pre každý order načítame položky
$orderItems = [];
if ($orders) {
    $ids = array_column($orders, 'id');
    $in = implode(',', array_map('intval', $ids));
    $sql = "SELECT oi.order_id, oi.book_id, oi.quantity, oi.unit_price, b.nazov, b.pdf_file, b.obrazok
            FROM order_items oi
            JOIN books b ON oi.book_id = b.id
            WHERE oi.order_id IN ({$in})";
    foreach ($pdoLocal->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $orderItems[$r['order_id']][] = $r;
    }
}
?><!doctype html>
<html lang="sk"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Môj účet</title>
<link rel="stylesheet" href="/eshop/css/eshop-auth.css">
</head>
<body class="eshop">
  <div class="eshop-wrap">
    <div class="card">
      <h1>Môj účet</h1>
      <p class="small">Prihlásený ako: <strong><?php echo esc($user['meno']); ?></strong> — <?php echo esc($user['email']); ?></p>

      <h2>Objednávky</h2>
      <?php if (empty($orders)): ?>
        <p class="small">Zatiaľ žiadne objednávky.</p>
      <?php else: ?>
        <?php foreach ($orders as $o): ?>
          <div style="border-top:1px solid rgba(0,0,0,0.06); padding:12px 0;">
            <div><strong>Objednávka #<?php echo esc($o['id']); ?></strong> — <?php echo esc($o['status']); ?> — <?php echo esc($o['created_at']); ?></div>
            <div class="small">Celkom: <?php echo esc(number_format((float)$o['total_price'],2,',',' ')); ?> <?php echo esc('EUR'); ?></div>
            <?php if (!empty($orderItems[$o['id']])): ?>
              <ul>
                <?php foreach ($orderItems[$o['id']] as $it): ?>
                  <li>
                    <?php echo esc($it['nazov']); ?> —
                    Množstvo: <?php echo (int)$it['quantity']; ?> —
                    Cena: <?php echo esc(number_format((float)$it['unit_price'],2,',',' ')); ?> €
                    <?php if ($o['status'] === 'paid'): ?>
                      | <a href="download.php?order=<?php echo (int)$o['id']; ?>&book=<?php echo (int)$it['book_id']; ?>">Stiahnuť</a>
                    <?php else: ?>
                      | <span class="small">Stiahnutie po úhrade</span>
                    <?php endif; ?>
                  </li>
                <?php endforeach;?>
              </ul>
            <?php endif;?>
          </div>
        <?php endforeach;?>
      <?php endif; ?>

      <p class="small"><a href="/eshop/auth/logout.php">Odhlásiť sa</a></p>
    </div>
  </div>
</body>
</html>

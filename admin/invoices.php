<?php
// /admin/invoices.php
require_once __DIR__ . '/bootstrap.php';
require_admin();
require_once __DIR__ . '/inc/helpers.php';

$invoices = $pdo->query("SELECT * FROM invoices ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/header.php';
?>
<section class="adm-section">
  <h1>Faktúry</h1>
  <div class="adm-actions">
    <a class="adm-btn" href="/admin/invoice-create.php">Vytvoriť faktúru</a>
  </div>

  <table class="adm-table">
    <thead><tr><th>#</th><th>Objednávka</th><th>Sum</th><th>Vytvorená</th><th>Akcie</th></tr></thead>
    <tbody>
      <?php foreach ($invoices as $inv): ?>
        <tr>
          <td><?= adm_esc($inv['id']) ?></td>
          <td><?= adm_esc($inv['order_id']) ?></td>
          <td><?= adm_esc(adm_money($inv['amount'])) ?></td>
          <td><?= adm_esc($inv['created_at']) ?></td>
          <td><a class="adm-btn-small" href="/eshop/invoices/<?= adm_esc($inv['file']) ?>" target="_blank">Stiahnuť</a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php include __DIR__ . '/footer.php'; ?>

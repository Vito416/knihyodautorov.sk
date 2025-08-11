<?php
// /admin/reports.php
require_once __DIR__ . '/bootstrap.php';
require_admin();
require_once __DIR__ . '/inc/helpers.php';

// top predaje – 10 najpredávanejších kníh
$top = $pdo->query("
  SELECT b.id, b.nazov, COUNT(oi.id) AS sold
  FROM order_items oi
  JOIN books b ON oi.book_id = b.id
  GROUP BY b.id ORDER BY sold DESC LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/header.php';
?>
<section class="adm-section">
  <h1>Reporty</h1>

  <section class="card">
    <h2>Top predávané knihy</h2>
    <ol>
      <?php foreach ($top as $t): ?>
        <li><?= adm_esc($t['nazov']) ?> — <?= adm_esc($t['sold']) ?> ks</li>
      <?php endforeach; ?>
    </ol>
    <div class="adm-actions"><a class="adm-btn" href="/admin/export-top-books.php">Export CSV</a></div>
  </section>
</section>
<?php include __DIR__ . '/footer.php'; ?>

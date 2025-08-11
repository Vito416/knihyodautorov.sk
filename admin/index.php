<?php
// /admin/index.php
require_once __DIR__ . '/bootstrap.php';
require_admin();
require_once __DIR__ . '/inc/helpers.php';

// Základné štatistiky
$counts = $pdo->query("
  SELECT
    (SELECT COUNT(*) FROM books) AS books,
    (SELECT COUNT(*) FROM authors) AS authors,
    (SELECT COUNT(*) FROM orders) AS orders,
    (SELECT COUNT(*) FROM users) AS users,
    (SELECT COUNT(*) FROM invoices) AS invoices
")->fetch(PDO::FETCH_ASSOC);

// posledné knihy (books.created_at existuje)
$recentBooks = $pdo->query("SELECT id, nazov, obrazok, created_at FROM books ORDER BY created_at DESC LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);

// posledné objednávky
$recentOrders = $pdo->query("SELECT o.id, o.total_price, o.status, o.created_at, u.meno AS user_name FROM orders o LEFT JOIN users u ON o.user_id = u.id ORDER BY o.created_at DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);

// orders per last 7 days
$rows = $pdo->query("
  SELECT DATE(created_at) AS day, COUNT(*) AS cnt, IFNULL(SUM(total_price),0) AS sum_total
  FROM orders
  WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
  GROUP BY DATE(created_at)
  ORDER BY DATE(created_at)
")->fetchAll(PDO::FETCH_ASSOC);

$labels = []; $dataCnt = []; $dataSum = [];
$map = [];
foreach ($rows as $r) $map[$r['day']] = $r;
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $labels[] = $d;
    if (isset($map[$d])) {
        $dataCnt[] = (int)$map[$d]['cnt'];
        $dataSum[] = (float)$map[$d]['sum_total'];
    } else {
        $dataCnt[] = 0;
        $dataSum[] = 0.0;
    }
}

include __DIR__ . '/header.php';
?>

<section class="adm-dashboard">
  <h1>Prehľad</h1>

  <div class="adm-stats">
    <div class="adm-stat">
      <div class="adm-stat-num"><?= adm_esc($counts['books']) ?></div>
      <div class="adm-stat-label">Knihy</div>
    </div>
    <div class="adm-stat">
      <div class="adm-stat-num"><?= adm_esc($counts['authors']) ?></div>
      <div class="adm-stat-label">Autori</div>
    </div>
    <div class="adm-stat">
      <div class="adm-stat-num"><?= adm_esc($counts['orders']) ?></div>
      <div class="adm-stat-label">Objednávky</div>
    </div>
    <div class="adm-stat">
      <div class="adm-stat-num"><?= adm_esc($counts['users']) ?></div>
      <div class="adm-stat-label">Užívatelia</div>
    </div>
    <div class="adm-stat">
      <div class="adm-stat-num"><?= adm_esc($counts['invoices']) ?></div>
      <div class="adm-stat-label">Faktúry</div>
    </div>
  </div>

  <div class="adm-row">
    <div class="adm-col">
      <section class="card">
        <h2>Objednávky (posledných 8)</h2>
        <ul class="adm-list">
          <?php foreach ($recentOrders as $o): ?>
            <li>
              <strong>#<?= adm_esc($o['id']) ?></strong>
              <?= adm_esc($o['user_name'] ?? 'Neregistrovaný') ?> —
              <?= adm_esc(adm_money($o['total_price'])) ?>
              <span class="badge"><?= adm_esc($o['status']) ?></span>
              <span class="muted"><?= adm_esc($o['created_at']) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      </section>
    </div>

    <div class="adm-col">
      <section class="card">
        <h2>Graf objednávok (7 dní)</h2>
        <canvas id="chartOrders" height="160"></canvas>
      </section>

      <section class="card" style="margin-top:12px;">
        <h2>Posledné knihy</h2>
        <div class="mini-grid">
          <?php foreach ($recentBooks as $b): ?>
            <article class="mini">
              <img src="<?= adm_esc($b['obrazok'] ? '/books-img/' . ltrim($b['obrazok'],'/') : '/assets/books-imgFB.png') ?>" alt="<?= adm_esc($b['nazov']) ?>">
              <div class="mini-meta">
                <strong><?= adm_esc($b['nazov']) ?></strong>
                <small><?= adm_esc($b['created_at']) ?></small>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      </section>
    </div>
  </div>
</section>

<?php include __DIR__ . '/footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js" defer></script>
<script>
document.addEventListener('DOMContentLoaded', function(){
  const labels = <?= json_encode($labels, JSON_UNESCAPED_UNICODE) ?>;
  const dataCnt = <?= json_encode($dataCnt, JSON_UNESCAPED_UNICODE) ?>;
  const dataSum = <?= json_encode($dataSum, JSON_UNESCAPED_UNICODE) ?>;
  const ctx = document.getElementById('chartOrders').getContext('2d');
  new Chart(ctx, {
    type: 'bar',
    data: {
      labels: labels,
      datasets: [
        { label: 'Objednávky', data: dataCnt, backgroundColor: 'rgba(207,155,58,0.92)' },
        { label: 'Suma (€)', data: dataSum, backgroundColor: 'rgba(139,90,32,0.78)' }
      ]
    },
    options: { responsive: true, plugins: { legend: { position: 'top' } } }
  });
});
</script>

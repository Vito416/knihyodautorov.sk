<?php
// /admin/index.php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_admin();

// bezpečný escape helper (lokálny)
if (!function_exists('admin_esc')) {
    function admin_esc($s) {
        if (function_exists('esc')) return esc($s);
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

$counts = [
    'books' => 0,
    'authors' => 0,
    'users' => 0,
    'orders' => 0,
    'invoices' => 0,
    'reviews' => 0,
];

// základné metriky + revenue (posledných 30 dní) + pending orders
$metrics = [
    'revenue_30d' => 0.0,
    'orders_pending' => 0,
];

try {
    foreach (['books','authors','users','orders','invoices','reviews'] as $t) {
        $q = $pdo->prepare("SELECT COUNT(*) FROM `{$t}`");
        $q->execute();
        $counts[$t] = (int)$q->fetchColumn();
    }

    // suma objednávok za posledných 30 dní (pokryť, ak stĺpec created_at chýba)
    try {
        $q = $pdo->prepare("SELECT SUM(total_price) FROM orders WHERE COALESCE(created_at, NOW()) >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
        $q->execute();
        $metrics['revenue_30d'] = (float)($q->fetchColumn() ?? 0.0);
    } catch (Throwable $e) {
        $metrics['revenue_30d'] = 0.0;
    }

    // pending
    try {
        $q = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE status = 'pending'");
        $q->execute();
        $metrics['orders_pending'] = (int)$q->fetchColumn();
    } catch (Throwable $e) {
        $metrics['orders_pending'] = 0;
    }

} catch (Throwable $e) {
    // log error a necháme údaje 0
    error_log('admin/index.php metrics error: ' . $e->getMessage());
}

// Najnovšie záznamy
$recentOrders = [];
$recentUsers = [];
$recentBooks = [];

try {
    $stmt = $pdo->query("SELECT o.id, o.total_price, o.status, COALESCE(o.created_at, '') AS created_at, u.meno AS user_name
                         FROM orders o LEFT JOIN users u ON o.user_id = u.id
                         ORDER BY COALESCE(o.created_at, '') DESC LIMIT 6");
    $recentOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $recentOrders = []; }

try {
    $stmt = $pdo->query("SELECT id, meno, email, COALESCE(created_at, datum_registracie, '') AS created_at FROM users ORDER BY COALESCE(created_at, '') DESC LIMIT 6");
    $recentUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $recentUsers = []; }

try {
    $stmt = $pdo->query("SELECT b.id, b.nazov, COALESCE(a.meno,'') AS autor, COALESCE(b.created_at, '') AS created_at
                         FROM books b LEFT JOIN authors a ON b.author_id = a.id
                         ORDER BY COALESCE(b.created_at, '') DESC LIMIT 6");
    $recentBooks = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $recentBooks = []; }

// zahrneme header partial (upravený podľa tvojich partials)
include __DIR__ . '/partials/header.php';
?>

<link rel="stylesheet" href="/admin/css/admin.css">
<main class="admin-main container" role="main" aria-label="Administrácia — prehľad">
  <section class="dashboard-hero card">
    <div class="hero-left">
      <h1>Prehľad systému</h1>
      <p class="muted">Rýchle metriky, posledná aktivita a najčastejšie akcie. Všetko pripravené na rýchle rozhodnutia.</p>
      <div class="dashboard-actions" role="group" aria-label="Rýchle akcie">
        <a class="btn-primary" href="/admin/books.php" title="Spravovať knihy">Spravovať knihy</a>
        <a class="btn" href="/admin/orders.php" title="Spravovať objednávky">Spravovať objednávky</a>
        <a class="btn" href="/admin/users.php" title="Spravovať užívateľov">Užívatelia</a>
        <a class="btn-ghost" id="smtp-test-btn" href="#" data-url="/admin/actions/test-smtp.php" title="Otestovať SMTP">Otestovať SMTP</a>
      </div>
    </div>

    <div class="hero-right">
      <div class="kpi-row">
        <div class="kpi">
          <div class="kpi-label">Tržby (30d)</div>
          <div class="kpi-value"><?php echo number_format($metrics['revenue_30d'], 2, ',', ' '); ?> €</div>
          <div class="kpi-muted small">Dohľadávajú sa objednávky z posledných 30 dní</div>
        </div>
        <div class="kpi">
          <div class="kpi-label">Objednávky čakajúce</div>
          <div class="kpi-value"><?php echo (int)$metrics['orders_pending']; ?></div>
          <div class="kpi-muted small">Stavy: pending / paid / fulfilled</div>
        </div>
        <div class="kpi">
          <div class="kpi-label">Užívatelia celkom</div>
          <div class="kpi-value"><?php echo (int)$counts['users']; ?></div>
          <div class="kpi-muted small">Aktívne účty</div>
        </div>
      </div>
    </div>
  </section>

  <section class="dashboard-stats">
    <div class="stats-grid">
      <article class="stat-card card" aria-labelledby="stat-books">
        <h3 id="stat-books">Knihy</h3>
        <div class="stat-num"><?php echo admin_esc($counts['books']); ?></div>
        <p class="small muted">Kategórie: <?php echo admin_esc((int)$counts['authors']); ?> autori</p>
        <div class="card-actions"><a class="btn" href="/admin/books.php">Otvoriť</a></div>
      </article>

      <article class="stat-card card" aria-labelledby="stat-authors">
        <h3 id="stat-authors">Autori</h3>
        <div class="stat-num"><?php echo admin_esc($counts['authors']); ?></div>
        <p class="small muted">Celkom autorov</p>
        <div class="card-actions"><a class="btn" href="/admin/authors.php">Otvoriť</a></div>
      </article>

      <article class="stat-card card" aria-labelledby="stat-orders">
        <h3 id="stat-orders">Objednávky</h3>
        <div class="stat-num"><?php echo admin_esc($counts['orders']); ?></div>
        <p class="small muted">Prehľad / vyrovnanie</p>
        <div class="card-actions"><a class="btn" href="/admin/orders.php">Spravovať</a> <a class="btn" href="/admin/exports.php?type=orders">Export</a></div>
      </article>

      <article class="stat-card card" aria-labelledby="stat-invoices">
        <h3 id="stat-invoices">Faktúry</h3>
        <div class="stat-num"><?php echo admin_esc($counts['invoices']); ?></div>
        <p class="small muted">Uložené PDF</p>
        <div class="card-actions"><a class="btn" href="/admin/invoices.php">Prehľad</a></div>
      </article>
    </div>
  </section>

  <section class="dashboard-widgets grid-3">
    <div class="widget card">
      <h4>Posledné objednávky</h4>
      <table class="table compact" aria-describedby="recent-orders-desc">
        <caption id="recent-orders-desc" class="sr-only">Posledné objednávky</caption>
        <thead><tr><th>ID</th><th>Užívateľ</th><th>Sum</th><th>Stav</th><th>Dátum</th></tr></thead>
        <tbody>
        <?php if (empty($recentOrders)): ?>
          <tr><td colspan="5">Žiadne objednávky</td></tr>
        <?php else: foreach ($recentOrders as $o): ?>
          <tr>
            <td><?php echo (int)$o['id']; ?></td>
            <td><?php echo admin_esc($o['user_name'] ?? '—'); ?></td>
            <td><?php echo admin_esc(number_format((float)$o['total_price'], 2, ',', ' ')); ?> €</td>
            <td><?php echo admin_esc($o['status']); ?></td>
            <td><?php echo admin_esc($o['created_at']); ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
      <div class="widget-actions"><a class="btn" href="/admin/orders.php">Zobraziť všetky</a></div>
    </div>

    <div class="widget card">
      <h4>Noví užívatelia</h4>
      <ul class="list-compact">
        <?php if (empty($recentUsers)): ?>
          <li>Žiadni užívatelia</li>
        <?php else: foreach ($recentUsers as $u): ?>
          <li><strong><?php echo admin_esc($u['meno']); ?></strong> — <span class="muted"><?php echo admin_esc($u['email']); ?></span> <small class="muted">(<?php echo admin_esc($u['created_at']); ?>)</small></li>
        <?php endforeach; endif; ?>
      </ul>
      <div class="widget-actions"><a class="btn" href="/admin/users.php">Spravovať užívateľov</a></div>
    </div>

    <div class="widget card">
      <h4>Nové knihy</h4>
      <ul class="list-compact">
        <?php if (empty($recentBooks)): ?>
          <li>Žiadne knihy</li>
        <?php else: foreach ($recentBooks as $b): ?>
          <li><strong><?php echo admin_esc($b['nazov']); ?></strong> — <span class="muted"><?php echo admin_esc($b['autor']); ?></span></li>
        <?php endforeach; endif; ?>
      </ul>
      <div class="widget-actions"><a class="btn" href="/admin/books.php">Spravovať knihy</a></div>
    </div>
  </section>

  <section class="dashboard-graph card" aria-label="Mini graf">
    <h4>Rýchly pomer: knihy / autori / užívatelia / objednávky</h4>
    <div id="dashboard-mini-chart"
         role="img"
         aria-label="Graf: pomer kníh, autorov, užívateľov, objednávok"
         data-books="<?php echo (int)$counts['books']; ?>"
         data-authors="<?php echo (int)$counts['authors']; ?>"
         data-users="<?php echo (int)$counts['users']; ?>"
         data-orders="<?php echo (int)$counts['orders']; ?>">
      <!-- SVG donut bude v JS vykreslené sem -->
    </div>
  </section>

  <section class="dashboard-extra card grid-2">
    <div>
      <h4>Rýchle exporty & nástroje</h4>
      <ul class="list-compact">
        <li><a class="btn" href="/admin/exports.php?type=books">Export kníh (CSV/XLSX)</a></li>
        <li><a class="btn" href="/admin/exports.php?type=users">Export užívateľov</a></li>
        <li><a class="btn" href="/admin/actions/generate-invoice.php?bulk=1" onclick="return confirm('Vygenerovať faktúry za nové objednávky?')">Vygenerovať faktúry (bulk)</a></li>
        <li><a class="btn" href="/admin/debug/lib-test.php" target="_blank">Diagnostic: libs test</a></li>
      </ul>
    </div>

    <div>
      <h4>Bezpečnostný stav</h4>
      <p class="small muted">Rýchla kontrola: session_status, disable_functions, HTTPS, file perms.</p>
      <ul class="list-compact small">
        <li>PHP verzia: <strong><?php echo admin_esc(PHP_VERSION); ?></strong></li>
        <li>HTTPS: <strong><?php echo (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'OK' : 'NIE'; ?></strong></li>
        <li>Allow URL fopen: <strong><?php echo ini_get('allow_url_fopen') ? 'ON' : 'OFF'; ?></strong></li>
      </ul>
      <div class="muted small">Pre detailný audit použite <a href="/admin/debug/lib-test.php" target="_blank">debug skript</a>.</div>
    </div>
  </section>
</main>

<?php include __DIR__ . '/partials/footer.php'; ?>

<!-- Lokálny JS (mini-chart + SMTP test) -->
<script>
(function(){
  // vykreslí jednoduchý donut chart do #dashboard-mini-chart
  const ch = document.getElementById('dashboard-mini-chart');
  if (ch) {
    const books = parseInt(ch.dataset.books||0,10);
    const authors = parseInt(ch.dataset.authors||0,10);
    const users = parseInt(ch.dataset.users||0,10);
    const orders = parseInt(ch.dataset.orders||0,10);
    const arr = [
      {label:'Knihy', val:books, color:'#cf9b3a'},
      {label:'Autori', val:authors, color:'#8b5a20'},
      {label:'Užívatelia', val:users, color:'#6a4518'},
      {label:'Objednávky', val:orders, color:'#3b2a1a'}
    ];
    const total = arr.reduce((s,i)=>s+i.val,0) || 1;
    // vytvoríme SVG
    const size = 220, cx = size/2, cy = size/2, r = 80;
    let svg = document.createElementNS('http://www.w3.org/2000/svg','svg');
    svg.setAttribute('viewBox','0 0 '+size+' '+size);
    svg.setAttribute('width','100%');
    svg.setAttribute('height','220');
    // legend
    let legend = document.createElement('div');
    legend.style.marginTop = '12px';
    legend.style.display = 'flex';
    legend.style.flexWrap = 'wrap';
    legend.style.gap = '8px';
    // draw arcs
    let start = -Math.PI/2;
    arr.forEach(function(group, idx){
      const portion = group.val/total;
      const end = start + portion * Math.PI * 2;
      const large = (end - start) > Math.PI ? 1 : 0;
      const x1 = cx + r * Math.cos(start);
      const y1 = cy + r * Math.sin(start);
      const x2 = cx + r * Math.cos(end);
      const y2 = cy + r * Math.sin(end);
      const d = `M ${cx} ${cy} L ${x1} ${y1} A ${r} ${r} 0 ${large} 1 ${x2} ${y2} Z`;
      const path = document.createElementNS('http://www.w3.org/2000/svg','path');
      path.setAttribute('d', d);
      path.setAttribute('fill', group.color);
      path.setAttribute('opacity', group.val===0 ? 0.06 : 0.95);
      svg.appendChild(path);
      start = end;
      // legend item
      const legendItem = document.createElement('div');
      legendItem.style.display='flex';
      legendItem.style.alignItems='center';
      legendItem.style.gap='8px';
      legendItem.style.fontSize='0.9rem';
      const sw = document.createElement('span');
      sw.style.width='12px'; sw.style.height='12px'; sw.style.background=group.color; sw.style.display='inline-block'; sw.style.borderRadius='2px';
      const txt = document.createElement('span');
      txt.textContent = group.label + ' — ' + group.val;
      legendItem.appendChild(sw); legendItem.appendChild(txt);
      legend.appendChild(legendItem);
    });
    ch.appendChild(svg);
    ch.appendChild(legend);
  }

  // SMTP test tlačidlo
  const smtpBtn = document.getElementById('smtp-test-btn');
  if (smtpBtn) {
    smtpBtn.addEventListener('click', async function(e){
      e.preventDefault();
      const url = this.dataset.url || '/admin/actions/test-smtp.php';
      const old = this.textContent;
      this.textContent = 'Testujem...';
      try {
        const res = await fetch(url, { method:'POST', credentials:'same-origin' });
        const j = await res.json();
        alert(j.ok ? 'SMTP OK: '+j.message : 'SMTP CHYBA: '+j.message);
      } catch (err) {
        alert('Chyba testu: ' + err);
      } finally {
        this.textContent = old;
      }
    });
  }
})();
</script>
<?php
// /admin/index.php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
require __DIR__ . '/bootstrap.php';
require_admin();

// Helper escape
if (!function_exists('admin_esc')) {
    function admin_esc($s) {
        if (function_exists('esc')) return esc($s);
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

// Získame štatistiky
$counts = [];
$tables = ['books','authors','users','orders','invoices','reviews'];
foreach ($tables as $t) {
    try {
        $counts[$t] = (int)$pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
    } catch (Throwable $e) {
        $counts[$t] = 0;
    }
}

// Najnovšie 5 objednávok
$recentOrders = [];
try {
    $stmt = $pdo->query("SELECT o.id, o.total_price, o.status, o.created_at, u.meno AS user_name FROM orders o LEFT JOIN users u ON o.user_id = u.id ORDER BY o.created_at DESC LIMIT 5");
    $recentOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e){ $recentOrders = []; }

// Najnovších 5 užívateľov
$recentUsers = [];
try {
    $stmt = $pdo->query("SELECT id, meno, email, datum_registracie FROM users ORDER BY datum_registracie DESC LIMIT 5");
    $recentUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e){ $recentUsers = []; }

// Najnovšie knihy
$recentBooks = [];
try {
    $stmt = $pdo->query("SELECT b.id, b.nazov, a.meno AS autor FROM books b LEFT JOIN authors a ON b.author_id = a.id ORDER BY b.created_at DESC LIMIT 5");
    $recentBooks = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e){ $recentBooks = []; }

// Include header
include __DIR__ . '/partials/header.php';
?>

<main class="admin-main container">
  <section class="dashboard-hero">
    <h1>Prehľad</h1>
    <p class="muted">Rýchly prehľad aktivity a rýchle odkazy na najpoužívanejšie akcie.</p>
    <div class="dashboard-actions">
      <a class="btn-primary" href="/admin/books.php">Spravovať knihy</a>
      <a class="btn" href="/admin/orders.php">Spravovať objednávky</a>
      <button id="smtp-test-btn" class="btn-ghost" data-url="/admin/actions/smtp-test.php">Otestovať SMTP</button>
    </div>
  </section>

  <section class="dashboard-stats">
    <div class="stats-grid">
      <div class="stat-card">
        <h3>Knihy</h3>
        <span class="stat-num"><?php echo admin_esc($counts['books'] ?? 0); ?></span>
      </div>
      <div class="stat-card">
        <h3>Autori</h3>
        <span class="stat-num"><?php echo admin_esc($counts['authors'] ?? 0); ?></span>
      </div>
      <div class="stat-card">
        <h3>Užívatelia</h3>
        <span class="stat-num"><?php echo admin_esc($counts['users'] ?? 0); ?></span>
      </div>
      <div class="stat-card">
        <h3>Objednávky</h3>
        <span class="stat-num"><?php echo admin_esc($counts['orders'] ?? 0); ?></span>
      </div>
    </div>
  </section>

  <section class="dashboard-widgets">
    <div class="widget">
      <h4>Posledné objednávky</h4>
      <table class="table">
        <thead><tr><th>ID</th><th>Užívatelia</th><th>Cena</th><th>Stav</th><th>Dátum</th></tr></thead>
        <tbody>
        <?php if (empty($recentOrders)): ?>
          <tr><td colspan="5">Žiadne objednávky</td></tr>
        <?php else: foreach ($recentOrders as $o): ?>
          <tr>
            <td><?php echo (int)$o['id']; ?></td>
            <td><?php echo admin_esc($o['user_name'] ?? '—'); ?></td>
            <td><?php echo admin_esc(number_format((float)$o['total_price'],2,',','.')) ?> €</td>
            <td><?php echo admin_esc($o['status']); ?></td>
            <td><?php echo admin_esc($o['created_at']); ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
      <div class="widget-actions">
        <a class="btn" href="/admin/orders.php">Zobraziť všetky objednávky</a>
        <a class="btn" href="/admin/exports.php?type=orders">Export (CSV)</a>
      </div>
    </div>

    <div class="widget">
      <h4>Poslední užívatelia</h4>
      <ul class="list-compact">
        <?php if (empty($recentUsers)): ?>
          <li>Žiadni užívatelia</li>
        <?php else: foreach ($recentUsers as $u): ?>
          <li><?php echo admin_esc($u['meno']); ?> — <span class="muted"><?php echo admin_esc($u['email']); ?></span> <small class="muted">(<?php echo admin_esc($u['datum_registracie']); ?>)</small></li>
        <?php endforeach; endif; ?>
      </ul>
      <div class="widget-actions">
        <a class="btn" href="/admin/users.php">Spravovať užívateľov</a>
        <a class="btn" href="/admin/exports.php?type=users">Export (CSV)</a>
      </div>
    </div>

    <div class="widget">
      <h4>Posledné knihy</h4>
      <ul class="list-compact">
        <?php if (empty($recentBooks)): ?>
          <li>Žiadne knihy</li>
        <?php else: foreach ($recentBooks as $b): ?>
          <li><?php echo admin_esc($b['nazov']); ?> — <span class="muted"><?php echo admin_esc($b['autor']); ?></span></li>
        <?php endforeach; endif; ?>
      </ul>
      <div class="widget-actions">
        <a class="btn" href="/admin/books.php">Spravovať knihy</a>
      </div>
    </div>
  </section>

  <section class="dashboard-graph">
    <h4>Rýchly graf (zobrazuje pomer)</h4>
    <div id="dashboard-mini-chart" data-books="<?php echo (int)$counts['books']; ?>" data-authors="<?php echo (int)$counts['authors']; ?>" data-users="<?php echo (int)$counts['users']; ?>" data-orders="<?php echo (int)$counts['orders']; ?>"></div>
  </section>

</main>

<?php include __DIR__ . '/partials/footer.php'; ?>
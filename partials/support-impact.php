<?php
// partials/support-impact.php
// Sekcia "Podpora a dopad" - načítava štatistiky z DB a zobrazí progress, odhady.

$pdo = null;
$candidates = [
    __DIR__ . '/../db/config/config.php',
    __DIR__ . '/db/config/config.php',
    __DIR__ . '/../../db/config/config.php',
];
foreach ($candidates as $c) {
    if (file_exists($c)) {
        $maybe = require $c;
        if ($maybe instanceof PDO) { $pdo = $maybe; break; }
        if (isset($pdo) && $pdo instanceof PDO) break;
    }
}

if (!function_exists('esc_si')) {
    function esc_si($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

// default values
$totalSales = 0.0;
$totalOrders = 0;
$totalBooksSold = 0;
$supportPercent = 10; // percent from settings? fallback
$supportEnabled = true;

// gather data
if ($pdo instanceof PDO) {
    try {
        // total revenue from paid orders
        $totalSales = (float)$pdo->query("SELECT COALESCE(SUM(total_price),0) FROM orders WHERE status='paid'")->fetchColumn();

        // total orders paid
        $totalOrders = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status='paid'")->fetchColumn();

        // total books sold (sum of order_items for paid orders)
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(oi.quantity),0) FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE o.status='paid'");
        $stmt->execute();
        $totalBooksSold = (int)$stmt->fetchColumn();

        // read setting support_babybox and support percent (if stored)
        $st = $pdo->prepare("SELECT v FROM settings WHERE k = ?");
        $st->execute(['support_babybox']);
        $supportEnabled = ($st->fetchColumn() === '1');

        $st->execute(['support_percent']);
        $sp = $st->fetchColumn();
        if ($sp !== false && is_numeric($sp)) $supportPercent = (int)$sp;
    } catch (Throwable $e) {
        error_log("support-impact.php SQL error: " + e.message);
    }
}

// estimate donated amount
$donated = $totalSales * ($supportPercent / 100.0);
?>
<link rel="stylesheet" href="/css/support-impact.css">

<section class="supportimpact-section" aria-label="Podpora a dopad">
  <div class="supportimpact-paper-wrap">
    <span class="supportimpact-grain-overlay" aria-hidden="true"></span>

    <div class="supportimpact-container">
      <header class="supportimpact-head">
        <h2 class="supportimpact-title">Tvoj nákup má zmysel</h2>
        <p class="supportimpact-text">Časť výťažku systematicky putuje na podporu babyboxov a charitatívnych projektov.</p>
      </header>

      <div class="supportimpact-stats" role="region" aria-label="Štatistiky podpory">
        <div class="supportimpact-item">
          <strong class="si-number"><?php echo esc_si(number_format($totalBooksSold,0,'',' ')); ?></strong>
          <span class="si-label">Predané knihy</span>
        </div>
        <div class="supportimpact-item">
          <strong class="si-number"><?php echo esc_si(number_format($totalOrders,0,'',' ')); ?></strong>
          <span class="si-label">Splatené objednávky</span>
        </div>
        <div class="supportimpact-item">
          <strong class="si-number"><?php echo esc_si(number_format($totalSales,2,',',' ')); ?> €</strong>
          <span class="si-label">Celkové tržby</span>
        </div>
        <div class="supportimpact-item">
          <strong class="si-number"><?php echo esc_si(number_format($donated,2,',',' ')); ?> €</strong>
          <span class="si-label">Odhadovaná darovaná suma (<?php echo esc_si($supportPercent); ?>%)</span>
        </div>
      </div>

      <div class="supportimpact-highlight">
        <?php if ($supportEnabled): ?>
          Ďakujeme — podpora babyboxov je aktívna. <?php echo esc_si(number_format($donated,2,',',' ')); ?> € bolo venované z odhadovaných tržieb.
        <?php else: ?>
          Momentálne nie je podpora babyboxov aktívna. Kontaktuj administrátora pre viac informácií.
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>

<script src="/js/support-impact.js" defer></script>

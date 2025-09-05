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
          error_log("support-impact.php SQL error: " . $e->getMessage());
        }
}

// estimate donated amount
$donated = $totalSales * ($supportPercent / 100.0);
?>
<link rel="stylesheet" href="/css/support-impact.css">

<section class="style-section" aria-label="Podpora a dopad">
  <div class="paper-wrap">
    <span class="paper-grain-overlay" aria-hidden="true"></span>
    <span class="paper-edge" aria-hidden="true"></span>

    <div class="central-container">
      <div class="central-steps" role="region" aria-label="Štatistiky podpory">
        <div class="supportimpact-head">
        <h1 class="section-title" data-lines="2">Tvoj nákup má <span>zmysel</span></h1>
        <p class="section-subtitle">Časť výťažku systematicky putuje na podporu babyboxov a charitatívnych projektov.</p>
        </div>
        <div class="supportimpact-item">
            <div class="supportimpact-icon" aria-hidden="true">
            <img src="/assets/kniha2.png" alt="ikona pečate">
            </div>
          <div class="supportimpact-item-textbox">
          <h3 class="section-title supportimpact-text1"><span><?php echo esc_si(number_format($totalBooksSold,0,'',' ')); ?></span></h3>
          <h3 class="section-title supportimpact-text1" data-lines="2">Predané knihy</h3>
          </div>
        </div>
        <div class="supportimpact-item">
            <div class="supportimpact-icon" aria-hidden="true">
            <img src="/assets/faktura.png" alt="ikona pečate">
            </div>
          <div class="supportimpact-item-textbox">
          <h3 class="section-title supportimpact-text2"><span><?php echo esc_si(number_format($totalOrders,0,'',' ')); ?></span></h3>
          <h3 class="section-title supportimpact-text2" data-lines="2">Splatené objednávky</h3>
          </div>
        </div>
        <div class="supportimpact-item">
            <div class="supportimpact-icon" aria-hidden="true">
            <img src="/assets/mince2.png" alt="ikona pečate">
            </div>
          <div class="supportimpact-item-textbox">
          <h3 class="section-title supportimpact-text2"><span><?php echo esc_si(number_format($totalSales,2,',',' ')); ?> €</span></h3>
          <h3 class="section-title supportimpact-text3" data-lines="2">Celkové tržby</h3>
          </div>
        </div>
        <div class="supportimpact-item">
            <div class="supportimpact-icon" aria-hidden="true">
            <img src="/assets/dieta.png" alt="ikona pečate">
            </div>
          <div class="supportimpact-item-textbox">
          <h3 class="section-title supportimpact-text4"><span><?php echo esc_si(number_format($donated,2,',',' ')); ?> €</span></h3>
          <h3 class="section-title supportimpact-text4"  data-lines="2">Darovaná suma</h3>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<script src="/js/support-impact.js" defer></script>

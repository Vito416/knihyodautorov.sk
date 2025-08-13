<?php
// /admin/partials/footer.php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../bootstrap.php';

$siteName = 'Knihy od Autorov';
$year = (int)date('Y');
$ver = 'admin v1.0';
?>
<!-- ukončenie main a footer -->
</main>

<footer class="admin-footer" role="contentinfo">
  <div class="admin-footer-inner">
    <div class="footer-left">
      <div class="footer-brand">
        <strong><?php echo admin_esc($siteName); ?></strong>
        <div class="small muted">Správa obsahu a objednávok</div>
      </div>
    </div>

    <div class="footer-center">
      <nav class="footer-links" aria-label="Rýchle odkazy do adminu">
        <a href="/admin/index.php">Prehľad</a>
        <a href="/admin/books.php">Knihy</a>
        <a href="/admin/orders.php">Objednávky</a>
        <a href="/admin/exports.php">Exporty</a>
      </nav>
    </div>

    <div class="footer-right">
      <div class="small muted">© <?php echo (int)$year; ?> Knihy od Autorov — <?php echo admin_esc($ver); ?></div>
      <a class="btn btn-ghost" href="/" target="_blank" rel="noopener">Zobraziť web</a>
    </div>
  </div>

  <!-- jemná dekorácia (SVG) -->
  <svg class="footer-decor" viewBox="0 0 100 20" preserveAspectRatio="none" aria-hidden="true">
    <path d="M0 10 C20 0, 80 20, 100 10" stroke="#cf9b3a" stroke-opacity="0.08" stroke-width="2" fill="none"/>
  </svg>
</footer>

</body>
</html>
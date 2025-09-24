<?php
declare(strict_types=1);
$year = date('Y');
$appName = $_ENV['APP_NAME'] ?? 'Knižnica Stratégov';
?>
</main> <!-- #content -->

<footer class="site-footer epic-footer" role="contentinfo">
  <div class="wrap">
    <div class="footer-left">
      <div class="footer-brand">
        <img src="/assets/crest-small.png" alt="" class="footer-crest">
        <span>&copy; <?= (int)$year ?> <?= htmlspecialchars($appName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>.</span>
      </div>
      <div class="footer-desc">Historické knihy • Herné prvky • Epický zážitok</div>
    </div>
    <div class="footer-right">
      <a href="/eshop/privacy.php">Ochrana osobných údajov</a> |
      <a href="/eshop/terms.php">Obchodné podmienky</a> |
      <a href="/eshop/contact.php">Kontakt</a>
      <div style="margin-top:.5rem;">
        <button id="ambient-toggle" class="btn btn-ghost" aria-pressed="false" title="Ambientná hudba">🎵 Ambient</button>
        <a href="/eshop/rss.php" class="btn btn-ghost" title="RSS">📡 RSS</a>
      </div>
    </div>
    <div style="clear:both;"></div>
  </div>
</footer>

<script src="/eshop/js/app.js" defer></script>
</body>
</html>
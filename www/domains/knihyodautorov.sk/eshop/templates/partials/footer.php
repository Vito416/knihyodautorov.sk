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

      <!-- NOVÝ BLOK: firemní informace -->
      <div class="footer-info" style="margin-top: 1rem; font-size: 0.85rem; color:#ccc;">
        <strong>Obchodné meno:</strong> Black Cat Academy s. r. o.<br>
        <strong>Sídlo:</strong> Dolná ulica 1C, Kunerad 013 13<br>
        <strong>IČO:</strong> 55 396 461<br>
        <strong>Kontakt:</strong> <a href="mailto:info@knihyodautorov.sk" style="color:#ccc;">info@knihyodautorov.sk</a>, 
        <a href="tel:+421901770666" style="color:#ccc;">+421 901 770 666</a>
      </div>
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

  <!-- zbytek footeru a modalu zůstává beze změny -->
  <div id="bookModal" class="modal" aria-hidden="true">
    <div class="modal-overlay" aria-hidden="true"></div>
    <div class="panel" role="dialog" aria-modal="true" aria-label="Detail knihy">
      <button class="modal-close" aria-label="Zavřít">&times;</button>
      <div class="modal-inner"><div class="modal-body">
        <!-- AJAX detail se sem načte -->
      </div></div>
    </div>
  </div>
</footer>

<script defer src="/eshop/js/app.js"></script>
<script defer src="/eshop/js/header.js"></script>
<script defer src="/eshop/js/header-cart.js"></script> <!-- před flash! -->
<script defer src="/eshop/js/catalog.js"></script>
<script defer src="/eshop/js/cart-ajax-flash.js"></script>
<script src="/eshop/js/checkout-submit.js"></script> <!-- po flash! -->

<?php
$flashes = $_SESSION['flash'] ?? null;
if (!empty($flashes) && is_array($flashes)):
    // bezpečně připravit JSON pro vložení do skriptu
    $jsonFlashes = json_encode($flashes, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
    // odstranit ze session — server už je předal (fallback je v <noscript>)
    unset($_SESSION['flash']);
?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  try {
    const flashes = <?php echo $jsonFlashes; ?>;
    if (!Array.isArray(flashes) || flashes.length === 0) return;

    // pokud máme JS widget, použij ho
    if (window.__createFlash && typeof window.__createFlash === 'function') {
      flashes.forEach(function(f) {
        window.__createFlash(f.message || '', { type: f.type || 'info', timeout: (f.timeout || 5000) });
      });
    } else {
      // pokud není widget (starší stránky), fallback: vložit jednoduchý noscript div
      var wrapper = document.createElement('div');
      wrapper.style.position = 'fixed';
      wrapper.style.right = '1rem';
      wrapper.style.bottom = '1rem';
      wrapper.style.zIndex = 99999;
      flashes.forEach(function(f) {
        var d = document.createElement('div');
        d.textContent = f.message || '';
        d.style.background = '#111';
        d.style.color = '#fff';
        d.style.padding = '0.6rem 0.9rem';
        d.style.borderRadius = '8px';
        d.style.marginTop = '.4rem';
        wrapper.appendChild(d);
      });
      document.body.appendChild(wrapper);
      // auto-remove after a while (best-effort)
      setTimeout(function(){ try{ document.body.removeChild(wrapper); } catch(_){} }, 7000);
    }
  } catch (err) {
    // ticho — nechceme rusit vykresleni stranky
    console.error(err);
  }
});
</script>

<noscript>
  <?php foreach ($flashes as $f): 
    $type = $f['type'] ?? 'info';
    $msg = $f['message'] ?? '';
    $class = 'flash-info';
    if ($type === 'success') $class = 'flash-success';
    if ($type === 'warning') $class = 'flash-warning';
    if ($type === 'error') $class = 'flash-error';
  ?>
    <div class="<?= htmlspecialchars($class) ?>" style="position:fixed; right:1rem; bottom:1rem; z-index:99999; padding:.6rem .9rem; border-radius:8px; color:#fff; background:#222; margin-top:.4rem;">
      <?= nl2br(htmlspecialchars((string)$msg, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?>
    </div>
  <?php endforeach; ?>
</noscript>

<?php endif; ?>

</body>
</html>
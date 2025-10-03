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

      <!-- Firemní informace -->
      <div class="footer-info" style="margin-top: 1rem; font-size: 0.85rem; color:#ccc;">
        <strong>Obchodné meno:</strong> Black Cat Academy s. r. o.<br>
        <strong>Sídlo:</strong> Dolná ulica 1C, Kunerad 013 13<br>
        <strong>IČO:</strong> 55 396 461<br>
        <strong>Kontakt:</strong> 
        <a href="mailto:info@knihyodautorov.sk" style="color:#ccc;">info@knihyodautorov.sk</a>, 
        <a href="tel:+421901770666" style="color:#ccc;">+421 901 770 666</a>
      </div>

      <div class="payment-methods" style="margin-top:1rem;">
        <a href="https://www.gopay.cz" target="_blank">
          <img src="https://help.gopay.com/img.php?hash=6839a31109d2573ce58c6b2b52a099aae7d7c047a8fe0bdd54ebbc10b54b49bb.png" alt="GoPay" style="height:32px; margin-right:0.5rem;">
        </a>
        <a href="https://www.gopay.cz" target="_blank">
          <img src="https://help.gopay.com/img.php?hash=3f16ee624dcff569b03ab83c0bc797561eeac7c6103ec90783f6d37390921eab.png" alt="GoPay" style="height:32px; margin-right:0.5rem;">
        </a>
      </div>
      <!-- Loga platebních metod přes odkaz -->
      <div class="payment-methods" style="margin-top:1rem;">
        <a href="https://www.visa.com" target="_blank">
          <img src="https://help.gopay.com/img.php?hash=f4ff2c1d9aa413c4d1e314c46ad715ad19c1abde59ae1f109271cc35610169d0.png" alt="Visa" style="height:32px; margin-right:0.5rem;">
        </a>
        <a href="https://www.visa.com" target="_blank">
          <img src="https://help.gopay.com/img.php?hash=474ac07c97a45fa24445c9ee8713089491c861c066c86f1a1c5818e94f5d96d5.png" alt="Visa" style="height:32px; margin-right:0.5rem;">
        </a>
        <a href="https://www.mastercard.com" target="_blank">
          <img src="https://help.gopay.com/img.php?hash=9229adf70f3a25146c64f477392b8b17c5ec9333285b6e6229fdd89e5ad55047.png" alt="MasterCard" style="height:32px; margin-right:0.5rem;">
        </a>
        <a href="https://www.mastercard.com" target="_blank">
          <img src="https://help.gopay.com/cs/img.php?hash=9faf331b11e48cb7e13a95ecd22ffa5fa1e42dfdfe6705f8e4e20b235a1e8ccd.png" alt="MasterCard" style="height:32px; margin-right:0.5rem;">
        </a>
        <a href="https://www.maestro.com" target="_blank">
          <img src="https://help.gopay.com/img.php?hash=d2f8644e6ede034dede054af6957f17ee984b5e29de33d8d104657cf5bbac984.png" alt="Maestro" style="height:32px;">
        </a>
      </div>
    </div>
          <div class="payment-methods" style="margin-top:1rem;">
        <a href="https://www.visa.com" target="_blank">
          <img src="https://help.gopay.com/cs/img.php?hash=6efd47e6022b111fee1d9fb862a93c57d279a0a060adc354c4de49308a23f572.png" alt="Visa" style="height:32px; margin-right:0.5rem;">
        </a>
        <a href="https://www.mastercard.com" target="_blank">
          <img src="https://help.gopay.com/img.php?hash=bc6253cf22823dc847c98dc3623af7f3bd7ba712371a7dcfd7882f56dbc933b2.png" alt="MasterCard" style="height:32px; margin-right:0.5rem;">
        </a>
      </div>
    </div>

    <div class="footer-right">
      <a href="/eshop/gdpr">Ochrana osobných údajov</a> |
      <a href="/eshop/vop">Obchodné podmienky</a> |
      <a href="/eshop/reklamacie">Reklamačný poriadok</a> |
      <a href="/eshop/contact">Kontakt</a>
      <div style="margin-top:.5rem;">
        <button id="ambient-toggle" class="btn btn-ghost" aria-pressed="false" title="Ambientná hudba">🎵 Ambient</button>
        <a href="/eshop/rss.php" class="btn btn-ghost" title="RSS">📡 RSS</a>
      </div>
    </div>

    <div style="clear:both;"></div>
  </div>

  <!-- Modal -->
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
<script defer src="/eshop/js/header-cart.js"></script>
<script defer src="/eshop/js/catalog.js"></script>
<script defer src="/eshop/js/cart-ajax-flash.js"></script>
<script src="/eshop/js/checkout-submit.js"></script>

<?php
$flashes = $_SESSION['flash'] ?? null;
if (!empty($flashes) && is_array($flashes)):
    $jsonFlashes = json_encode($flashes, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
    unset($_SESSION['flash']);
?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  try {
    const flashes = <?php echo $jsonFlashes; ?>;
    if (!Array.isArray(flashes) || flashes.length === 0) return;

    if (window.__createFlash && typeof window.__createFlash === 'function') {
      flashes.forEach(f => window.__createFlash(f.message || '', { type: f.type || 'info', timeout: f.timeout || 5000 }));
    } else {
      const wrapper = document.createElement('div');
      wrapper.style.position = 'fixed';
      wrapper.style.right = '1rem';
      wrapper.style.bottom = '1rem';
      wrapper.style.zIndex = 99999;
      flashes.forEach(f => {
        const d = document.createElement('div');
        d.textContent = f.message || '';
        d.style.background = '#111';
        d.style.color = '#fff';
        d.style.padding = '0.6rem 0.9rem';
        d.style.borderRadius = '8px';
        d.style.marginTop = '.4rem';
        wrapper.appendChild(d);
      });
      document.body.appendChild(wrapper);
      setTimeout(() => { try { document.body.removeChild(wrapper); } catch(_){} }, 7000);
    }
  } catch (err) { console.error(err); }
});
</script>

<noscript>
  <?php foreach ($flashes as $f): 
    $type = $f['type'] ?? 'info';
    $msg = $f['message'] ?? '';
    $class = match($type) {
        'success' => 'flash-success',
        'warning' => 'flash-warning',
        'error'   => 'flash-error',
        default   => 'flash-info',
    };
  ?>
    <div class="<?= htmlspecialchars($class) ?>" style="position:fixed; right:1rem; bottom:1rem; z-index:99999; padding:.6rem .9rem; border-radius:8px; color:#fff; background:#222; margin-top:.4rem;">
      <?= nl2br(htmlspecialchars((string)$msg, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?>
    </div>
  <?php endforeach; ?>
</noscript>

<?php endif; ?> <!-- KONEC IF FLASH -->
</body>
</html>
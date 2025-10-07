<?php
declare(strict_types=1);

$year = date('Y');
$appName = $_ENV['APP_DOMAIN'] ?? 'Knižnica Stratégov';
?>
</main> <!-- #content -->
<link rel="stylesheet" href="/eshop/css/footer.css">
<footer class="site-footer" role="contentinfo">
  <div class="container-footer">
    <div class="footer-grid">
      <div class="footer-card">
        <div class="footer-icon">
          <img src="/eshop/assets/footer/kapuce.png" alt="kapuce" class="footer-kapuce-icon">
        </div>
          <div class="footer-text">
            <p>Vydavateľstvo a internetový obchod</p>
            <h2>Knihy od autorov</h2>
            <p>Originálne knihy od slovenských a českých autorov z oblasti osobného rozvoja, biznisu, marketingu, psychológie a zdravia.</p>
          </div>
        </div>
      <div class="footer-card">
        <div class="footer-icon">
          <img src="/eshop/assets/footer/informace.png" alt="informace" class="footer-informace-icon">
        </div>
          <div class="footer-navigation">
            <a href="/eshop/gdpr">Ochrana osobných údajov</a>
            <a href="/eshop/vop">Obchodné podmienky</a>
            <a href="/eshop/reklamacie">Reklamačný poriadok</a>
            <a href="/eshop/contact">Kontakt</a>
          </div>
        </div>
      <div class="footer-card">
        <div class="footer-icon">
          <img src="/eshop/assets/footer/kategorie.png" alt="kategorie" class="footer-kategorie-icon">
        </div>
          <form action="">
            <label for="newsletter-email" class="footer-newsletter-label">Prihláste sa na odber noviniek:</label>
            <input type="email" id="newsletter-email" name="email" placeholder="Váš e-mail" required>
            <button type="submit" class="btn btn-newsletter">Odoberať</button>
          </form>
        </div>
      <div class="footer-card no-border">
        <div class="footer-icon">
          <img src="/eshop/assets/footer/obalka.png" alt="obalka" class="footer-obalka-icon">
        </div>
          <!-- Firemní informace -->
          <div class="footer-contact-info">
            <p><strong>Obchodné meno:</strong> Black Cat Academy s. r. o.</p>
            <p><strong>Sídlo:</strong> Dolná ulica 1C, Kunerad 013 13</p>
            <p><strong>IČO:</strong> 55 396 461</p>
            <p><strong>Kontakt:</strong> 
            <a href="mailto:info@knihyodautorov.sk" style="color:#ccc;">info@knihyodautorov.sk</a>, 
            <a href="tel:+421901770666" style="color:#ccc;">+421 901 770 666</a></p>
          </div>
        </div>
      </div>
    <div style="clear:both;"></div>
  </div>
  <div class="footer-copyright">
    <div class="footer-copyright-text">
      <span>&copy; <?= (int)$year ?></br><?= htmlspecialchars($appName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
    </div>
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
  </div>
</footer>

<script defer src="/eshop/js/app.js"></script>
<script defer src="/eshop/js/main-page.js"></script>
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
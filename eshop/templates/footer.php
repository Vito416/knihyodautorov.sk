<?php
/**
 * /eshop/templates/footer.php
 * Vylepšený footer: newsletter, odkazy, back-to-top, načítanie JS (extraJs)
 */
?>

  <footer class="site-footer" role="contentinfo">
    <div class="container footer__inner" style="display:grid;grid-template-columns:1fr 1fr 320px;gap:1.25rem;align-items:start;padding:2rem 0;">
      <div class="footer__col">
        <h4>O projekte</h4>
        <p>Kurátorský výber nezávislých autorov. Digitálne knihy s okamžitým prístupom a profesionálnymi faktúrami.</p>
      </div>

      <div class="footer__col">
        <h4>Odkazy</h4>
        <ul style="list-style:none;padding:0;margin:0;">
          <li><a href="/eshop/catalog.php">Katalóg</a></li>
          <li><a href="/eshop/account/login.php">Prihlásiť</a></li>
          <li><a href="/admin">Admin</a></li>
        </ul>
      </div>

      <div class="footer__col">
        <h4>Newsletter</h4>
        <form action="/eshop/actions/newsletter-subscribe.php" method="post" class="newsletter">
          <?php if (function_exists('csrf_field')) csrf_field('newsletter'); ?>
          <label for="newsletter-email" class="visually-hidden">Váš e-mail</label>
          <input id="newsletter-email" type="email" name="email" placeholder="Váš e-mail" required style="width:100%;padding:.5rem;border-radius:8px;border:1px solid rgba(255,255,255,0.04);background:rgba(0,0,0,0.45);color:#fff;">
          <button class="btn" type="submit" style="margin-top:.5rem;">Odoberať</button>
        </form>
      </div>
    </div>

    <div class="container footer__bottom" style="display:flex;align-items:center;justify-content:space-between;padding:1rem 0;border-top:1px solid rgba(255,255,255,0.02);">
      <div>© <?= date('Y'); ?> Knihy od Autorov — Všetky práva vyhradené</div>
      <div>
        <a href="/privacy.php">Ochrana osobných údajov</a> · <a href="/terms.php">Podmienky</a>
      </div>
    </div>

    <button id="back-to-top" aria-label="Naspäť hore" style="position:fixed;right:18px;bottom:18px;border-radius:12px;padding:.6rem .8rem;border:0;background:linear-gradient(135deg,var(--gold-2),var(--gold-1));color:#2b1d0f;box-shadow:0 10px 30px rgba(0,0,0,0.6);display:none;">↑</button>
  </footer>

  <!-- Globálny JS e-shopu (základný) -->
  <script src="/eshop/js/eshop.js" defer></script>

  <!-- Extra JS pre danú stránku -->
  <?php if (!empty($extraJs) && is_array($extraJs)): foreach ($extraJs as $js): ?>
    <script src="<?= htmlspecialchars($js, ENT_QUOTES|ENT_HTML5); ?>" defer></script>
  <?php endforeach; endif; ?>

  <script>
    // Malé client-side zlepšenia (bez závislostí)
    (function(){
      'use strict';
      // mobile menu toggle
      const menuToggle = document.querySelector('.menu-toggle');
      const nav = document.querySelector('.nav');
      if (menuToggle && nav) {
        menuToggle.addEventListener('click', function(){
          const expanded = this.getAttribute('aria-expanded') === 'true';
          this.setAttribute('aria-expanded', String(!expanded));
          nav.classList.toggle('open');
        });
      }

      // back-to-top
      const btt = document.getElementById('back-to-top');
      window.addEventListener('scroll', function(){
        if (window.scrollY > 400) btt.style.display = 'block'; else btt.style.display = 'none';
      }, { passive: true });
      btt.addEventListener('click', function(){ window.scrollTo({top:0, behavior:'smooth'}); });

      // accessible focus: add visible focus for keyboard users
      document.addEventListener('keyup', function(e){ if (e.key === 'Tab') document.body.classList.add('show-focus'); });
    })();
  </script>

</body>
</html>

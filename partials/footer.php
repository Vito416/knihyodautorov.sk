<link rel="stylesheet" href="/css/footer.css" />

<footer class="site-footer" role="contentinfo" aria-label="Patička stránky">
  <div class="footer-overlay" aria-hidden="true"></div>

  <div class="footer-content">
    <!-- 1. LOGO / ABOUT -->
    <div class="footer-col footer-logo" aria-label="O projektu">
      <img src="/assets/logoobdelnikbezpozadi.png" alt="Knihy od Autorov" onerror="this.src='/assets/logoobdelnikbezpozadi.png';">
      <h2>Knihy od Autorov</h2>
      <p class="footer-tagline">Propojujeme autory a čtenáře — část výdělku podporuje babyboxy.</p>
    </div>

    <!-- 2. QUICK LINKS -->
    <nav class="footer-col footer-links" aria-label="Rychlé odkazy">
      <h3>Rychlé odkazy</h3>
      <ul>
        <li><a href="#about">O nás</a></li>
        <li><a href="#books">Knihy</a></li>
        <li><a href="#support">Podpora</a></li>
        <li><a href="/privacy.php">Ochrana soukromí</a></li>
      </ul>
    </nav>

    <!-- 3. SOCIALS + SUBSCRIBE -->
    <div class="footer-col footer-social" aria-label="Sledujte a přihlaste se k odběru">
      <h3>Sledujte & Odebírejte</h3>

      <div class="social-icons" aria-hidden="false">
        <a href="#" class="social-link" aria-label="Facebook"><i class="fab fa-facebook-f" aria-hidden="true"></i></a>
        <a href="#" class="social-link" aria-label="Twitter"><i class="fab fa-twitter" aria-hidden="true"></i></a>
        <a href="#" class="social-link" aria-label="Instagram"><i class="fab fa-instagram" aria-hidden="true"></i></a>
        <a href="#" class="social-link" aria-label="YouTube"><i class="fab fa-youtube" aria-hidden="true"></i></a>
      </div>

      <form class="subscribe-form" action="/subscribe" method="post" novalidate aria-label="Přihlášení k odběru newsletteru">
        <label for="subscribe-email" class="visually-hidden">E-mail pro odběr</label>
        <div class="subscribe-row">
          <input id="subscribe-email" name="email" type="email" placeholder="Váš email" required autocomplete="email" />
          <button type="submit" class="btn-subscribe" aria-label="Přihlásit k odběru">Odebírat</button>
        </div>
        <div class="subscribe-note">Žádné spamování — jen novinky a tipy. <a href="/privacy.php">Zásady</a></div>
      </form>
    </div>

    <!-- 4. CONTACT -->
    <address class="footer-col footer-contact" aria-label="Kontakt">
      <h3>Kontaktujte nás</h3>
      <p class="contact-line"><strong>Email:</strong> <a href="mailto:info@knihyodautorov.example">info@knihyodautorov.example</a></p>
      <p class="contact-line"><strong>Tel:</strong> <a href="tel:+420123456789">+420 123 456 789</a></p>
      <p class="contact-line small">Pro obchodní dotazy, prosím použijte e-mail nebo kontaktní formulář.</p>
    </address>
  </div>

  <div class="footer-bottom" role="note">
    <p>© <?php echo date("Y"); ?> Knihy od Autorov — Část výdělku věnujeme babyboxům ❤️</p>
  </div>
</footer>

<script src="/js/footer.js" defer></script>
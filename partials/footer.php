<link rel="stylesheet" href="/css/footer.css" />

<footer class="footer-section paper-wrap" role="contentinfo" aria-label="Pätička stránky">
  <span class="paper-grain-overlay" aria-hidden="true"></span>
  <span class="paper-edge" aria-hidden="true"></span>
  <div class="footer-content">

  <!-- 1) LOGO / O PROJEKTE  -->
  <div class="footer-panel">
    <div class="footer-text">
    <h2 class="section-title"><span>Knihy</span> od Autorov</h2>
    <p class="section-subtitle dropcap">
      Spájame autorov a čitateľov každý nákup pomáha babyboxom.
      Náš projekt podporuje lokálnu tvorbu, férové odmeny autorom a ponúka
      pohodlné sťahovanie legálnych PDF titulov. Veríme, že kvalitná literatúra
      má byť dostupná pre každého.
    </p>
    </div>
    <div class="footer-logo" >
    <img src="/assets/footer-logo.png" alt="Knihy od Autorov">
    </div>
  </div>

<!-- 2) RÝCHLE ODKAZY (s ikonami; odlišná titulná ikona) -->
  <div class="footer-panel">
    <div class="footer-links-container">
    <div class="footer-text">
    <h2 class="section-title"><span>Rýchle</span> odkazy</h2>
    </div>

    <ul class="footer-links-list section-subtitle dropcap" role="list" aria-label="Rýchle odkazy - zoznam">
      <li class="footer-link-item" role="listitem">
        <a href="/" class="footer-link footer-home" title="Domov" aria-label="Domov">
        <span class="link-text">Domov</span>
        </a>
      </li>
      <li class="footer-link-item" role="listitem">
        <a href="/samples" class="footer-link footer-samples" title="Ukážky kníh" aria-label="Ukážky kníh">
          <span class="link-text">Ukážky kníh</span>
        </a>
      </li>
      <li class="footer-link-item" role="listitem">
        <a href="/authors" class="footer-link footer-authors" title="Autori" aria-label="Autori">
          <span class="link-text">Autori</span>
        </a>
      </li>
      <li class="footer-link-item" role="listitem">
        <a href="/about" class="footer-link footer-about" title="O nás" aria-label="O nás">
          <span class="link-text">O nás</span>
        </a>
      </li>
      <li class="footer-link-item" role="listitem">
        <a href="/shop" class="footer-link footer-eshop" title="E-shop" aria-label="E-shop">
          <span class="link-text">E-shop</span>
        </a>
      </li>
      <li class="footer-link-item" role="listitem">
        <a href="/privacy.php" class="footer-link footer-gdpr" title="Ochrana osobných údajov" aria-label="Ochrana osobných údajov">
          <span class="link-text">GDPR</span>
        </a>
      </li>
    </ul>
    </div>
  </div>

<!-- 3) KOMPAKTNÝ KONTAKTNÝ FORMULÁR (s ikonami) -->
  <div class="footer-panel">
    <div class="footer-text">
    <h2 class="section-title"><span>Napíšte</span> nám</h2>
    </div>
    <div class="section-subtitle">
      <form class="contact-form" action="/contact-submit" method="post" novalidate>
      <!-- name -->
      <label class="visually-hidden" for="f-name">Vaše meno</label>
      <div class="input-row">
        <span class="input-icon" aria-hidden="true">
          <!-- user svg -->
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false">
            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            <circle cx="12" cy="7" r="4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </span>
        <input id="f-name" name="name" type="text" placeholder="Vaše meno" required class="input-field">
      </div>

      <!-- email -->
      <label class="visually-hidden" for="f-email">Váš e-mail</label>
      <div class="input-row">
        <span class="input-icon" aria-hidden="true">
          <!-- mail svg -->
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false">
      <rect x="3" y="6" width="18" height="12" rx="2" ry="2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
      <path d="M3 7l9 7 9-7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
      </span>
      <input id="f-email" name="email" type="email" placeholder="Váš e-mail" required class="input-field">
      </div>

      <!-- message -->
      <label class="visually-hidden" for="f-message">Správa</label>
      <div class="input-row input-row--textarea">
        <span class="input-icon" aria-hidden="true">
          <!-- message / chat svg -->
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false">
            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </span>
        <textarea id="f-message" name="message" placeholder="Vaša správa..." required class="input-field textarea-field"></textarea>
      </div>

      <!-- submit with small icon -->
      <div class="submit-row">
        <button type="submit" class="btn-send" aria-label="Odoslať správu">
          <!-- send / paper-plane svg -->
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false">
            <path d="M22 2L11 13" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/>
            <path d="M22 2L15 22l-4-9-9-4 20-7z" stroke="currentColor" stroke-width="1.1" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
          <span>Odoslať</span>
        </button>
      </div>
    </form>
    </div>
    <!-- social icons -->
    <div class="social-row">
      <h2 class="section-title"><span>Sledujte</span> nás</h2>
      <div class="social-icons" aria-label="Sledujte nás">
        <a class="social-link FB" href="#" aria-label="Facebook"></a>
        <a class="social-link X" href="#" aria-label="Twitter"></a>
        <a class="social-link INST" href="#" aria-label="Instagram"></a>
        <a class="social-link YT" href="#" aria-label="YouTube"></a>
    </div>
  </div>
</div>

   <!-- 4) KONTAKT + ODBER (firemné údaje nad kontaktom) s microdata + JSON-LD -->

  <div class="footer-panel">
    <div class="footer-text">
    <h2 class="section-title"><span>Informácie</span> o prevádzkovateľovi</h2>

    <div class="company-info" itemScope itemType="https://schema.org/Organization" aria-label="Informácie o prevádzkovateľovi">
      <div class="section-subtitle">
      <p><strong>Obchodné meno</strong>:</br><span itemProp="name">Black Cat Academy s. r. o.</span></p>
      <p><strong>Sídlo</strong>:</br><span itemProp="address" itemScope itemType="https://schema.org/PostalAddress"><span itemProp="streetAddress">Bytčická 89</span>, <span itemProp="addressLocality">Žilina</span> <span itemProp="postalCode">010 09</span></span></p>
      <p><span itemProp="identifier" itemScope itemType="https://schema.org/PropertyValue"><span itemProp="propertyID"><strong>IČO</strong></span>: <span itemProp="value">55 396 461</span></span></p>
      <meta itemProp="legalName" content="Black Cat Academy s. r. o." />
      <meta itemProp="url" content="https://knihyodautorov.sk" />

    <!-- Contact lines with microdata (telephone + email) -->
      <p><strong>Email: </strong><a href="mailto:info@knihyodautorov.sk" itemProp="email">info@knihyodautorov.sk</a></p>
      <p><strong>Tel: </strong><a href="tel:+421901770666" itemProp="telephone">+421 901 770 666</a></p>
      <div class="footer-subscribe" aria-label="Prihlásenie k odberu">
      <p class="section-title"><span>Prihlásiť</span> k odberu</p>
      </div>
      <form class="subscribe-form" action="/subscribe" method="post" novalidate>
        <label class="visually-hidden" for="subscribe-email-footer">E-mail</label>
        <div class="subscribe-row">
          <input id="subscribe-email-footer" name="email" type="email" placeholder="Váš e-mail" required autocomplete="email" class="input-field" />
          <button type="submit" class="btn-subscribe" aria-label="Prihlásiť k odberu">
              <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16">
              <path d="M13.485 1.929a1.5 1.5 0 0 1 0 2.122L6.06 11.475l-3.182-3.182a1.5 1.5 0 0 1 2.122-2.122L6.06 8.232l5.303-5.303a1.5 1.5 0 0 1 2.122 0z"/>
              </svg>
          </button>
      </div></form>
  </div>
  </div>
  </div>
</div>

<!-- JSON-LD (Organization) — adjust "url" and "sameAs" if you have official pages -->
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "Organization",
  "name": "Black Cat Academy s. r. o.",
  "legalName": "Black Cat Academy s. r. o.",
  "url": "https://knihyodautorov.sk",
  "email": "info@knihyodautorov.sk",
  "telephone": "+421901770666",
  "identifier": {
    "@type": "PropertyValue",
    "propertyID": "IČO",
    "value": "55 396 461"
  },
  "address": {
    "@type": "PostalAddress",
    "streetAddress": "Bytčická 89",
    "addressLocality": "Žilina",
    "postalCode": "010 09",
    "addressCountry": "SK"
  }
}
</script>

  </div>
  <div class="footer-panel">
  <div class="footer-bottom section-title" role="note">
    <p>© <?php echo date("Y"); ?> Knihy od Autorov — Časť výnosu venujeme
    <span>babyboxom</span> 
     ❤️</p>
  </div>
  </div>
</footer>
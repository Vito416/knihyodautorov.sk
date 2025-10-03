<?php
declare(strict_types=1);

/**
 * contact.php
 * Static Contact page handler for front controller.
 * Returns: ['template' => null, 'vars' => [...], 'content' => '...']
 *
 * Poznámky:
 * - Formulár posiela POST na /contact/submit — uprav podľa reálnej routy.
 * - Backend MUSÍ overiť: CSRF, honeypot (hp), file size/type, rate-limit, sanitizáciu vstupov.
 * - Po úspešnom prijatí odporúčam posielať automatické potvrdenie s ticket_id.
 */

// při generování stránky (server-side)
$honeypots = [
  'website' => 'Odkaz na vaši webovú stránku (URL) - zadajte, ak máte',
  'company' => 'Názov spoločnosti / firmy',
  'vat_id' => 'DIČ / IČ DPH (ak platíte DPH)',
  'promo_code' => 'Zadajte zľavový kód (ak máte)',
  'order_notes' => 'Poznámky k objednávke (interné)',
  'linkedin' => 'Odkaz na LinkedIn profil',
  'skype' => 'Skype / kontakt',
  'referrer' => 'Kód odporúčania (referral)',
  'contact_time' => 'Preferovaný čas kontaktu (HH:MM)',
];

$hp_keys = array_keys($honeypots);
$rand_key = $hp_keys[array_rand($hp_keys)];
$hp_label = $honeypots[$rand_key];
$hp_name = $rand_key; // použijeme jako name atribut

$article = <<<'HTML'
<article id="contact-article" class="page-article container">
  <header class="page-header">
    <h1>Kontakt</h1>
    <p class="lead">Ozvite sa nám — radi odpovieme na vaše otázky ohľadom objednávky, distribúcie alebo spolupráce.</p>
  </header>

  <section class="page-content">
    <div class="contact-grid" style="display:grid;grid-template-columns:1fr 380px;gap:32px;align-items:start;">
      <div class="contact-main">

        <section aria-labelledby="company-info">
          <h2 id="company-info">Informácie o prevádzkovateľovi</h2>
          <address itemscope itemtype="http://schema.org/Organization" class="company-info">
            <meta itemprop="name" content="Black Cat Academy s. r. o." />
            <p>
              <strong itemprop="name">Black Cat Academy s. r. o.</strong><br>
              <span itemprop="address" itemscope itemtype="http://schema.org/PostalAddress">
                <span itemprop="streetAddress">Dolná ulica 1C</span>, 
                <span itemprop="postalCode">013 13</span> 
                <span itemprop="addressLocality">Kunerad</span>
              </span>
            </p>
            <p>IČO: <strong>55 396 461</strong><br>
               DIČ: <strong>2121977429</strong><br>
               IČ DPH: <strong>neplátca</strong></p>
            <p>Telefón: <a href="tel:+421901770666" itemprop="telephone">+421 901 770 666</a><br>
               E-mail: <a href="mailto:info@knihyodautorov.sk" itemprop="email">info@knihyodautorov.sk</a></p>
          </address>

          <p style="margin-top:10px;color:#555;">
            <strong>Poznámka:</strong> Ide o virtuálne sídlo spoločnosti. Nemáme otvorenú kamennú predajňu — osobné stretnutia sú možné výhradne po predchádzajúcej dohode.
          </p>
        </section>

        <section aria-labelledby="socials" style="margin-top:14px;">
          <h4 id="socials">Sociálne siete</h4>
          <p>
            <a href="https://facebook.com/" target="_blank" rel="noopener noreferrer">Facebook</a><br>
            <a href="https://instagram.com/" target="_blank" rel="noopener noreferrer">Instagram</a>
          </p>
        </section>

        <section aria-labelledby="faq" style="margin-top:18px;">
          <h4 id="faq">Rýchle odkazy</h4>
          <p>
            <a href="/eshop/gdpr">Politika ochrany osobných údajov (GDPR)</a><br>
            <a href="/eshop/vop">Všeobecné obchodné podmienky</a><br>
            <a href="/eshop/reklamacie">Reklamácie a vrátenie tovaru</a><br>
            <a href="/eshop/faq">Často kladené otázky (FAQ)</a>
          </p>
        </section>

      </div>

      <aside class="contact-aside" aria-labelledby="map-title" style="border-left:1px solid #eee;padding-left:24px;">

        <section aria-labelledby="contact-form" class="mt-16">
          <h2 id="contact-form">Napíšte nám</h2>

          <!--
            Backend checklist:
            - Overiť CSRF token
            - Skontrolovať honeypot "hp" -> ak nie je prázdne, ignorovať
            - Validovať email, telefón, maxlength
            - Ak je priložený súbor: limit 5MB, povolené typy: image/*, application/pdf
            - Vytvoriť ticket_id (napr. KNT-20251002-0001) a poslať auto-reply
            - Logovať requesty, rate-limit (napr. max 5/10min z IP)
          -->
          <form id="site-contact-form" class="contact-form" method="post" action="/contact/submit" enctype="multipart/form-data" novalidate>
            <!-- CSRF: controller môže vložiť <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf ?? '') ?>"> -->
            <div style="display:flex;flex-direction:column;gap:12px;max-width:720px;">
              <label for="name">Meno a priezvisko <span aria-hidden="true">*</span>
                <input id="name" name="name" type="text" required maxlength="255" placeholder="Vaše meno" />
              </label>

              <label for="email">E-mail <span aria-hidden="true">*</span>
                <input id="email" name="email" type="email" required maxlength="255" placeholder="vas@email.sk" />
              </label>

              <label for="phone">Telefón (nepovinné)
                <input id="phone" name="phone" type="tel" maxlength="50" placeholder="+421 9XX XXX XXX" />
              </label>

              <label for="reason">Dôvod kontaktu
                <select id="reason" name="reason" required>
                  <option value="">— Vyberte —</option>
                  <option value="order">Otázka k objednávke</option>
                  <option value="return">Reklamácia / vrátenie</option>
                  <option value="payment">Platba / faktúry</option>
                  <option value="partnership">Spolupráca / obchod</option>
                  <option value="other">Iné</option>
                </select>
              </label>

              <label for="order_number">Číslo objednávky (ak súvisí)
                <input id="order_number" name="order_number" type="text" maxlength="100" placeholder="Napr. 2025-000123" />
              </label>

              <label for="subject">Predmet
                <input id="subject" name="subject" type="text" maxlength="255" placeholder="Predmet správy" />
              </label>

              <label for="message">Správa <span aria-hidden="true">*</span>
                <textarea id="message" name="message" rows="8" required maxlength="5000" placeholder="Sem napíšte vašu správu..."></textarea>
              </label>

            <div style="position:absolute;left:-10000px;top:auto;width:1px;height:1px;overflow:hidden" aria-hidden="true">
            <label for="hp_<?php echo htmlspecialchars($hp_name, ENT_QUOTES); ?>">
                <?php echo htmlspecialchars($hp_label, ENT_QUOTES); ?>
            </label>
            <input id="hp_<?php echo htmlspecialchars($hp_name, ENT_QUOTES); ?>"
                    name="<?php echo htmlspecialchars($hp_name, ENT_QUOTES); ?>"
                    type="text"
                    tabindex="-1"
                    autocomplete="off"
                    value="">
            </div>

              <label class="consent" for="consent_marketing">
                <input id="consent_marketing" name="consent_marketing" type="checkbox" value="1" />
                Súhlasím so zasielaním marketingových informácií (newsletter) — tento súhlas je dobrovoľný.
              </label>

              <div style="display:flex;gap:12px;align-items:center;">
                <button type="submit" class="btn header_link-register" style="padding:10px 18px;border-radius:6px;">Odoslať správu</button>
                <small aria-live="polite">Odpovieme do 1–2 pracovných dní. Pre urgentné prípady volajte <a href="tel:+421901770666">+421 901 770 666</a>.</small>
              </div>
            </div>
          </form>
        </section>

        <hr>

        <p style="font-size:0.9em;color:#666;">
          <strong>Ochrana osobných údajov:</strong> Kontaktné údaje použijeme výhradne na spracovanie vašej požiadavky. Viac informácií nájdete v našej <a href="/eshop/gdpr">Politike ochrany osobných údajov (GDPR)</a>.
        </p>
      </aside>
    </div>

    <footer class="contact-footer" style="margin-top:28px;">
      <p style="font-size:0.9em;color:#555;">Posledná aktualizácia: <em>[DÁTUM]</em></p>
    </footer>

    <!-- Structured data: Organization + ContactPage -->
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "Organization",
      "name": "Black Cat Academy s. r. o.",
      "url": "https://knihyodautorov.sk",
      "logo": "https://knihyodautorov.sk/assets/logo.png",
      "contactPoint": [
        {
          "@type": "ContactPoint",
          "telephone": "+421901770666",
          "contactType": "customer support",
          "areaServed": "SK",
          "availableLanguage": ["Slovak", "Czech"]
        }
      ],
      "address": {
        "@type": "PostalAddress",
        "streetAddress": "Dolná ulica 1C",
        "addressLocality": "Kunerad",
        "postalCode": "013 13",
        "addressCountry": "SK"
      }
    }
    </script>

    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "ContactPage",
      "mainEntity": {
        "@type": "Organization",
        "name": "Black Cat Academy s. r. o."
      }
    }
    </script>

  </section>
</article>
HTML;

$fullHtml = $article;

return [
    'template' => null,
    'vars' => [],
    'content' => $fullHtml,
];

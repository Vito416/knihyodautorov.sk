<link rel="stylesheet" href="/css/contact.css" />

<section id="contact" class="contact-section">
  <div class="container contact-container">
    <div class="contact-left">
      <h2>Kontaktujte nás <span>knihyodautorov</span></h2>
      <p class="lead">Máte otázky, chcete vložiť svoju knihu, alebo máte tip na spoluprácu? Napíšte nám — radi sa Vám ozveme.</p>

      <ul class="contact-details" aria-hidden="false">
        <li><strong>Email:</strong> <a href="mailto:kontakt@knihyodautorov.sk">kontakt@knihyodautorov.sk</a></li>
        <li><strong>Podpora babyboxov:</strong> Časť výnosov putuje na dobročinné účely.</li>
        <li><strong>Adresa:</strong> (voliteľné) Bratislava, Slovensko</li>
      </ul>

      <div class="contact-cta">
        <a href="#contact-form" class="btn btn-primary scroll-to-form">Napíšte nám</a>
      </div>
    </div>

    <div class="contact-right">
      <form id="contact-form" class="contact-form" method="post" action="contact-handler.php" novalidate>
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
        <!-- Honeypot (skrytý) -->
        <input type="text" name="hp_email" value="" style="display:none;" tabindex="-1" autocomplete="off">

        <label for="name">Meno</label>
        <input id="name" name="name" type="text" required aria-required="true" placeholder="Vaše meno">

        <label for="email">Email</label>
        <input id="email" name="email" type="email" required aria-required="true" placeholder="vas@email.sk">

        <label for="subject">Predmet</label>
        <input id="subject" name="subject" type="text" required placeholder="O čom píšete?">

        <label for="message">Správa</label>
        <textarea id="message" name="message" rows="6" required placeholder="Napíšte správu..."></textarea>

        <!-- voliteľné: reCAPTCHA token (pokyny dolu) -->
        <!-- <input type="hidden" name="g-recaptcha-response" id="g-recaptcha-response"> -->

        <div class="form-actions">
          <button type="submit" class="btn btn-primary" id="contactSubmit">Odoslať správu</button>
          <div id="formStatus" role="status" aria-live="polite"></div>
        </div>
      </form>
    </div>
  </div>
</section>

<script src="js/contact.js" defer></script>
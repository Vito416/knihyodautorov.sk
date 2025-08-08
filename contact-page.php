<?php include 'partials/header.php'; ?>

<link rel="stylesheet" href="css/contact-page.css" />

<section class="contact-hero-section">
    <div class="contact-hero-overlay"></div>
    <div class="contact-hero-content">
        <h1>Kontaktujte nás</h1>
        <p>Máte otázku, návrh na spoluprácu alebo technický problém? Sme tu pre vás.</p>
    </div>
</section>

<section class="contact-main-section">
    <div class="contact-container">
        <div class="contact-info">
            <h2>Kontaktné údaje</h2>
            <ul>
                <li><i class="fa-solid fa-envelope"></i> Email: <a href="mailto:info@knihyodautorov.sk">info@knihyodautorov.sk</a></li>
                <li><i class="fa-solid fa-phone"></i> Telefón: +421 900 123 456</li>
                <li><i class="fa-solid fa-location-dot"></i> Adresa: Bratislava, Slovensko</li>
            </ul>
        </div>

        <div class="contact-form-container">
            <h2>Napíšte nám</h2>
            <form action="send-message.php" method="POST" class="contact-form">
                <label for="name">Meno</label>
                <input type="text" id="name" name="name" required>

                <label for="email">E-mail</label>
                <input type="email" id="email" name="email" required>

                <label for="message">Správa</label>
                <textarea id="message" name="message" rows="5" required></textarea>

                <button type="submit">Odoslať</button>
            </form>
        </div>
    </div>
</section>

<script src="js/contact-page.js" defer></script>

<?php include 'partials/footer.php'; ?>

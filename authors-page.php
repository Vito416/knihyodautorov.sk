<?php include 'partials/header.php'; ?>

<section class="authors-hero">
    <div class="authors-hero-content">
        <h1>Naši autori</h1>
        <p>Podporujeme slovenských aj českých autorov. Časť z výnosov venujeme na podporu babyboxov.</p>
    </div>
</section>

<section class="authors-list">
    <div class="authors-filter">
        <label for="filter-country">Filtrovať podľa krajiny:</label>
        <select id="filter-country">
            <option value="all">Všetky</option>
            <option value="sk">Slovensko</option>
            <option value="cz">Česko</option>
        </select>
    </div>

    <div class="authors-grid" id="authorsGrid">
        <!-- Ukážkový autor -->
        <div class="author-card" data-country="sk" tabindex="0">
            <div class="author-image">
                <img src="assets/authors/author1.jpg" alt="Ján Novák">
            </div>
            <h3>Ján Novák</h3>
            <span class="author-country">🇸🇰 Slovensko</span>
            <p>Autor známy svojimi epickými fantasy príbehmi, ktoré očarili čitateľov po celom svete.</p>
            <a href="books.php?author=jan-novak" class="btn">Zobraziť knihy</a>
        </div>

        <div class="author-card" data-country="cz" tabindex="0">
            <div class="author-image">
                <img src="assets/authors/author2.jpg" alt="Petr Svoboda">
            </div>
            <h3>Petr Svoboda</h3>
            <span class="author-country">🇨🇿 Česko</span>
            <p>Moderný český spisovateľ, ktorý prináša svieže nápady a originálne príbehy.</p>
            <a href="books.php?author=petr-svoboda" class="btn">Zobraziť knihy</a>
        </div>

        <!-- Pridaj ďalších autorov dynamicky cez PHP / DB -->
    </div>
</section>

<?php include 'partials/footer.php'; ?>
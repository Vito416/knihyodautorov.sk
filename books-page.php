<?php include 'partials/header.php'; ?>

<link rel="stylesheet" href="css/books-page.css" />

<section class="books-page-hero">
    <div class="books-page-hero-content">
        <h1>Naše knihy</h1>
        <p>Objavte príbehy od slovenských a českých autorov vo formáte PDF.</p>
    </div>
</section>

<section class="books-page-filter">
    <div class="books-page-filter-group">
        <label for="books-page-genre">Žáner:</label>
        <select id="books-page-genre">
            <option value="all">Všetky</option>
            <option value="fantasy">Fantasy</option>
            <option value="romance">Romantika</option>
            <option value="scifi">Sci-Fi</option>
        </select>
    </div>

    <div class="books-page-filter-group">
        <label for="books-page-author">Autor:</label>
        <select id="books-page-author">
            <option value="all">Všetci</option>
            <option value="jan-novak">Ján Novák</option>
            <option value="petr-svoboda">Petr Svoboda</option>
        </select>
    </div>
</section>

<section class="books-page-list" id="booksPageList">
    <!-- Ukážková kniha -->
    <div class="books-page-card" data-genre="fantasy" data-author="jan-novak">
        <div class="books-page-image">
            <img src="assets/books/book1.jpg" alt="Názov knihy">
        </div>
        <h3>Názov knihy</h3>
        <span class="books-page-author">Ján Novák</span>
        <p>Strhujúci fantasy príbeh plný dobrodružstva a mágie.</p>
        <a href="book-detail.php?id=1" class="books-page-btn">Zobraziť detail</a>
    </div>

    <div class="books-page-card" data-genre="romance" data-author="petr-svoboda">
        <div class="books-page-image">
            <img src="assets/books/book2.jpg" alt="Romantický príbeh">
        </div>
        <h3>Romantický príbeh</h3>
        <span class="books-page-author">Petr Svoboda</span>
        <p>Nezabudnuteľná láska v tieni historických udalostí.</p>
        <a href="book-detail.php?id=2" class="books-page-btn">Zobraziť detail</a>
    </div>
</section>

<script src="js/books-page.js" defer></script>

<?php include 'partials/footer.php'; ?>

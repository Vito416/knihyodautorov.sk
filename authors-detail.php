<?php include 'partials/header.php'; ?>

<link rel="stylesheet" href="css/authors-detail.css" />

<section class="author-detail-hero">
    <div class="author-detail-overlay"></div>
    <div class="author-detail-content">
        <img src="assets/author-sample.jpg" alt="Fotografia autora" class="author-detail-photo">
        <h1>Ján Novák</h1>
        <p>Spisovateľ historických románov a fantasy príbehov zo Slovenska.</p>
    </div>
</section>

<section class="author-detail-main">
    <div class="author-detail-container">
        <div class="author-detail-bio">
            <h2>O autorovi</h2>
            <p>
                Ján Novák je oceňovaný slovenský spisovateľ, ktorý sa preslávil svojimi 
                historickými románmi a fantasy príbehmi. Publikuje už viac ako 15 rokov a 
                jeho knihy sú známe pútavým dejom, realistickými postavami a nezabudnuteľnou atmosférou.
            </p>
            <p>
                Medzi jeho najznámejšie diela patrí séria „Svet tieňov“ a román „Cesta hrdinu“.
            </p>
        </div>

        <div class="author-detail-books">
            <h2>Knihy od tohto autora</h2>
            <div class="author-detail-book-list">
                <div class="author-detail-book-card">
                    <img src="assets/book-sample1.jpg" alt="Obálka knihy">
                    <h3>Svet tieňov</h3>
                    <a href="book-detail.php" class="author-detail-btn">Zobraziť</a>
                </div>
                <div class="author-detail-book-card">
                    <img src="assets/book-sample2.jpg" alt="Obálka knihy">
                    <h3>Cesta hrdinu</h3>
                    <a href="book-detail.php" class="author-detail-btn">Zobraziť</a>
                </div>
            </div>
        </div>
    </div>
</section>

<script src="js/authors-detail.js" defer></script>

<?php include 'partials/footer.php'; ?>

<?php
// book-detail.php
// Získáme ID knihy z URL
//$bookId = isset($_GET['id']) ? intval($_GET['id']) : 0;

// TODO: Připojení k databázi
// $db = new PDO(...);

// Zde jen pro ukázku – normálně by to bylo z DB
$book = [
    'title' => 'Pán prstenů',
    'author' => 'J. R. R. Tolkien',
    'description' => 'Epický fantasy příběh o přátelství, odvaze a boji proti temnotě. Čeká vás dobrodružství, které navždy změní Středozemi.',
    'cover' => 'assets/books/lotr.jpg',
    'rating' => 4.8,
    'genre' => 'Fantasy'
];
?>
<?php include 'partials/header.php'; ?>

<main class="book-detail">
    <section class="book-hero">
        <div class="book-cover">
            <img src="<?php echo $book['cover']; ?>" alt="<?php echo $book['title']; ?>">
        </div>
        <div class="book-info">
            <h1><?php echo $book['title']; ?></h1>
            <h3>Autor: <?php echo $book['author']; ?></h3>
            <div class="rating">
                <?php
                $stars = floor($book['rating']);
                for ($i = 0; $i < 5; $i++) {
                    echo $i < $stars ? '⭐' : '☆';
                }
                ?>
                <span><?php echo $book['rating']; ?>/5</span>
            </div>
            <p class="description"><?php echo $book['description']; ?></p>
            <div class="book-actions">
                <a href="#" class="btn-primary">📖 Přečíst ukázku</a>
                <a href="#" class="btn-secondary">🛒 Koupit</a>
            </div>
        </div>
    </section>

    <section class="reviews">
        <h2>Recenze čtenářů</h2>
        <div class="review">
            <strong>Jan Novák</strong>
            <p>Úžasný příběh, napětí od začátku do konce.</p>
        </div>
        <div class="review">
            <strong>Petra Svobodová</strong>
            <p>Jeden z nejlepších fantasy románů, co jsem kdy četla.</p>
        </div>
    </section>
</main>

<?php include 'partials/footer.php'; ?>

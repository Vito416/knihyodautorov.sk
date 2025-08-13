<?php
declare(strict_types=1);
require_once __DIR__ . '/_init.php';
include __DIR__ . '/templates/header.php';

// Načteme ID knihy z URL
$bookId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($bookId <= 0) {
    echo "<p class='error'>Neplatný odkaz na knihu.</p>";
    include __DIR__ . '/templates/footer.php';
    exit;
}

// Načteme detail knihy z DB
try {
    $stmt = $pdo->prepare("SELECT id, title, author, price, description, cover_image, file_size FROM books WHERE id = ?");
    $stmt->execute([$bookId]);
    $book = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    echo "<p class='error'>Chyba načítání detailu knihy. Kontaktujte podporu.</p>";
    include __DIR__ . '/templates/footer.php';
    exit;
}

if (!$book) {
    echo "<p class='error'>Kniha nebyla nalezena.</p>";
    include __DIR__ . '/templates/footer.php';
    exit;
}
?>

<div class="book-detail">
    <div class="book-detail-image">
        <?php if (!empty($book['cover_image']) && file_exists(__DIR__ . '/uploads/' . $book['cover_image'])): ?>
            <img src="/eshop/uploads/<?php echo htmlspecialchars($book['cover_image']); ?>" alt="<?php echo htmlspecialchars($book['title']); ?>">
        <?php else: ?>
            <img src="/eshop/assets/img/no-cover.png" alt="Bez obalu">
        <?php endif; ?>
    </div>

    <div class="book-detail-info">
        <h1><?php echo htmlspecialchars($book['title']); ?></h1>
        <p class="author">Autor: <?php echo htmlspecialchars($book['author']); ?></p>
        <p class="price"><?php echo number_format((float)$book['price'], 2, ',', ' '); ?> €</p>
        <?php if (!empty($book['file_size'])): ?>
            <p class="filesize">Velikost PDF: <?php echo round($book['file_size'] / 1024 / 1024, 2); ?> MB</p>
        <?php endif; ?>
        <p class="description"><?php echo nl2br(htmlspecialchars($book['description'])); ?></p>

        <form method="post" action="/eshop/cart-add.php">
            <input type="hidden" name="id" value="<?php echo (int)$book['id']; ?>">
            <button type="submit">Přidat do košíku</button>
        </form>

        <p><a href="/eshop/index.php" class="back-link">← Zpět na katalog</a></p>
    </div>
</div>

<?php
include __DIR__ . '/templates/footer.php';
<?php
// eshop/product-detail.php
session_start();
require_once __DIR__ . '/../db/config/config.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    header('Location: eshop.php');
    exit;
}

// načítaj knihu
$stmt = $pdo->prepare("SELECT b.*, a.meno AS author_name, c.nazov AS category_name
    FROM books b
    LEFT JOIN authors a ON b.author_id = a.id
    LEFT JOIN categories c ON b.category_id = c.id
    WHERE b.id = ?");
$stmt->execute([$id]);
$book = $stmt->fetch();

if (!$book) {
    http_response_code(404);
    echo "Kniha nenájdená.";
    exit;
}

if (file_exists(__DIR__ . '/css/product-detail.css')) {
    echo '<link rel="stylesheet" href="/eshop/css/product-detail.css">';
}
if (file_exists(__DIR__ . '/js/product-detail.js')) {
    echo '<script src="/eshop/js/product-detail.js" defer></script>';
}

// header include
if (file_exists(__DIR__ . '/partials/header.php')) include __DIR__ . '/partials/header.php';
?>

<section class="product-detail-hero">
  <div class="product-detail-inner">
    <div class="product-detail-card">
      <div class="product-detail-image">
        <img src="/books-img/<?= htmlspecialchars($book['obrazok'] ?: 'placeholder.jpg') ?>" alt="<?= htmlspecialchars($book['nazov']) ?>">
      </div>
      <div class="product-detail-info">
        <h1><?= htmlspecialchars($book['nazov']) ?></h1>
        <p class="product-detail-meta">
          Autor: <?= htmlspecialchars($book['author_name'] ?: 'Neznámy') ?> • Kategória: <?= htmlspecialchars($book['category_name'] ?: '-') ?>
        </p>
        <p class="product-detail-desc"><?= nl2br(htmlspecialchars($book['popis'])) ?></p>
        <div class="product-detail-buy">
          <span class="product-detail-price"><?= htmlspecialchars(number_format($book['cena'],2,',','')) ?> €</span>

          <form action="cart.php" method="post" class="product-detail-add">
            <input type="hidden" name="action" value="add">
            <input type="hidden" name="book_id" value="<?= (int)$book['id'] ?>">
            <label for="qty">Množstvo</label>
            <input id="qty" type="number" name="qty" value="1" min="1" max="10">
            <button type="submit" class="product-detail-btn">Pridať do košíka</button>
          </form>

          <?php if ((float)$book['cena'] == 0.00): ?>
            <div class="product-detail-free"><a href="../download.php?book=<?= (int)$book['id'] ?>">Stiahnuť zadarmo</a></div>
          <?php endif; ?>
        </div>

        <div class="product-detail-meta2">
          <small>Publikované: <?= htmlspecialchars($book['created_at']) ?></small>
        </div>
      </div>
    </div>
  </div>
</section>

<?php if (file_exists(__DIR__ . '/partials/footer.php')) include __DIR__ . '/partials/footer.php'; ?>

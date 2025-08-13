<?php
// /eshop/book.php
require __DIR__ . '/_init.php';

$slug = trim((string)($_GET['slug'] ?? ''));
if ($slug === '') {
    header('Location: /eshop/index.php'); exit;
}
$stmt = $pdo->prepare("SELECT b.*, a.meno AS autor, c.nazov AS category, c.slug AS category_slug FROM books b LEFT JOIN authors a ON b.author_id = a.id LEFT JOIN categories c ON b.category_id = c.id WHERE b.slug = ? LIMIT 1");
$stmt->execute([$slug]);
$book = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$book) {
    http_response_code(404);
    echo "Kniha nenájdená"; exit;
}
?>
<!doctype html>
<html lang="sk">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?php echo eshop_esc($book['nazov']); ?> — Knihy od autorov</title>
  <link rel="stylesheet" href="<?php echo eshop_asset('eshop/css/eshop.css'); ?>">
</head>
<body>
  <?php // header minimal ?>
  <header class="eshop-header"><div class="wrap"><a href="/"><img src="<?php echo eshop_asset('assets/logoobdelnikbezpozadi.png');?>" alt=""></a><a href="/eshop/cart.php">Košík (<?php echo eshop_cart_count(); ?>)</a></div></header>

  <main class="eshop-wrap">
    <div class="container book-detail">
      <div class="left">
        <img class="book-cover" src="<?php echo eshop_asset('books-img/'.($book['obrazok']?:'placeholder.png')); ?>" alt="<?php echo eshop_esc($book['nazov']); ?>">
      </div>
      <div class="right">
        <h1><?php echo eshop_esc($book['nazov']); ?></h1>
        <p class="author"><?php echo eshop_esc($book['autor'] ?? ''); ?></p>
        <div class="price big"><?php echo number_format((float)$book['cena'],2,',','.'); ?> €</div>
        <p class="desc"><?php echo nl2br(eshop_esc($book['popis'])); ?></p>

        <form action="/eshop/actions/cart-add.php" method="post" class="buy-form">
          <input type="hidden" name="book_id" value="<?php echo (int)$book['id']; ?>">
          <input type="hidden" name="csrf" value="<?php echo eshop_csrf_token(); ?>">
          <label>Množstvo <input type="number" name="qty" value="1" min="1" max="99"></label>
          <button class="btn-primary">Kúpiť</button>
        </form>

        <?php if (!empty($book['pdf_file'])): ?>
          <p class="muted">PDF súbor dostupný po zakúpení.</p>
        <?php endif; ?>
      </div>
    </div>
  </main>

  <script src="<?php echo eshop_asset('eshop/js/eshop.js'); ?>"></script>
</body>
</html>
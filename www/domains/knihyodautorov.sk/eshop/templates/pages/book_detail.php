<?php
// Expects:
//  - $pageTitle (string)
//  - $book (array)
//  - $similarBooks (array)
//  - $csrf_token (string|null)

$partialsDir = __DIR__ . '/../partials';
include $partialsDir . '/header.php';
include $partialsDir . '/flash.php';

$bookId = (int)($book['id'] ?? 0);
$title = htmlspecialchars($book['title'] ?? '—', ENT_QUOTES, 'UTF-8');
$author = htmlspecialchars($book['author_name'] ?? '', ENT_QUOTES, 'UTF-8');
$authorSlug = htmlspecialchars($book['author_slug'] ?? '', ENT_QUOTES, 'UTF-8');
$category = htmlspecialchars($book['category_name'] ?? '', ENT_QUOTES, 'UTF-8');
$categorySlug = htmlspecialchars($book['category_slug'] ?? '', ENT_QUOTES, 'UTF-8');
$price = number_format((float)($book['price'] ?? 0), 2, ',', ' ');
$currency = htmlspecialchars($book['currency'] ?? 'EUR', ENT_QUOTES, 'UTF-8');
$cover = htmlspecialchars($book['cover_path'] ?? '/eshop/img/placeholder-book.png', ENT_QUOTES, 'UTF-8');
$desc = nl2br(htmlspecialchars($book['description'] ?? '', ENT_QUOTES, 'UTF-8'));
$available = ((int)($book['is_available'] ?? 0) === 1);
$stock = (int)($book['stock_quantity'] ?? 0);
?>
<article class="book-detail">
    <div class="book-detail-main">
        <div class="cover">
            <img src="<?= $cover ?>" alt="Obálka: <?= $title ?>">
        </div>
        <div class="info">
            <h1><?= $title ?></h1>
            <p class="author">Autor: <a href="/eshop/author.php?slug=<?= $authorSlug ?>"><?= $author ?></a></p>
            <?php if ($category): ?>
                <p class="category">Kategória: <a href="/eshop/category.php?slug=<?= $categorySlug ?>"><?= $category ?></a></p>
            <?php endif; ?>
            <p class="price"><?= $price ?> <?= $currency ?></p>
            <p class="availability"><?= $available ? "Skladom ($stock ks)" : 'Nedostupné' ?></p>

            <?php if ($available && $stock > 0): ?>
                <form method="post" action="/eshop/actions/add_to_cart.php" class="add-to-cart">
                    <input type="hidden" name="book_id" value="<?= $bookId ?>">
                    <input type="number" name="quantity" value="1" min="1" max="<?= $stock ?>">
                    <?php if ($csrf_token): ?>
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                    <?php endif; ?>
                    <button type="submit" class="btn btn-primary">Pridať do košíka</button>
                </form>
            <?php else: ?>
                <button class="btn btn-disabled" disabled>Vypradané</button>
            <?php endif; ?>
        </div>
    </div>

    <section class="description">
        <h2>Popis</h2>
        <p><?= $desc ?></p>
    </section>

    <?php if (!empty($similarBooks)): ?>
        <section class="similar">
            <h2>Podobné knihy</h2>
            <div class="grid">
                <?php foreach ($similarBooks as $s): 
                    $sTitle = htmlspecialchars($s['title'], ENT_QUOTES, 'UTF-8');
                    $sSlug = htmlspecialchars($s['slug'], ENT_QUOTES, 'UTF-8');
                    $sCover = htmlspecialchars($s['cover_path'] ?? '/eshop/img/placeholder-book.png', ENT_QUOTES, 'UTF-8');
                    $sPrice = number_format((float)$s['price'], 2, ',', ' ');
                    $sCurr = htmlspecialchars($s['currency'], ENT_QUOTES, 'UTF-8');
                ?>
                <article class="book-card">
                    <a href="/eshop/book.php?slug=<?= $sSlug ?>">
                        <div class="cover">
                            <img src="<?= $sCover ?>" alt="Obálka: <?= $sTitle ?>">
                        </div>
                        <h3 class="title"><?= $sTitle ?></h3>
                        <div class="price"><?= $sPrice ?> <?= $sCurr ?></div>
                    </a>
                </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
</article>
<?php
include $partialsDir . '/footer.php';
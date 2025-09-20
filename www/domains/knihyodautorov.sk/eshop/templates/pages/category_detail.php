<?php
// templates/pages/category_detail.php
// Expects:
//  - $pageTitle (string)
//  - $navActive (string)
//  - $category (array) id, nazov, slug, description
//  - $books (array) zoznam kníh
// Používa partials: header.php, nav.php, flash.php, footer.php

$pageTitle = $pageTitle ?? 'Kategória';
$navActive = $navActive ?? 'categories';
$category = is_array($category) ? $category : [];
$books = is_array($books) ? $books : [];

$partialsDir = __DIR__ . '/../partials';
include $partialsDir . '/header.php';
include $partialsDir . '/nav.php';
include $partialsDir . '/flash.php';
?>
<div class="category-page">
    <header class="category-header">
        <h1><?= htmlspecialchars($category['nazov'] ?? 'Neznáma kategória', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
        <?php if (!empty($category['description'])): ?>
            <div class="category-description">
                <?= nl2br(htmlspecialchars($category['description'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?>
            </div>
        <?php endif; ?>
    </header>

    <section class="category-books">
        <h2>Knihy v kategórii</h2>
        <?php if (empty($books)): ?>
            <p>V tejto kategórii zatiaľ nie sú žiadne knihy.</p>
        <?php else: ?>
            <div class="grid">
                <?php foreach ($books as $b):
                    $id = (int)($b['id'] ?? 0);
                    $title = htmlspecialchars($b['title'] ?? '—', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    $slug = htmlspecialchars($b['slug'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    $price = isset($b['price']) ? number_format((float)$b['price'], 2, ',', ' ') : '';
                    $currency = htmlspecialchars($b['currency'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    $available = ((int)($b['is_active'] ?? 0) === 1 && (int)($b['is_available'] ?? 0) === 1);
                    $cover = htmlspecialchars($b['cover_path'] ?? '/eshop/img/placeholder-book.png', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                ?>
                    <article class="book-card" data-book-id="<?= $id ?>">
                        <a class="book-link" href="/eshop/book.php?slug=<?= $slug ?>">
                            <div class="cover">
                                <img src="<?= $cover ?>" alt="Obálka: <?= $title ?>">
                            </div>
                            <h3 class="title"><?= $title ?></h3>
                        </a>
                        <div class="meta">
                            <div class="price"><?= $price ?> <?= $currency ?></div>
                            <div class="availability"><?= $available ? 'Skladom' : 'Nedostupné' ?></div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>
<?php
include $partialsDir . '/footer.php';
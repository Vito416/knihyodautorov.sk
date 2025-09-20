<?php
// templates/pages/author_detail.php
// Expects:
//  - $pageTitle (string)
//  - $navActive (string)
//  - $author (array) id, name, slug, bio, photo_path
//  - $books (array) zoznam kníh
// Používa partials: header.php, nav.php, flash.php, footer.php

$pageTitle = $pageTitle ?? 'Autor';
$navActive = $navActive ?? 'authors';
$author = is_array($author) ? $author : [];
$books = is_array($books) ? $books : [];

$partialsDir = __DIR__ . '/../partials';
include $partialsDir . '/header.php';
include $partialsDir . '/nav.php';
include $partialsDir . '/flash.php';
?>
<div class="author-page">
    <header class="author-header">
        <h1><?= htmlspecialchars($author['name'] ?? 'Neznámy autor', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
        <?php if (!empty($author['photo_path'])): ?>
            <div class="author-photo">
                <img src="<?= htmlspecialchars($author['photo_path'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                     alt="Foto autora <?= htmlspecialchars($author['name'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            </div>
        <?php endif; ?>
        <?php if (!empty($author['bio'])): ?>
            <div class="author-bio">
                <?= nl2br(htmlspecialchars($author['bio'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?>
            </div>
        <?php endif; ?>
    </header>

    <section class="author-books">
        <h2>Knihy autora</h2>
        <?php if (empty($books)): ?>
            <p>Tento autor zatiaľ nemá žiadne knihy v ponuke.</p>
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
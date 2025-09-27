<?php
declare(strict_types=1);

/** @var array $book */
/** @var array $assets */
/** @var array $relatedBooks */
/** @var array|null $user */
/** @var bool $hasPurchased */

$cover = $book['cover_url'] ?? '/assets/book-placeholder-epic.png';
$title = $book['title'] ?? 'Kniha';
$author = $book['author_name'] ?? '';
$price = isset($book['price']) ? number_format((float)$book['price'], 2, ',', ' ') . ' ' . ($book['currency'] ?? 'EUR') : 'Neznámá cena';
$available = $book['is_available'] ?? false;
$description = $book['description'] ?? '';
?>

<main class="book-detail-page">
    <div class="wrap">
        <article class="book-detail">
            <div class="book-cover">
                <img src="<?= htmlspecialchars($cover, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" alt="<?= htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            </div>
            <div class="book-info">
                <h1><?= htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
                <h2 class="author"><?= htmlspecialchars($author, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
                <div class="price"><?= $price ?></div>
                <div class="availability"><?= $available ? 'Skladom' : 'Nedostupné' ?></div>
                <div class="description"><?= nl2br(htmlspecialchars($description, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?></div>
                <?php if ($hasPurchased): ?>
                    <div class="badge badge-owned">Vlastníte tuto knihu</div>
                <?php endif; ?>
            </div>
        </article>

        <?php if (!empty($relatedBooks)): ?>
            <section class="related-books">
                <h3>Podobné knihy</h3>
                <div class="grid">
                    <?php foreach ($relatedBooks as $rb):
                        $rCover = $rb['cover_url'] ?? '/assets/book-placeholder-epic.png';
                        $rTitle = $rb['title'] ?? 'Kniha';
                        $rSlug = $rb['slug'] ?? '';
                    ?>
                        <article class="book-card">
                            <a href="/eshop/detail?slug=<?= rawurlencode($rSlug) ?>">
                                <img src="<?= htmlspecialchars($rCover, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" alt="<?= htmlspecialchars($rTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                                <span class="title"><?= htmlspecialchars($rTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                            </a>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>
    </div>
</main>
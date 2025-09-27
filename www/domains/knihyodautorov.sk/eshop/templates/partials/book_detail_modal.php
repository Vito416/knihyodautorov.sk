<?php
/**
 * @var array $book
 * @var array $assets
 * @var array $relatedBooks
 * @var array|null $user
 * @var bool $hasPurchased
 */
?>
<div class="book-detail">
    <div class="book-header">
        <?php if (!empty($book['cover_url'])): ?>
            <div class="book-cover">
                <img src="<?= htmlspecialchars($book['cover_url']) ?>" alt="<?= htmlspecialchars($book['title']) ?>">
            </div>
        <?php endif; ?>

        <div class="book-info">
            <h2><?= htmlspecialchars($book['title']) ?></h2>

            <?php if (!empty($book['author_name'])): ?>
                <p class="book-author">
                    Autor: <a href="/author.php?slug=<?= urlencode($book['author_slug']) ?>">
                        <?= htmlspecialchars($book['author_name']) ?>
                    </a>
                </p>
            <?php endif; ?>

            <?php if (!empty($book['category_name'])): ?>
                <p class="book-category">
                    Kategorie: <a href="/category.php?slug=<?= urlencode($book['category_slug']) ?>">
                        <?= htmlspecialchars(html_entity_decode($book['category_name'])) ?>
                    </a>
                </p>
            <?php endif; ?>

            <?php if ($book['is_available']): ?>
                <p class="book-price"><?= number_format($book['price'], 2, ',', ' ') ?> <?= htmlspecialchars($book['currency']) ?></p>
                <form method="post" action="/cart/add.php" class="add-to-cart-form">
                    <input type="hidden" name="book_id" value="<?= (int)$book['id'] ?>">
                    <button type="submit" class="btn btn-primary">Přidat do košíku</button>
                </form>
            <?php else: ?>
                <p class="book-unavailable">Momentálně není skladem</p>
            <?php endif; ?>

            <?php if ($hasPurchased): ?>
                <p class="book-purchased">✔ Již zakoupeno</p>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($book['description'])): ?>
        <div class="book-description">
            <h3>Popis</h3>
            <p><?= nl2br(htmlspecialchars($book['description'])) ?></p>
        </div>
    <?php endif; ?>

    <?php if (!empty($relatedBooks)): ?>
        <div class="related-books">
            <h3>Další knihy</h3>
            <ul class="related-list">
                <?php foreach ($relatedBooks as $rb): ?>
                    <li>
                        <a href="/detail.php?slug=<?= urlencode($rb['slug']) ?>">
                            <?php if (!empty($rb['cover_url'])): ?>
                                <img src="<?= htmlspecialchars($rb['cover_url']) ?>" alt="<?= htmlspecialchars($rb['title']) ?>">
                            <?php endif; ?>
                            <span><?= htmlspecialchars($rb['title']) ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
</div>
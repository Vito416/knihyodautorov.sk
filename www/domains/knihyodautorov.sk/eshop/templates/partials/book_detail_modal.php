<?php
/**
 * Partial: len vnútorný obsah modalu (bez .modal wrapperu, bez .modal-close)
 *
 * @var array $book
 * @var array $assets
 * @var array $relatedBooks
 * @var array|null $user
 * @var bool $hasPurchased
 */
?>
<div class="modal-inner">
    <?php if (!empty($book['cover_url'])): ?>
        <div class="modal-cover">
            <img
                src="<?= htmlspecialchars($book['cover_url'], ENT_QUOTES, 'UTF-8') ?>"
                alt="<?= htmlspecialchars($book['title'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                loading="lazy"
                decoding="async"
                width="220"
                height="320"
            >
        </div>
    <?php endif; ?>

    <div class="modal-info">
        <h2 class="modal-title"><?= htmlspecialchars($book['title'] ?? '', ENT_QUOTES, 'UTF-8') ?></h2>

        <?php if (!empty($book['author_name'])): ?>
            <p class="modal-author">
                Autor:
                <a href="/author.php?slug=<?= urlencode($book['author_slug'] ?? '') ?>">
                    <?= htmlspecialchars($book['author_name'], ENT_QUOTES, 'UTF-8') ?>
                </a>
            </p>
        <?php endif; ?>

        <?php if (!empty($book['category_name'])): ?>
            <p class="modal-category">
                Kategória:
                <a href="/category.php?slug=<?= urlencode($book['category_slug'] ?? '') ?>">
                    <?= htmlspecialchars(html_entity_decode($book['category_name']), ENT_QUOTES, 'UTF-8') ?>
                </a>
            </p>
        <?php endif; ?>

        <?php if (!empty($book['is_available'])): ?>
            <p class="modal-price">
                <?= number_format((float)($book['price'] ?? 0), 2, ',', ' ') ?>
                <?= htmlspecialchars($book['currency'] ?? '', ENT_QUOTES, 'UTF-8') ?>
            </p>
            <form method="post" action="/eshop/cart_add" class="modal-add-to-cart-form" novalidate>
                <input type="hidden" name="book_id" value="<?= (int)($book['id'] ?? 0) ?>">
                <?= CSRF::hiddenInput('csrf') ?>
                <button type="submit" class="btn btn-primary">Pridať do košíka</button>
            </form>

            <!-- priame kúpenie -->
            <form method="post" action="/eshop/checkout" class="modal-buy-now-form" novalidate style="margin-top:0.5rem;">
                <input type="hidden" name="book_id" value="<?= (int)($book['id'] ?? 0) ?>">
                <?= CSRF::hiddenInput('csrf') ?>
                <button type="submit" class="btn btn-success">Kúpiť</button>
            </form>
        <?php else: ?>
            <p class="modal-unavailable">Momentálne nie je skladom</p>
        <?php endif; ?>

        <?php if (!empty($hasPurchased)): ?>
            <p class="modal-purchased" aria-hidden="false">✔ Už zakúpené</p>
        <?php endif; ?>

        <?php if (!empty($book['description'])): ?>
            <div class="modal-description">
                <h3>Popis</h3>
                <p><?= nl2br(htmlspecialchars($book['description'], ENT_QUOTES, 'UTF-8')) ?></p>
            </div>
        <?php endif; ?>

        <?php if (!empty($relatedBooks)): ?>
            <div class="modal-related-books">
                <h3>Ďalšie knihy</h3>
                <ul class="modal-related-list">
                    <?php foreach ($relatedBooks as $rb): ?>
                        <li>
                            <a href="/eshop/detail?slug=<?= urlencode($rb['slug'] ?? '') ?>">
                                <?php if (!empty($rb['cover_url'])): ?>
                                    <img
                                        src="<?= htmlspecialchars($rb['cover_url'], ENT_QUOTES, 'UTF-8') ?>"
                                        alt="<?= htmlspecialchars($rb['title'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                        width="90"
                                        height="135"
                                        loading="lazy"
                                        decoding="async"
                                    >
                                <?php endif; ?>
                                <span><?= htmlspecialchars($rb['title'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    </div>
</div>
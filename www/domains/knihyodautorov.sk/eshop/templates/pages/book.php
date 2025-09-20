<?php
// templates/pages/book.php
declare(strict_types=1);

/**
 * Detail knihy
 *
 * Premenné od kontroléra:
 *  - $pageTitle (string|null)
 *  - $user (array|null)
 *  - $navActive (string|null)
 *  - $book (array) : id, title, description, author_id, author_name, slug, price, currency, stock_quantity, is_active, is_available, cover_path
 *  - $related (array) : pole ďalších kníh (id, title, slug, cover_path)
 *  - $csrf_token (string|null)
 */

$pageTitle = isset($pageTitle) ? (string)$pageTitle : 'Detail knihy';
$navActive = $navActive ?? 'catalog';
$csrf = isset($csrf_token) && is_string($csrf_token) ? $csrf_token : null;

$book = is_array($book) ? $book : [];
$related = is_array($related) ? $related : [];

$id = (int)($book['id'] ?? 0);
$title = htmlspecialchars((string)($book['title'] ?? '—'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$author = htmlspecialchars((string)($book['author_name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$slug = htmlspecialchars((string)($book['slug'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$desc = (string)($book['description'] ?? '');
$price = isset($book['price']) ? number_format((float)$book['price'], 2, ',', ' ') : '';
$currency = htmlspecialchars((string)($book['currency'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$stockQty = isset($book['stock_quantity']) ? (int)$book['stock_quantity'] : 0;
$available = (!empty($book['is_active']) && !empty($book['is_available']));
$cover = htmlspecialchars((string)($book['cover_path'] ?? '/eshop/img/placeholder-book.png'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$partialsDir = __DIR__ . '/../partials';
include $partialsDir . '/header.php';
include $partialsDir . '/nav.php';
include $partialsDir . '/flash.php';
?>
<div class="book-detail wrap">
    <article class="book-main">
        <div class="book-cover">
            <img src="<?= $cover ?>" alt="Obálka: <?= $title ?>" loading="lazy">
        </div>
        <div class="book-info">
            <h1><?= $title ?></h1>
            <?php if ($author !== ''): ?>
                <p class="book-author">Autor: <?= $author ?></p>
            <?php endif; ?>

            <p class="book-price"><?= $price ?> <?= $currency ?></p>
            <p class="book-availability">
                <?= $available && $stockQty > 0 ? 'Skladom' : 'Momentálne nedostupné' ?>
            </p>

            <?php if ($available && $stockQty > 0): ?>
                <form method="post"
                      action="/eshop/actions/add_to_cart.php"
                      class="add-to-cart-form"
                      aria-label="Pridať knihu do košíka">
                    <input type="hidden" name="book_id" value="<?= $id ?>">
                    <label for="quantity" class="visually-hidden">Počet kusov</label>
                    <input id="quantity" type="number" name="quantity" value="1" min="1" max="<?= $stockQty ?>">
                    <?php if ($csrf !== null): ?>
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    <?php endif; ?>
                    <button type="submit" class="btn btn-primary">Pridať do košíka</button>
                </form>
            <?php else: ?>
                <button type="button" class="btn btn-disabled" disabled>Vypredané</button>
            <?php endif; ?>
        </div>
    </article>

    <section class="book-description">
        <h2>Popis knihy</h2>
        <div class="desc">
            <?= nl2br(htmlspecialchars($desc, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?>
        </div>
    </section>

    <?php if (!empty($related)): ?>
    <section class="related-books">
        <h2>Podobné knihy</h2>
        <div class="grid related-grid">
            <?php foreach ($related as $r):
                $rid = (int)($r['id'] ?? 0);
                $rtitle = htmlspecialchars((string)($r['title'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $rslug = htmlspecialchars((string)($r['slug'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $rcover = htmlspecialchars((string)($r['cover_path'] ?? '/eshop/img/placeholder-book.png'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            ?>
            <a class="related-card" href="/eshop/book.php?slug=<?= $rslug ?>">
                <img src="<?= $rcover ?>" alt="Obálka: <?= $rtitle ?>" loading="lazy">
                <span class="related-title"><?= $rtitle ?></span>
            </a>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>
</div>
<?php
include $partialsDir . '/footer.php';
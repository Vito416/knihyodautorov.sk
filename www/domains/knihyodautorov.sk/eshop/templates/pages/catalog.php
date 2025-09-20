<?php
// templates/pages/catalog.php
declare(strict_types=1);

/**
 * Hlavná stránka e-shopu: katalóg kníh
 *
 * Premenné očakávané od kontroléra:
 *  - $pageTitle (string|null)
 *  - $user (array|null)
 *  - $navActive (string|null)
 *  - $books (array) each: id, title, author_name, slug, price, currency, is_active, is_available, stock_quantity, cover_path
 *  - $categories (array) each: id, nazov, slug
 *  - $csrf_token (string|null)
 */

$pageTitle = isset($pageTitle) ? (string)$pageTitle : 'Katalóg kníh';
$navActive = $navActive ?? 'catalog';
$books = is_array($books) ? $books : [];
$categories = is_array($categories) ? $categories : [];
$csrf = isset($csrf_token) && is_string($csrf_token) ? $csrf_token : null;

$partialsDir = __DIR__ . '/../partials';
include $partialsDir . '/header.php';
include $partialsDir . '/nav.php';
include $partialsDir . '/flash.php';
?>
<div class="catalog-page">
    <header class="catalog-header">
        <h1><?= htmlspecialchars($pageTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>

        <!-- vyhľadávanie -->
        <form method="get" action="/eshop/catalog.php"
              class="catalog-search"
              role="search"
              aria-label="Vyhľadávanie kníh">
            <label for="q" class="visually-hidden">Hľadať</label>
            <input id="q" name="q" type="search"
                   placeholder="Hľadať titul alebo autora…"
                   value="<?= htmlspecialchars($_GET['q'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <button type="submit" class="btn btn-search">🔍 Hľadať</button>
        </form>
    </header>

    <div class="catalog-layout">
        <!-- sidebar: kategórie -->
        <aside class="catalog-sidebar" aria-label="Filtre podľa kategórie">
            <div class="widget categories">
                <h2>Kategórie</h2>
                <ul>
                    <li<?= (empty($_GET['category'])) ? ' class="active"' : '' ?>>
                        <a href="/eshop/catalog.php">Všetky</a>
                    </li>
                    <?php foreach ($categories as $cat):
                        $cname = htmlspecialchars($cat['nazov'] ?? ($cat['name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                        $cslug = htmlspecialchars($cat['slug'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                        $active = (isset($_GET['category']) && (string)$_GET['category'] === ($cat['slug'] ?? '')) ? ' class="active"' : '';
                    ?>
                        <li<?= $active ?>>
                            <a href="/eshop/catalog.php?category=<?= $cslug ?>"><?= $cname ?></a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </aside>

        <!-- zoznam kníh -->
        <section class="catalog-list" aria-live="polite">
            <?php if (empty($books)): ?>
                <div class="empty">Žiadne knihy neboli nájdené.</div>
            <?php else: ?>
                <div class="grid">
                    <?php foreach ($books as $b):
                        $id = (int)($b['id'] ?? 0);
                        $title = htmlspecialchars((string)($b['title'] ?? '—'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                        $author = htmlspecialchars((string)($b['author_name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                        $slug = htmlspecialchars((string)($b['slug'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                        $price = isset($b['price']) ? number_format((float)$b['price'], 2, ',', ' ') : '';
                        $currency = htmlspecialchars((string)($b['currency'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                        $available = (!empty($b['is_active']) && !empty($b['is_available']));
                        $stockQty = isset($b['stock_quantity']) ? (int)$b['stock_quantity'] : 0;
                        $cover = htmlspecialchars((string)($b['cover_path'] ?? '/eshop/img/placeholder-book.png'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    ?>
                        <article class="book-card" data-book-id="<?= $id ?>">
                            <a class="book-link" href="/eshop/book.php?slug=<?= $slug ?>">
                                <div class="cover">
                                    <img src="<?= $cover ?>"
                                         alt="Obálka: <?= $title ?>"
                                         loading="lazy">
                                </div>
                                <h3 class="title"><?= $title ?></h3>
                                <div class="author"><?= $author ?></div>
                            </a>

                            <div class="meta">
                                <div class="price"><?= $price ?> <?= $currency ?></div>
                                <div class="availability">
                                    <?= $available ? 'Skladom' : 'Nedostupné' ?>
                                </div>
                                <?php if ($available && $stockQty > 0): ?>
                                    <form method="post"
                                          action="/eshop/actions/add_to_cart.php"
                                          class="add-to-cart-form"
                                          aria-label="Pridať do košíka">
                                        <input type="hidden" name="book_id" value="<?= $id ?>">
                                        <input type="hidden" name="quantity" value="1">
                                        <?php if ($csrf !== null): ?>
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                                        <?php endif; ?>
                                        <button type="submit" class="btn btn-primary">Do košíka</button>
                                    </form>
                                <?php else: ?>
                                    <button type="button" class="btn btn-disabled" disabled>Vypredané</button>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>

<?php
include $partialsDir . '/footer.php';
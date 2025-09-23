<?php
// templates/pages/catalog.php
declare(strict_types=1);
/**
 * Katalóg (slovenčina)
 *
 * Expects:
 *  - $books (array) each book: id,title,slug,description,price,currency,is_available,stock_quantity,author_name,category_name,cover_url
 *  - $categories (array) each category: id,nazov,slug,parent_id
 *  - $page (int), $perPage (int), $total (int), $totalPages (int)
 *  - $currentCategory (string|null)
 *  - $navActive (string|null)
 *
 * Uses partials: header.php, nav.php, footer.php (loader handled by header.php)
 */

$navActive = $navActive ?? 'catalog';
$pageTitle = $pageTitle ?? 'Katalóg';
$books = is_array($books ?? null) ? $books : [];
$categories = is_array($categories ?? null) ? $categories : [];
$page = isset($page) ? (int)$page : 1;
$perPage = isset($perPage) ? (int)$perPage : 20;
$total = isset($total) ? (int)$total : count($books);
$totalPages = isset($totalPages) ? (int)$totalPages : max(1, (int)ceil($total / max(1, $perPage)));
$currentCategory = $currentCategory ?? null;

$partials = __DIR__ . '/../partials';
try { require_once $partials . '/header.php'; } catch (\Throwable $_) {}
?>
<article class="catalog-page">
    <header class="container-hero">
        <div class="wrap">
            <h1><?= htmlspecialchars($pageTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
            <p class="small">Vyberte z našej ponuky kníh — filtrovať podľa kategórie alebo prelistovať stránky.</p>
        </div>
    </header>

    <div class="wrap catalog-layout">
        <aside class="catalog-sidebar" aria-label="Kategórie">
            <h2 class="small">Kategórie</h2>
            <ul>
                <li<?= $currentCategory === null ? ' class="active"' : '' ?>>
                    <a href="/eshop/catalog.php">Všetky</a>
                </li>
                <?php foreach ($categories as $cat): 
                    $slug = $cat['slug'] ?? '';
                    $name = $cat['nazov'] ?? ($cat['name'] ?? ''); ?>
                    <li<?= $currentCategory === $slug ? ' class="active"' : '' ?>>
                        <a href="/eshop/catalog.php?cat=<?= rawurlencode($slug) ?>">
                            <?= htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </aside>

        <section class="catalog-list" aria-label="Knihy v katalógu">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
                <div>
                    <strong><?= number_format($total, 0, ',', ' ') ?></strong> výsledkov
                    <?php if ($currentCategory): ?>
                        pre kategóriu <em><?= htmlspecialchars($currentCategory, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></em>
                    <?php endif; ?>
                </div>

                <div class="small">
                    Strana <?= (int)$page ?> / <?= (int)$totalPages ?>
                </div>
            </div>

            <?php if (empty($books)): ?>
                <div class="flash-info">V tejto sekcii sa momentálne nenachádzajú žiadne knihy.</div>
            <?php else: ?>
                <div class="grid">
                    <?php foreach ($books as $b): 
                        $bookUrl = '/eshop/book.php?slug=' . rawurlencode($b['slug'] ?? $b['id']);
                        $title = $b['title'] ?? 'Kniha';
                        $author = $b['author_name'] ?? '';
                        $cover = $b['cover_url'] ?? '/assets/book-placeholder.png';
                        $price = isset($b['price']) ? number_format((float)$b['price'], 2, ',', ' ') . ' ' . ($b['currency'] ?? 'EUR') : '';
                        $available = (int)($b['is_available'] ?? 0) === 1;
                        $short = $b['description'] ?? ($b['short_description'] ?? '');
                        ?>
                        <article class="book-card" itemtype="http://schema.org/Book" itemscope>
                            <a class="cover" href="<?= htmlspecialchars($bookUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" aria-label="<?= htmlspecialchars($title . ' — ' . $author, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                                <img src="<?= htmlspecialchars($cover, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" alt="<?= htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                            </a>

                            <div style="padding:0.5rem 0;">
                                <h3 class="title" itemprop="name">
                                    <a href="<?= htmlspecialchars($bookUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
                                </h3>
                                <div class="author small" itemprop="author"><?= htmlspecialchars($author, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                                <p class="small" style="margin-top:.5rem;"><?= htmlspecialchars(mb_strimwidth(strip_tags($short), 0, 160, '...'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                            </div>

                            <div class="meta small" style="margin-top:auto;">
                                <div class="price"><?= $price ?></div>
                                <div class="availability"><?= $available ? 'Skladom' : 'Nedostupné' ?><?= isset($b['stock_quantity']) ? ' — ' . (int)$b['stock_quantity'] . ' ks' : '' ?></div>

                                <div style="margin-top:.5rem;">
                                    <?php if ($available): ?>
                                        <form action="/eshop/cart_add.php" method="post">
                                            <?php
                                            if (class_exists('CSRF') && method_exists('CSRF', 'hiddenInput')) {
                                                try { echo CSRF::hiddenInput('csrf'); } catch (\Throwable $_) {}
                                            } else if (isset($csrf_token)) {
                                                echo '<input type="hidden" name="csrf" value="'.htmlspecialchars($csrf_token, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'">';
                                            }
                                            ?>
                                            <input type="hidden" name="book_id" value="<?= (int)($b['id'] ?? 0) ?>">
                                            <button class="btn" type="submit">Do košíka</button>
                                        </form>
                                    <?php else: ?>
                                        <button class="btn btn-secondary" disabled>Nedostupné</button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>

                <!-- pagination -->
                <nav class="small" aria-label="Stránkovanie" style="margin-top:1.25rem;">
                    <?php
                    $baseUrl = '/eshop/catalog.php';
                    $query = [];
                    if ($currentCategory) $query['cat'] = $currentCategory;
                    ?>
                    <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
                    <?php if ($page > 1): 
                        $query['p'] = $page - 1;
                        $prevUrl = $baseUrl . '?' . http_build_query($query);
                        ?>
                        <a class="btn btn-secondary" href="<?= htmlspecialchars($prevUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">« Predchádzajúca</a>
                    <?php endif; ?>

                    <?php if ($page < $totalPages):
                        $query['p'] = $page + 1;
                        $nextUrl = $baseUrl . '?' . http_build_query($query);
                        ?>
                        <a class="btn" href="<?= htmlspecialchars($nextUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Ďalšia »</a>
                    <?php endif; ?>
                    </div>
                </nav>
            <?php endif; ?>
        </section>
    </div>
</article>

<?php
// footer
$footer = __DIR__ . '/../partials/footer.php';
if (file_exists($footer)) {
    try { include $footer; } catch (\Throwable $_) {}
}
?>
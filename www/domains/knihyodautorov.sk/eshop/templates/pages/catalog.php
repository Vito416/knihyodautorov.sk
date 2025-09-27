<?php
declare(strict_types=1);

// --- výchozí hodnoty ---
$navActive = $navActive ?? 'catalog';
$pageTitle = $pageTitle ?? 'Katalóg';
$books = is_array($books ?? null) ? $books : [];
$categories = is_array($categories ?? null) ? $categories : [];
$page = isset($page) ? (int)$page : 1;
$perPage = isset($perPage) ? (int)$perPage : 20;
$total = isset($total) ? (int)$total : count($books);
$totalPages = isset($totalPages) ? (int)$totalPages : max(1, (int)ceil($total / max(1, $perPage)));
$currentCategory = $currentCategory ?? null;
$currentUserId = $currentUserId ?? null; // pokud potřebujeme

// --- základní URL pro routing ---
$baseUrl = '/eshop'; // pokud frontend controller bere /eshop jako root
?>

<article class="catalog-page">
    <header class="container-hero container-hero-epic">
        <div class="wrap hero-inner">
            <h1 class="hero-title"><?= htmlspecialchars($pageTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
            <p class="hero-sub">História, vojenské stratégie a epické príbehy. Stiahnuteľné PDF aj tlačené verzie.</p>
            <div class="hero-cta">
                <a class="btn btn-hero" href="<?= $baseUrl ?>/new">Prehliadnuť novinky</a>
                <a class="btn btn-ghost" href="<?= $baseUrl ?>/events">Zúčastniť sa súťaže</a>
            </div>
        </div>
    </header>

    <div class="wrap catalog-layout">
        <aside class="catalog-sidebar" aria-label="Kategórie">
            <h2 class="small">Kategórie</h2>
            <ul>
                <li<?= $currentCategory === null ? ' class="active"' : '' ?>>
                    <a href="<?= $baseUrl ?>/catalog">Všetky</a>
                </li>
                <?php foreach ($categories as $cat):
                    $slug = $cat['slug'] ?? '';
                    $name = $cat['nazov'] ?? ($cat['name'] ?? '');
                ?>
                    <li<?= $currentCategory === $slug ? ' class="active"' : '' ?>>
                    <a href="<?= $baseUrl ?>/catalog?cat=<?= rawurlencode($slug) ?>">
                        <?= htmlspecialchars(html_entity_decode($name), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </aside>

        <section class="catalog-list" aria-label="Knihy v katalógu">
            <div class="catalog-head" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
                <div>
                    <strong><?= number_format($total, 0, ',', ' ') ?></strong> výsledkov
                    <?php if ($currentCategory): 
                        $catName = '';
                        foreach ($categories as $cat) {
                            if (($cat['slug'] ?? '') === $currentCategory) {
                                $catName = $cat['nazov'] ?? $currentCategory;
                                break;
                            }
                        }
                    ?>
                        pre kategóriu <em><?= htmlspecialchars($catName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></em>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (empty($books)): ?>
                <div class="flash-info">V tejto sekcii sa momentálne nenachádzajú žiadne knihy.</div>
            <?php else: ?>
                <div class="grid">
                    <?php foreach ($books as $b):
                        $bookSlug = $b['slug'] ?? $b['id'];
                        $bookUrl = $baseUrl . '/detail?slug=' . rawurlencode($bookSlug);
                        $title = $b['title'] ?? 'Kniha';
                        $author = $b['author_name'] ?? '';
                        $cover = $b['cover_url'] ?? '/assets/book-placeholder-epic.png';
                        $price = isset($b['price']) ? number_format((float)$b['price'], 2, ',', ' ') . ' ' . ($b['currency'] ?? 'EUR') : '';
                        $available = (int)($b['is_available'] ?? 0) === 1;
                        $short = $b['description'] ?? '';
                        $isPdf = !empty($b['is_pdf']) || (!empty($b['asset_types']) && in_array('pdf', $b['asset_types'] ?? [], true));
                        $isOwned = isset($user['purchased_books']) && in_array((int)($b['id'] ?? 0), $user['purchased_books'], true);
                        $badges = [];
                        if ($isPdf) $badges[] = ['label'=>'PDF','class'=>'badge-digital'];
                        if (!empty($b['is_new'])) $badges[] = ['label'=>'Nové','class'=>'badge-new'];
                        if (!empty($b['is_epic'])) $badges[] = ['label'=>'Legendárne','class'=>'badge-epic'];
                    ?>
                        <article class="book-card" itemtype="http://schema.org/Book" itemscope data-book-id="<?= (int)($b['id'] ?? 0) ?>">
                            <a class="cover openDetail"
                                href="<?= htmlspecialchars($bookUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                                data-slug="<?= htmlspecialchars($bookSlug, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                                data-id="<?= (int)($b['id'] ?? 0) ?>"
                                aria-label="<?= htmlspecialchars($title . ' — ' . $author, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                                <img src="<?= htmlspecialchars($cover, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" alt="<?= htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                                <?php if ($isOwned): ?><span class="ribbon ribbon-owned" aria-hidden="true">Vlastníte</span><?php endif; ?>
                                <?php foreach ($badges as $bd): ?>
                                    <span class="card-badge <?= htmlspecialchars($bd['class']) ?>"><?= htmlspecialchars($bd['label']) ?></span>
                                <?php endforeach; ?>
                            </a>

                            <div class="card-body">
                                <h3 class="title" itemprop="name">
                                    <a class="openDetail"
                                    href="<?= htmlspecialchars($bookUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                                    data-slug="<?= htmlspecialchars($bookSlug, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                                    data-id="<?= (int)($b['id'] ?? 0) ?>">
                                    <?= htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                    </a>
                                </h3>
                                <div class="author small" itemprop="author"><?= htmlspecialchars($author, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                                <p class="small excerpt"><?= htmlspecialchars(mb_strimwidth(strip_tags($short), 0, 160, '...'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                            </div>

                            <div class="meta small">
                                <div class="price"><?= $price ?></div>
                                <div class="availability"><?= $available ? 'Skladom' : 'Nedostupné' ?></div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>
</article>
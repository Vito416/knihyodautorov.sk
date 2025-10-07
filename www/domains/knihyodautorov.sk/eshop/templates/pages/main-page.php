<?php
declare(strict_types=1);

// --- výchozí hodnoty ---
$navActive = $navActive ?? 'home';
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
<link rel="stylesheet" href="/eshop/css/main-page.css">
<article class="main-page-container" role="main">
    <div class="container-main-page-header">
        <div class="co-wrap">
            <div class="co-inner">
                <h1 id="main-title" class="main-page-title" aria-live="polite" aria-atomic="true">
                    Nájdi svoju ďalšiu knihu</h1>
                <div class="main-page-cta">
                <a class="btn btn-main-page" href="<?= $baseUrl ?>/catalog" role="button" aria-label="Prezrieť celý katalóg kníh">
                    <!-- inline SVG ikona (kniha) + text -->
                    <svg class="btn-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path d="M4 4h12v16H4zM20 6h-1v12h1a1 1 0 0 0 1-1V7a1 1 0 0 0-1-1z" fill="currentColor"/>
                    </svg>
                    <span class="btn-text">Prezrieť celý katalóg</span>
                </a>
                </div>
            </div>
        </div>
    </div>

    <section class="co-wrap main-page" aria-label="TOP knihy">
        <div class="main-page-grid-container">
        <nav class="mp-tabs" role="tablist" aria-label="Kategórie kníh">
            <button class="mp-tab is-active" role="tab" aria-selected="true" data-cat="top">Top knihy</button>
            <button class="mp-tab" role="tab" aria-selected="false" data-cat="new">Novinky</button>
            <button class="mp-tab" role="tab" aria-selected="false" data-cat="sale">Akcie</button>
            <button class="mp-tab" role="tab" aria-selected="false" data-cat="rec">Odporúčané</button>
        </nav>

            <?php if (empty($books)): ?>
                <div class="flash-info">V tejto sekcii sa momentálne nenachádzajú žiadne knihy.</div>
            <?php else: ?>
                <div class="main-page-grid">
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

                            <?php
                            // v loopu nadále dostupné: $b, $baseUrl, případně $csrf_token, případně CSRF helper
                            $bookId = (int)($b['id'] ?? 0);
                            ?>
                            <form method="post"
                                action="<?= $baseUrl ?>/cart_add"
                                class="modal-add-to-cart-form inline-add-to-cart"
                                data-ajax="cart"
                                novalidate>
                            <input type="hidden" name="book_id" value="<?= $bookId ?>">
                            <input type="hidden" name="qty" value="1">
                            <?php
                                // vloží CSRF pole pokud je k dispozici
                                if (class_exists('CSRF') && method_exists('CSRF', 'hiddenInput')) {
                                try { echo CSRF::hiddenInput('csrf'); } catch (\Throwable $_) {}
                                } elseif (!empty($csrf_token)) {
                                echo '<input type="hidden" name="csrf" value="'.htmlspecialchars($csrf_token, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'">';
                                }
                            ?>
                            <div class="card-actions" style="display:flex;gap:.5rem;margin-top:.5rem;">
                                <button type="submit" class="btn btn-primary btn-add" aria-label="Pridať do košíka">Pridať do košíka</button>

                                <!-- buy-now: přidá do košíku a přesměruje na checkout -->
                                <button type="button" class="btn btn-ghost btn-buy-now" data-action="buy-now" aria-label="Kúpiť">
                                Kúpiť
                                </button>
                            </div>
                            </form>

                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            </div>
        </section>
</article>
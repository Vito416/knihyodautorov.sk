<?php
declare(strict_types=1);
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
    <header class="container-hero container-hero-epic">
        <div class="wrap hero-inner">
            <h1 class="hero-title"><?= htmlspecialchars($pageTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
            <p class="hero-sub">História, vojenské stratégie a epické príbehy. Stiahnuteľné PDF aj tlačené verzie.</p>
            <div class="hero-cta">
                <a class="btn btn-hero" href="/eshop/new.php">Prehliadnuť novinky</a>
                <a class="btn btn-ghost" href="/eshop/events.php">Zúčastniť sa súťaže</a>
            </div>
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

            <div class="sidebar-widget">
                <h3 class="small">Filtre</h3>
                <form method="get" action="/eshop/catalog.php" class="filters">
                  <label><input type="checkbox" name="format_pdf" value="1"> Len PDF</label><br>
                  <label><input type="checkbox" name="available" value="1"> Len skladom</label><br>
                  <label><input type="checkbox" name="strategy" value="1"> Herné stratégie</label>
                </form>
            </div>

            <div class="sidebar-widget">
                <h3 class="small">Tvoje knihy</h3>
                <?php if (!empty($user) && !empty($user['purchased_books'])): ?>
                    <ul class="muted">
                    <?php foreach ($user['purchased_books'] as $pb): ?>
                        <li>📚 Kniha #<?= (int)$pb ?></li>
                    <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <div class="muted">Zatiaľ žiadne nákupy</div>
                <?php endif; ?>
            </div>
        </aside>

        <section class="catalog-list" aria-label="Knihy v katalógu">
            <div class="catalog-head" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
                <div>
                    <strong><?= number_format($total, 0, ',', ' ') ?></strong> výsledkov
                    <?php if ($currentCategory): ?>
                        pre kategóriu <em><?= htmlspecialchars($currentCategory, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></em>
                    <?php endif; ?>
                </div>

                <div class="small sort-controls">
                    <label for="sort-select">Zoradiť:</label>
                    <select id="sort-select" onchange="this.form && this.form.submit();">
                        <option value="relevance">Najrelevantnejšie</option>
                        <option value="new">Najnovšie</option>
                        <option value="price_asc">Cena ↑</option>
                        <option value="price_desc">Cena ↓</option>
                    </select>
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
                        $cover = $b['cover_url'] ?? '/assets/book-placeholder-epic.png';
                        $price = isset($b['price']) ? number_format((float)$b['price'], 2, ',', ' ') . ' ' . ($b['currency'] ?? 'EUR') : '';
                        $available = (int)($b['is_available'] ?? 0) === 1;
                        $short = $b['description'] ?? ($b['short_description'] ?? '');
                        $isPdf = !empty($b['is_pdf']) || (!empty($b['asset_types']) && in_array('pdf', $b['asset_types'] ?? [], true));
                        $isOwned = !empty($user['purchased_books']) && in_array((int)($b['id'] ?? 0), $user['purchased_books'], true);
                        $badges = [];
                        if ($isPdf) $badges[] = ['label'=>'PDF','class'=>'badge-digital'];
                        if (!empty($b['is_new'])) $badges[] = ['label'=>'Nové','class'=>'badge-new'];
                        if (!empty($b['is_epic'])) $badges[] = ['label'=>'Legendárne','class'=>'badge-epic'];
                        ?>
                        <article class="book-card" itemtype="http://schema.org/Book" itemscope data-book-id="<?= (int)($b['id'] ?? 0) ?>">
                            <a class="cover" href="<?= htmlspecialchars($bookUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" aria-label="<?= htmlspecialchars($title . ' — ' . $author, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                                <img src="<?= htmlspecialchars($cover, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" alt="<?= htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                                <?php if ($isOwned): ?>
                                  <span class="ribbon ribbon-owned" aria-hidden="true">Vlastníte</span>
                                <?php endif; ?>
                                <?php if (!empty($badges)): foreach ($badges as $bd): ?>
                                  <span class="card-badge <?= htmlspecialchars($bd['class']) ?>"><?= htmlspecialchars($bd['label']) ?></span>
                                <?php endforeach; endif; ?>
                            </a>

                            <div class="card-body">
                                <h3 class="title" itemprop="name">
                                    <a href="<?= htmlspecialchars($bookUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
                                </h3>
                                <div class="author small" itemprop="author"><?= htmlspecialchars($author, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                                <p class="small excerpt"><?= htmlspecialchars(mb_strimwidth(strip_tags($short), 0, 160, '...'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                            </div>

                            <div class="meta small">
                                <div class="price"><?= $price ?></div>
                                <div class="availability"><?= $available ? 'Skladom' : 'Nedostupné' ?></div>

                                <div class="card-actions">
                                    <?php if ($available): ?>
                                        <form action="/eshop/cart_add.php" method="post" class="inline-form">
                                            <?php
                                            if (class_exists('CSRF') && method_exists('CSRF', 'hiddenInput')) {
                                                try { echo CSRF::hiddenInput('csrf'); } catch (\Throwable $_) {}
                                            } else if (isset($csrf_token)) {
                                                echo '<input type="hidden" name="csrf" value="'.htmlspecialchars($csrf_token, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'">';
                                            }
                                            ?>
                                            <input type="hidden" name="book_id" value="<?= (int)($b['id'] ?? 0) ?>">
                                            <button class="btn btn-small" type="submit">Do košíka</button>
                                        </form>
                                    <?php else: ?>
                                        <button class="btn btn-small btn-secondary" disabled>Nedostupné</button>
                                    <?php endif; ?>

                                    <button class="btn btn-small btn-outline preview-btn" type="button"
                                      data-book='<?= htmlspecialchars(json_encode([
                                          'id'=>$b['id'] ?? null,
                                          'title'=>$title,
                                          'author'=>$author,
                                          'cover'=>$cover,
                                          'price'=>$price,
                                          'excerpt'=>mb_strimwidth(strip_tags($short),0,100,'...'),
                                          'is_pdf'=>$isPdf,
                                          'slug'=>$b['slug'] ?? $b['id']
                                      ], JSON_UNESCAPED_UNICODE), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>'>
                                      Náhľad
                                    </button>
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

<!-- Book Preview Modal (epic) -->
<div id="book-preview-modal" class="modal" role="dialog" aria-modal="true" aria-hidden="true" aria-label="Náhľad knihy">
  <div class="modal-backdrop" data-action="close"></div>
  <div class="modal-panel" role="document">
    <button class="modal-close" data-action="close" aria-label="Zavrieť">✕</button>
    <div class="modal-inner">
      <div class="modal-cover"><img src="/assets/book-placeholder-epic.png" alt=""></div>
      <div class="modal-info">
        <h2 class="modal-title"></h2>
        <div class="modal-author small"></div>
        <div class="modal-excerpt"></div>
        <div class="modal-actions" style="margin-top:1rem;">
          <a class="btn btn-ghost modal-open-book" href="#" target="_blank">Otvoriť detail</a>
          <button class="btn modal-buy" type="button">Kúpiť</button>
        </div>
      </div>
    </div>
  </div>
</div>

<?php
$footer = __DIR__ . '/../partials/footer.php';
if (file_exists($footer)) {
    try { include $footer; } catch (\Throwable $_) {}
}
?>
<?php
// templates/pages/categories.php
// Očakáva:
//  - $pageTitle (string|null)
//  - $navActive (string|null)
//  - $categories (array) each: id, nazov, slug, description
//  - $csrf_token (string|null)
//
// Používa partials: header.php, nav.php, flash.php, footer.php

$pageTitle = isset($pageTitle) ? (string)$pageTitle : 'Kategórie';
$navActive = $navActive ?? 'categories';
$categories = isset($categories) && is_array($categories) ? $categories : [];
$csrf = isset($csrf_token) && is_string($csrf_token) ? $csrf_token : null;

$partialsDir = __DIR__ . '/../partials';
include $partialsDir . '/header.php';
include $partialsDir . '/nav.php';
include $partialsDir . '/flash.php';
?>
<article class="categories-page wrap">
    <h1><?= htmlspecialchars($pageTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>

    <?php if (empty($categories)): ?>
        <p>Žiadne kategórie neboli nájdené.</p>
    <?php else: ?>
        <div class="grid categories-grid">
            <?php foreach ($categories as $c):
                $id = (int)($c['id'] ?? 0);
                $name = htmlspecialchars((string)($c['nazov'] ?? '—'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $slug = htmlspecialchars((string)($c['slug'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $desc = htmlspecialchars((string)($c['description'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            ?>
            <section class="category-card">
                <a href="/eshop/catalog.php?category=<?= $slug ?>" class="category-link">
                    <h2 class="category-name"><?= $name ?></h2>
                </a>
                <?php if ($desc !== ''): ?>
                    <p class="category-desc"><?= mb_strimwidth($desc, 0, 160, '…') ?></p>
                <?php endif; ?>
            </section>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</article>

<?php
include $partialsDir . '/footer.php';
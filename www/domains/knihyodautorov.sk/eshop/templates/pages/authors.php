<?php
// templates/pages/authors.php
// Očakáva:
//  - $pageTitle (string|null)
//  - $navActive (string|null)
//  - $authors (array) each: id, name, slug, bio, photo_path
//  - $csrf_token (string|null)
//
// Používa partials: header.php, nav.php, flash.php, footer.php

$pageTitle = isset($pageTitle) ? (string)$pageTitle : 'Autori';
$navActive = $navActive ?? 'authors';
$authors = isset($authors) && is_array($authors) ? $authors : [];
$csrf = isset($csrf_token) && is_string($csrf_token) ? $csrf_token : null;

$partialsDir = __DIR__ . '/../partials';
include $partialsDir . '/header.php';
include $partialsDir . '/nav.php';
include $partialsDir . '/flash.php';
?>
<article class="authors-page wrap">
    <h1><?= htmlspecialchars($pageTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>

    <?php if (empty($authors)): ?>
        <p>Žiadni autori neboli nájdení.</p>
    <?php else: ?>
        <div class="grid authors-grid">
            <?php foreach ($authors as $a):
                $id = (int)($a['id'] ?? 0);
                $name = htmlspecialchars((string)($a['name'] ?? '—'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $slug = htmlspecialchars((string)($a['slug'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $bio = htmlspecialchars((string)($a['bio'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $photo = htmlspecialchars((string)($a['photo_path'] ?? '/eshop/img/placeholder-author.png'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            ?>
            <section class="author-card">
                <a href="/eshop/author.php?slug=<?= $slug ?>" class="author-link">
                    <img src="<?= $photo ?>" alt="Fotografia: <?= $name ?>" loading="lazy">
                    <h2 class="author-name"><?= $name ?></h2>
                </a>
                <?php if ($bio !== ''): ?>
                    <p class="author-bio"><?= mb_strimwidth($bio, 0, 160, '…') ?></p>
                <?php endif; ?>
            </section>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</article>

<?php
include $partialsDir . '/footer.php';
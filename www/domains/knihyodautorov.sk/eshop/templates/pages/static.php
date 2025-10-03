<?php
declare(strict_types=1);

// Očakáva: $pageTitle, $article, $navActive
$pageTitle = $pageTitle ?? 'Informácie';
$article   = $article   ?? '<p>Obsah nie je dostupný.</p>';
$navActive = $navActive ?? null;
?>

<article class="page-article legal-article container">
    <header class="page-header">
        <h1><?= htmlspecialchars($pageTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
    </header>

    <div class="page-content">
        <?= $article /* trusted HTML */ ?>
    </div>
</article>
<?php
declare(strict_types=1);
$message = $message ?? 'Stránka nebola nájdená.';
$pageTitle = $pageTitle ?? '404 - Nenájdené';
?>

<article class="page-404 container">
    <header><h1><?= htmlspecialchars($pageTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1></header>
    <div class="content">
        <p><?= htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        <p><a href="/eshop">Späť na domov</a></p>
    </div>
</article>
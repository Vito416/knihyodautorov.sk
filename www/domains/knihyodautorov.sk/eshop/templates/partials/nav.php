<?php
// templates/partials/nav.php
declare(strict_types=1);

// Expects: $user (array|null), $navActive (string|null)
$navActive = $navActive ?? '';

// helper for active class (returns either '' or ' active' to avoid trimming concerns)
$active = function(string $k) use ($navActive) : string {
    return $navActive === $k ? ' active' : '';
};
?>
<nav id="main-nav" class="main-nav" role="navigation" aria-label="Hlavná navigácia">
    <ul class="nav-list" role="menubar" aria-label="Hlavné menu">
        <li class="nav-item<?= $active('catalog') ?>" role="none">
            <a role="menuitem" href="/eshop/catalog.php" <?= $navActive === 'catalog' ? 'aria-current="page"' : '' ?>>Katalóg</a>
        </li>

        <li class="nav-item<?= $active('authors') ?>" role="none">
            <a role="menuitem" href="/eshop/authors.php" <?= $navActive === 'authors' ? 'aria-current="page"' : '' ?>>Autori</a>
        </li>

        <li class="nav-item<?= $active('categories') ?>" role="none">
            <a role="menuitem" href="/eshop/categories.php" <?= $navActive === 'categories' ? 'aria-current="page"' : '' ?>>Kategórie</a>
        </li>

        <li class="nav-item<?= $active('cart') ?>" role="none">
            <a role="menuitem" href="/eshop/cart.php" <?= $navActive === 'cart' ? 'aria-current="page"' : '' ?>>Košík</a>
        </li>

        <?php if (!empty($user) && isset($user['id'])): ?>
            <li class="nav-item<?= $active('orders') ?>" role="none">
                <a role="menuitem" href="/eshop/orders.php" <?= $navActive === 'orders' ? 'aria-current="page"' : '' ?>>Objednávky</a>
            </li>
            <li class="nav-item<?= $active('account') ?>" role="none">
                <a role="menuitem" href="/eshop/profile.php" <?= $navActive === 'account' ? 'aria-current="page"' : '' ?>>Môj účet</a>
            </li>
            <li class="nav-item" role="none">
                <form method="post" action="/eshop/logout.php" class="inline-form" style="display:inline;margin:0;padding:0;">
                    <?php
                    if (class_exists('CSRF') && method_exists('CSRF', 'hiddenInput')) {
                        try { echo CSRF::hiddenInput('csrf'); } catch (\Throwable $_) {}
                    }
                    ?>
                    <button type="submit" class="btn btn-link" aria-label="Odhlásiť">Odhlásiť</button>
                </form>
            </li>
        <?php else: ?>
            <li class="nav-item<?= $active('login') ?>" role="none">
                <a role="menuitem" href="/eshop/login.php" <?= $navActive === 'login' ? 'aria-current="page"' : '' ?>>Prihlásenie</a>
            </li>
            <li class="nav-item<?= $active('register') ?>" role="none">
                <a role="menuitem" href="/eshop/register.php" <?= $navActive === 'register' ? 'aria-current="page"' : '' ?>>Registrácia</a>
            </li>
        <?php endif; ?>
    </ul>
</nav>
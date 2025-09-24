<?php
declare(strict_types=1);

$user = $user ?? ($nav_user ?? null);
$navActive = $navActive ?? ($nav_navActive ?? 'catalog');
$categories = is_array($categories) ? $categories : ($nav_categories ?? []);

$activeClass = function(string $k) use ($navActive): string {
    return $navActive === $k ? ' active' : '';
};
?>
<nav id="main-nav" class="main-nav" role="navigation" aria-label="Hlavná navigácia">
  <div class="wrap nav-inner">
    <ul class="nav-list" role="menubar" aria-label="Hlavné menu">
      <li role="none" class="nav-item<?= $activeClass('catalog') ?>">
        <a role="menuitem" href="/eshop/catalog.php" <?= $navActive === 'catalog' ? 'aria-current="page"' : '' ?>>Katalóg</a>
      </li>

      <li role="none" class="nav-item<?= $activeClass('authors') ?>">
        <a role="menuitem" href="/eshop/authors.php" <?= $navActive === 'authors' ? 'aria-current="page"' : '' ?>>Autori</a>
      </li>

      <li role="none" class="nav-item nav-dropdown<?= $activeClass('categories') ?>">
        <button aria-haspopup="true" aria-expanded="false" class="dropdown-toggle">Kategórie ▾</button>
        <div class="dropdown" role="menu" aria-label="Kategórie">
          <ul>
            <?php foreach ($categories as $cat):
                $slug = htmlspecialchars((string)($cat['slug'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $name = htmlspecialchars((string)($cat['nazov'] ?? 'Bez názvu'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                // small icon selection heuristics by slug
                $icon = '📜';
                if (strpos($slug, 'beletria') !== false) $icon = '📖';
                if (strpos($slug, 'detektiv') !== false) $icon = '🕵️';
                if (strpos($slug, 'non') !== false) $icon = '📚';
            ?>
            <li role="none"><a role="menuitem" href="/eshop/catalog.php?cat=<?= $slug ?>"><?= $icon ?> <?= $name ?></a></li>
            <?php endforeach; ?>
            <?php if (empty($categories)): ?>
            <li role="none" class="muted"><span>Neboli nájdené žiadne kategórie</span></li>
            <?php endif; ?>
          </ul>
        </div>
      </li>

      <li role="none" class="nav-item<?= $activeClass('new') ?>">
        <a role="menuitem" href="/eshop/new.php">Novinky <span class="badge badge-new">Nové</span></a>
      </li>

      <li role="none" class="nav-item<?= $activeClass('events') ?>">
        <a role="menuitem" href="/eshop/events.php">Súťaže <span class="badge badge-epic">Epické</span></a>
      </li>

      <li role="none" class="nav-item<?= $activeClass('cart') ?>">
        <a role="menuitem" href="/eshop/cart.php" <?= $navActive === 'cart' ? 'aria-current="page"' : '' ?>>Košík</a>
      </li>

      <?php if (!empty($user) && isset($user['id'])): ?>
        <li role="none" class="nav-item<?= $activeClass('orders') ?>">
          <a role="menuitem" href="/eshop/orders.php" <?= $navActive === 'orders' ? 'aria-current="page"' : '' ?>>Objednávky</a>
        </li>
        <li role="none" class="nav-item<?= $activeClass('account') ?>">
          <a role="menuitem" href="/eshop/profile.php" <?= $navActive === 'account' ? 'aria-current="page"' : '' ?>>Môj účet</a>
        </li>
      <?php else: ?>
        <li role="none" class="nav-item<?= $activeClass('login') ?>">
          <a role="menuitem" href="/eshop/login.php" <?= $navActive === 'login' ? 'aria-current="page"' : '' ?>>Prihlásenie</a>
        </li>
        <li role="none" class="nav-item<?= $activeClass('register') ?>">
          <a role="menuitem" href="/eshop/register.php" <?= $navActive === 'register' ? 'aria-current="page"' : '' ?>>Registrácia</a>
        </li>
      <?php endif; ?>
    </ul>
  </div>
</nav>
<?php
// minimal header
?><header>
<nav>
  <a href="/eshop/">Domov</a> | <a href="/eshop/catalog.php">Knihy</a> | <a href="/eshop/cart.php">Košík</a>
  <?php if (!empty($_SESSION['user_id'])): ?> | <a href="/eshop/downloads.php">Moje súbory</a> | <a href="/eshop/logout.php">Odhlásiť</a>
  <?php else: ?> | <a href="/eshop/login.php">Prihlásiť</a>
  <?php endif; ?>
</nav>
</header>
<?php
/** @var array|null $product */
/** @var string|null $error */
/** @var string $csrf */
$product = $product ?? null;
$error = $error ?? '';
?>
<!doctype html>
<html lang="sk">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= $product ? htmlspecialchars($product['title']) . ' — e-shop' : 'Produkt' ?></title>
  <link rel="stylesheet" href="/eshop/css/login.css">
</head>
<body>
  <main class="container">
    <?php if ($error): ?>
      <h1>Chyba</h1>
      <p><?= htmlspecialchars($error) ?></p>
      <p><a href="/eshop/catalog.php">Späť do katalógu</a></p>
    <?php else: ?>
      <article>
        <h1><?= htmlspecialchars($product['title']) ?></h1>
        <p style="color:#666;">Autor: <?= htmlspecialchars($product['author']) ?> · Kategória: <?= htmlspecialchars($product['category']) ?></p>
        <p><strong>Cena:</strong> <?= htmlspecialchars($product['price']) ?> <?= htmlspecialchars($product['currency']) ?></p>
        <p><strong>Na sklade:</strong> <?= (int)$product['stock'] ?></p>
        <div style="margin-top:12px;"><?= nl2br(htmlspecialchars($product['description'])) ?></div>

        <div style="margin-top:18px;">
          <form id="addCartForm" action="/eshop/actions/cart_add.php" method="post">
            <?= $csrf ?? '' ?>
            <input type="hidden" name="product_id" value="<?= (int)$product['id'] ?>">
            <label for="qty">Množstvo</label>
            <input id="qty" name="quantity" type="number" value="1" min="1" max="<?= (int)$product['stock'] ?>">
            <button type="submit">Pridať do košíka</button>
          </form>
        </div>

        <p style="margin-top:12px;"><a href="/eshop/catalog.php">Späť do katalógu</a></p>
      </article>
    <?php endif; ?>
  </main>

  <script>
  // jednoduché ajax spracovanie pridania do košíka (fallback: klasický submit)
  document.addEventListener('DOMContentLoaded', function(){
    const f = document.getElementById('addCartForm');
    if (!f) return;
    f.addEventListener('submit', async function(e){
      e.preventDefault();
      const fd = new FormData(f);
      try {
        const r = await fetch(f.action, {method:'POST', body:fd, credentials:'same-origin'});
        const j = await r.json();
        alert(j.message ?? (j.success ? 'Pridané do košíka' : 'Chyba'));
      } catch (err) {
        alert('Chyba pri kontakte so serverom.');
      }
    });
  });
  </script>
</body>
</html>
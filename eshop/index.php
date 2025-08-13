<?php
declare(strict_types=1);

/**
 * /eshop/index.php
 * Landing / hero + ukázka najnovších / bestseller kníh.
 *
 * Používá /eshop/_init.php (session, pdo, helpers).
 */

require_once __DIR__ . '/_init.php';
$pdoLocal = $GLOBALS['pdo'] ?? null;
if (!($pdoLocal instanceof PDO)) {
    eshop_log('ERROR', 'PDO nie je dostupné v index.php');
    echo "Interná chyba";
    exit;
}

// načítame pár najnovších aktívnych kníh (limit 8)
try {
    $stmt = $pdoLocal->query("SELECT id, slug, nazov, cena, mena, obrazok, popis, pdf_file FROM books WHERE is_active = 1 ORDER BY created_at DESC LIMIT 8");
    $books = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    eshop_log('ERROR', 'Chyba pri načítaní kníh na index: ' . $e->getMessage());
    $books = [];
}

// jednoduché metriky (príklad)
$totalBooks = 0;
try {
    $totalBooks = (int)$pdoLocal->query("SELECT COUNT(*) FROM books WHERE is_active = 1")->fetchColumn();
} catch (Throwable $e) {
    // ignore
}

?>
<!doctype html>
<html lang="sk">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Knihy od Autorov — domov</title>

  <!-- Webfonty: Inter + Merriweather (ak chces, uprav) -->
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;800&family=Merriweather:wght@700;900&display=swap" rel="stylesheet">

  <link rel="stylesheet" href="/eshop/css/eshop.css">
</head>
<body class="page-home">
  <header class="site-header">
    <div class="container">
      <a class="brand" href="/eshop/index.php">Knihy od Autorov</a>
      <nav class="nav">
        <a href="/eshop/catalog.php">Katalóg</a>
        <a href="/eshop/cart.php">Košík</a>
        <?php if (auth_user_id()): ?>
          <a href="/eshop/account/account.php">Môj účet</a>
        <?php else: ?>
          <a href="/eshop/account/login.php">Prihlásiť</a>
          <a class="btn btn-primary" href="/eshop/account/register.php">Registrovať</a>
        <?php endif; ?>
      </nav>
    </div>
  </header>

  <main class="container">
    <section class="hero paper-wrap">
      <div class="hero-inner">
        <h1 class="hero-title">Epické knihy. Priame od autorov.</h1>
        <p class="hero-sub">Nové tituly, limitované edície a okamžité digitálne stiahnutie po zakúpení. Vyberte si svoju ďalšiu knihu ešte dnes.</p>
        <div class="hero-ctas">
          <a class="btn btn-cta" href="/eshop/catalog.php">Prejsť do katalógu</a>
          <a class="btn" href="/eshop/catalog.php?category=1">Najpredávanejšie</a>
        </div>
      </div>
      <div class="hero-stats">
        <div class="stat">
          <div class="stat-val"><?php echo htmlspecialchars((string)$totalBooks, ENT_QUOTES|ENT_HTML5); ?></div>
          <div class="stat-label">Dostupných titulov</div>
        </div>
        <div class="stat">
          <div class="stat-val">⭐</div>
          <div class="stat-label">Výber redakcie</div>
        </div>
      </div>
    </section>

    <section class="section section-featured">
      <h2 class="section-title">Novinky</h2>
      <?php if (empty($books)): ?>
        <p class="muted">Zatiaľ žiadne nové tituly.</p>
      <?php else: ?>
        <div class="grid">
          <?php foreach ($books as $b): ?>
            <article class="book-card">
              <?php if (!empty($b['obrazok'])): ?>
                <a href="/eshop/book.php?slug=<?php echo urlencode($b['slug']); ?>"><img src="/books-img/<?php echo htmlspecialchars($b['obrazok'], ENT_QUOTES|ENT_HTML5); ?>" alt="<?php echo htmlspecialchars($b['nazov'], ENT_QUOTES|ENT_HTML5); ?>"></a>
              <?php else: ?>
                <div class="noimg">Bez obrázku</div>
              <?php endif; ?>
              <div class="book-body">
                <h3 class="book-title"><a href="/eshop/book.php?slug=<?php echo urlencode($b['slug']); ?>"><?php echo htmlspecialchars($b['nazov'], ENT_QUOTES|ENT_HTML5); ?></a></h3>
                <div class="book-meta"><?php echo !empty($b['pdf_file']) ? 'Digitálna verzia' : 'Tlačená verzia'; ?></div>
                <div class="book-foot">
                  <div class="price"><?php echo number_format((float)$b['cena'], 2, ',', ' ') . ' ' . htmlspecialchars($b['mena'], ENT_QUOTES|ENT_HTML5); ?></div>
                  <form action="/eshop/actions/cart-add.php" method="post" style="display:inline">
                    <?php csrf_field('cart'); ?>
                    <input type="hidden" name="book_id" value="<?php echo (int)$b['id']; ?>">
                    <button class="btn" type="submit">Kúpiť</button>
                  </form>
                </div>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <section class="section section-about">
      <h2 class="section-title">Prečo nakupovať u nás</h2>
      <ul class="benefits">
        <li>Priama podpora autorov</li>
        <li>Digitálne PDF ihneď po zaplatení</li>
        <li>Luxusné spracovanie faktúr</li>
      </ul>
    </section>
  </main>

  <footer class="site-footer">
    <div class="container">
      <p>© <?php echo date('Y'); ?> Knihy od Autorov — Všetky práva vyhradené</p>
      <p><a href="/eshop/catalog.php">Katalóg</a> · <a href="/eshop/account/login.php">Admin (ak treba)</a></p>
    </div>
  </footer>

  <script src="/eshop/js/eshop.js" defer></script>
</body>
</html>
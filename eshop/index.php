<?php
declare(strict_types=1);

/**
 * /eshop/index.php
 * Epický katalóg — prémiový vzhľad, filtre, featured carousel, microdata + JSON-LD.
 *
 * Požaduje: /eshop/_init.php (session, autoload, $pdo, helpery: eshop_log(), csrf_field(), auth_user_id())
 * Štýly používajú triedy: filter-panel, section-header, product-grid, product-card (index.css)
 *
 * Jazyk UI: slovenčina
 */

require_once __DIR__ . '/_init.php';

/* ---------- Kontrola PDO ---------- */
$pdoLocal = $GLOBALS['pdo'] ?? null;
if (!($pdoLocal instanceof PDO)) {
    eshop_log('ERROR', 'PDO nie je dostupné v /eshop/index.php');
    http_response_code(500);
    echo 'Interná chyba (DB).';
    exit;
}

/* ---------- baseUrl + page metadata ---------- */
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'knihyodautorov.sk';
$baseUrl = $scheme . '://' . $host;

$pageTitle = 'Katalóg | Knihy od Autorov';
$metaDescription = 'Epický katalóg kníh — výber redakcie, filtre, digitálne aj tlačené verzie, luxusné zobrazenie.';
$extraCss = ['/eshop/css/index.css'];
$extraJs  = ['/eshop/js/index.js'];

/* ---------- GET parametre (filtre/pagination) ---------- */
$q = trim((string)($_GET['q'] ?? ''));
$category = isset($_GET['category']) ? (int)$_GET['category'] : 0;
$author = isset($_GET['author']) ? (int)$_GET['author'] : 0;
$priceMin = isset($_GET['price_min']) ? (float)$_GET['price_min'] : 0.0;
$priceMax = isset($_GET['price_max']) ? (float)$_GET['price_max'] : 0.0;
$onlyDigital = isset($_GET['digital']) && (string)$_GET['digital'] === '1';
$sort = (string)($_GET['sort'] ?? 'new'); // new, price_asc, price_desc, popular
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 12;

/* ---------- Helper: bezpečné LIKE (escape %) ---------- */
function esc_like(string $s): string {
    return str_replace(['\\','%','_'], ['\\\\','\\%','\\_'], $s);
}

/* ---------- Build WHERE + params (bezpečne) ---------- */
$where = ['is_active = 1'];
$params = [];

if ($q !== '') {
    $where[] = '(nazov LIKE :q OR popis LIKE :q)';
    $params[':q'] = '%' . esc_like($q) . '%';
}
if ($category > 0) {
    $where[] = 'category_id = :cat';
    $params[':cat'] = $category;
}
if ($author > 0) {
    $where[] = 'author_id = :author';
    $params[':author'] = $author;
}
if ($priceMin > 0) {
    $where[] = 'cena >= :pmin';
    $params[':pmin'] = $priceMin;
}
if ($priceMax > 0 && $priceMax >= $priceMin) {
    $where[] = 'cena <= :pmax';
    $params[':pmax'] = $priceMax;
}
if ($onlyDigital) {
    $where[] = "pdf_file IS NOT NULL AND pdf_file <> ''";
}

/* Radenie */
$orderSql = 'ORDER BY created_at DESC';
switch ($sort) {
    case 'price_asc': $orderSql = 'ORDER BY cena ASC'; break;
    case 'price_desc': $orderSql = 'ORDER BY cena DESC'; break;
    case 'popular': $orderSql = 'ORDER BY created_at DESC'; break;
    case 'new':
    default: $orderSql = 'ORDER BY created_at DESC'; break;
}

$whereSql = implode(' AND ', $where);

/* ---------- COUNT pre pagináciu ---------- */
$total = 0;
try {
    $countSql = "SELECT COUNT(*) FROM books WHERE $whereSql";
    $stmt = $pdoLocal->prepare($countSql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->execute();
    $total = (int)$stmt->fetchColumn();
} catch (Throwable $e) {
    eshop_log('ERROR', 'Chyba pri COUNT books: ' . $e->getMessage());
    $total = 0;
}

/* ---------- SELECT s LIMIT/OFFSET ---------- */
$offset = ($page - 1) * $perPage;
$selectSql = "SELECT id, slug, nazov, cena, mena, obrazok, popis, pdf_file FROM books WHERE $whereSql $orderSql LIMIT :limit OFFSET :offset";

$books = [];
try {
    $stmt = $pdoLocal->prepare($selectSql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $books = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    eshop_log('ERROR', 'Chyba pri SELECT books: ' . $e->getMessage());
    $books = [];
}

/* ---------- Featured (výber redakcie) — jednoduchý query (limit 6) ---------- */
$featured = [];
try {
    $fstmt = $pdoLocal->query("SELECT id, slug, nazov, cena, mena, obrazok, popis FROM books WHERE is_active = 1 ORDER BY created_at DESC LIMIT 6");
    $featured = $fstmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // ticho
}

/* ---------- Načítanie kategórií a autorov (pre filter panel) ---------- */
$categories = [];
try {
    $stmt = $pdoLocal->query("SELECT id, nazov FROM categories ORDER BY nazov ASC");
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    eshop_log('ERROR', 'Chyba pri načítaní categories: ' . $e->getMessage());
    $categories = [];
}

$authors = [];
try {
    $stmt = $pdoLocal->query("SELECT id, meno FROM authors ORDER BY meno ASC");
    $authors = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    eshop_log('ERROR', 'Chyba pri načítaní authors: ' . $e->getMessage());
    $authors = [];
}

/* ---------- INCLUDE header ---------- */
require __DIR__ . '/templates/header.php';

/* ---------- Small helper for links (keeps query) ---------- */
if (!function_exists('build_query_link')) {
    function build_query_link(array $override = []): string {
        $params = $_GET;
        foreach ($override as $k => $v) {
            $params[$k] = $v;
        }
        $qs = http_build_query($params);
        return '/eshop/index.php' . ($qs !== '' ? ('?' . $qs) : '');
    }
}

/* ---------- Output HTML ---------- */
?>
<main class="eshop-wrapper" role="main">
  <!-- SHOP HEADER (textura + ornament) -->
  <header class="section-header" aria-hidden="false" style="margin-bottom: 1.5rem;">
    <h2>Výber redakcie</h2>
  </header>

  <!-- FEATURED CAROUSEL (ak existuje) -->
  <?php if (!empty($featured)): ?>
    <section class="section-pergamen" aria-label="Výber redakcie" style="margin-bottom:2rem;">
      <div class="container" data-animate="fade-in">
        <div class="carousel" style="position:relative;">
          <div class="carousel__track" style="display:grid;grid-auto-flow:column;grid-auto-columns:minmax(260px,1fr);gap:1rem;overflow-x:auto;padding:0 0.25rem;">
            <?php foreach ($featured as $f): 
              $pUrl = $baseUrl . '/eshop/book.php?slug=' . urlencode($f['slug']);
              $img = !empty($f['obrazok']) ? '/books-img/' . htmlspecialchars($f['obrazok'], ENT_QUOTES|ENT_HTML5) : '/assets/placeholder-book.png';
            ?>
              <article class="product-card" style="min-width:260px;">
                <a href="<?= htmlspecialchars($pUrl, ENT_QUOTES|ENT_HTML5); ?>">
                  <img src="<?= $img; ?>" alt="<?= htmlspecialchars($f['nazov'], ENT_QUOTES|ENT_HTML5); ?>">
                </a>
                <div style="padding:10px;">
                  <h3 style="margin:0 0 .5rem;"><a href="<?= htmlspecialchars($pUrl, ENT_QUOTES|ENT_HTML5); ?>"><?= htmlspecialchars($f['nazov'], ENT_QUOTES|ENT_HTML5); ?></a></h3>
                  <div style="color:#7a613f;font-weight:700;"><?= number_format((float)$f['cena'],2,',',' ') . ' ' . htmlspecialchars($f['mena'], ENT_QUOTES|ENT_HTML5); ?></div>
                </div>
              </article>
            <?php endforeach; ?>
          </div>

          <button class="carousel__btn" data-action="prev" aria-label="Predchádzajúce" style="position:absolute;left:0;top:50%;transform:translateY(-50%);">‹</button>
          <button class="carousel__btn" data-action="next" aria-label="Ďalšie" style="position:absolute;right:0;top:50%;transform:translateY(-50%);">›</button>
        </div>
      </div>
    </section>
  <?php endif; ?>

  <!-- GRID LAYOUT: SIDEBAR + CONTENT -->
  <div style="display:grid;grid-template-columns: 320px 1fr; gap:28px; align-items:start;">
    <!-- SIDEBAR (filtre) -->
    <aside class="filter-panel" aria-label="Filtre">
      <h4>Filtrovať</h4>
      <form method="get" action="/eshop/index.php" class="filters" novalidate>
        <label for="q">Hľadať</label>
        <input id="q" name="q" type="search" value="<?= htmlspecialchars($q, ENT_QUOTES|ENT_HTML5); ?>" placeholder="názov alebo popis...">

        <label for="category">Kategória</label>
        <select id="category" name="category">
          <option value="0">Všetky</option>
          <?php foreach ($categories as $c): ?>
            <option value="<?= (int)$c['id']; ?>" <?= $category === (int)$c['id'] ? 'selected' : ''; ?>><?= htmlspecialchars($c['nazov'], ENT_QUOTES|ENT_HTML5); ?></option>
          <?php endforeach; ?>
        </select>

        <label for="author">Autor</label>
        <select id="author" name="author">
          <option value="0">Všetci</option>
          <?php foreach ($authors as $a): ?>
            <option value="<?= (int)$a['id']; ?>" <?= $author === (int)$a['id'] ? 'selected' : ''; ?>><?= htmlspecialchars($a['meno'], ENT_QUOTES|ENT_HTML5); ?></option>
          <?php endforeach; ?>
        </select>

        <label>Cena (EUR)</label>
        <div style="display:flex;gap:.5rem;">
          <input name="price_min" type="number" step="0.01" min="0" placeholder="min" value="<?= $priceMin > 0 ? htmlspecialchars((string)$priceMin, ENT_QUOTES|ENT_HTML5) : ''; ?>">
          <input name="price_max" type="number" step="0.01" min="0" placeholder="max" value="<?= $priceMax > 0 ? htmlspecialchars((string)$priceMax, ENT_QUOTES|ENT_HTML5) : ''; ?>">
        </div>

        <label style="margin-top:.5rem;">
          <input type="checkbox" name="digital" value="1" <?= $onlyDigital ? 'checked' : ''; ?>>
          Iba digitálne (PDF)
        </label>

        <label for="sort">Zoradiť</label>
        <select id="sort" name="sort">
          <option value="new" <?= $sort === 'new' ? 'selected' : ''; ?>>Najnovšie</option>
          <option value="price_asc" <?= $sort === 'price_asc' ? 'selected' : ''; ?>>Cena: vzostupne</option>
          <option value="price_desc" <?= $sort === 'price_desc' ? 'selected' : ''; ?>>Cena: zostupne</option>
        </select>

        <div style="margin-top:.75rem;display:flex;gap:.5rem;">
          <button class="btn btn-primary" type="submit">Použiť</button>
          <a class="btn" href="/eshop/index.php">Vyčistiť</a>
        </div>
      </form>

      <div style="margin-top:1rem;" class="promo-box">
        <strong>Luxusné faktúry</strong>
        <p>Automaticky generované PDF faktúry so zlatými detailmi a QR kódom.</p>
      </div>
    </aside>

    <!-- CONTENT: produktová mriežka -->
    <section style="min-height:200px;">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
        <div><strong><?= number_format($total,0,',',' '); ?></strong> výsledkov</div>
        <div style="display:flex;gap:.5rem;align-items:center;">
          <a class="btn" href="/eshop/catalog.php">Rozšírené</a>
        </div>
      </div>

      <?php if (empty($books)): ?>
        <div class="muted">Neboli nájdené žiadne produkty podľa zadaných kritérií.</div>
      <?php else: ?>
        <div class="product-grid" role="list">
          <?php foreach ($books as $b):
            $productUrl = $baseUrl . '/eshop/book.php?slug=' . urlencode($b['slug']);
            $imgPublic = !empty($b['obrazok']) ? '/books-img/' . htmlspecialchars($b['obrazok'], ENT_QUOTES|ENT_HTML5) : '/assets/placeholder-book.png';
          ?>
            <article class="product-card" itemscope itemtype="http://schema.org/Product" role="listitem">
              <a href="<?= htmlspecialchars($productUrl, ENT_QUOTES|ENT_HTML5); ?>" itemprop="url" class="card-media" style="display:block;">
                <img src="<?= $imgPublic; ?>" alt="<?= htmlspecialchars($b['nazov'], ENT_QUOTES|ENT_HTML5); ?>" itemprop="image">
              </a>

              <div class="card-body" style="display:flex;flex-direction:column;margin-top:.5rem;">
                <h3 itemprop="name" style="margin:0 0 .4rem;"><a href="<?= htmlspecialchars($productUrl, ENT_QUOTES|ENT_HTML5); ?>"><?= htmlspecialchars($b['nazov'], ENT_QUOTES|ENT_HTML5); ?></a></h3>

                <div class="muted" style="flex:0 0 auto; color:#7b6345;"><?= htmlspecialchars(mb_strimwidth((string)$b['popis'], 0, 120, '...'), ENT_QUOTES|ENT_HTML5); ?></div>

                <div style="margin-top:auto; display:flex; align-items:center; justify-content:space-between; gap:.5rem;">
                  <div class="price" itemprop="offers" itemscope itemtype="http://schema.org/Offer">
                    <meta itemprop="priceCurrency" content="<?= htmlspecialchars($b['mena'], ENT_QUOTES|ENT_HTML5); ?>">
                    <meta itemprop="price" content="<?= number_format((float)$b['cena'], 2, '.', ''); ?>">
                    <span style="font-weight:800;color:#8b5e00;"><?= number_format((float)$b['cena'],2,',',' ') . ' ' . htmlspecialchars($b['mena'], ENT_QUOTES|ENT_HTML5); ?></span>
                  </div>

                  <form action="/eshop/actions/cart-add.php" method="post" style="margin:0;">
                    <?php csrf_field('cart'); ?>
                    <input type="hidden" name="book_id" value="<?= (int)$b['id']; ?>">
                    <button class="btn btn-buy" type="submit">Kúpiť</button>
                  </form>
                </div>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <!-- PAGINATION -->
      <?php
        $totalPages = (int)ceil(max(1, $total) / $perPage);
        $showPages = 7;
        $startPage = max(1, $page - (int)floor($showPages / 2));
        $endPage = min($totalPages, $startPage + $showPages - 1);
        if ($startPage > 1) $startPage = max(1, $endPage - $showPages + 1);
      ?>
      <?php if ($totalPages > 1): ?>
        <nav class="pagination" aria-label="Stránkovanie produktov" style="margin-top:1rem;">
          <?php if ($page > 1): ?>
            <a class="page" href="<?= htmlspecialchars(build_query_link(['page' => $page - 1]), ENT_QUOTES|ENT_HTML5); ?>">&laquo; Predchádzajúca</a>
          <?php endif; ?>

          <?php for ($p = $startPage; $p <= $endPage; $p++): ?>
            <?php if ($p === $page): ?>
              <span class="page current"><?= $p; ?></span>
            <?php else: ?>
              <a class="page" href="<?= htmlspecialchars(build_query_link(['page' => $p]), ENT_QUOTES|ENT_HTML5); ?>"><?= $p; ?></a>
            <?php endif; ?>
          <?php endfor; ?>

          <?php if ($page < $totalPages): ?>
            <a class="page" href="<?= htmlspecialchars(build_query_link(['page' => $page + 1]), ENT_QUOTES|ENT_HTML5); ?>">Ďalšia &raquo;</a>
          <?php endif; ?>
        </nav>
      <?php endif; ?>

    </section>
  </div>
</main>

<?php
/* ---------- JSON-LD: Organization + WebSite + Breadcrumb + ItemList (pre SEO) ---------- */
$org = [
  '@context' => 'https://schema.org',
  '@type' => 'Organization',
  'name' => 'Knihy od Autorov',
  'url' => $baseUrl,
  'logo' => $baseUrl . '/assets/logo.png'
];

$website = [
  '@context' => 'https://schema.org',
  '@type' => 'WebSite',
  'url' => $baseUrl,
  'name' => 'Knihy od Autorov',
  'potentialAction' => [
    '@type' => 'SearchAction',
    'target' => $baseUrl . '/eshop/index.php?q={search_term_string}',
    'query-input' => 'required name=search_term_string'
  ]
];

$breadcrumbs = [
  '@context' => 'https://schema.org',
  '@type' => 'BreadcrumbList',
  'itemListElement' => [
    ['@type' => 'ListItem','position' => 1,'name' => 'Domov','item' => $baseUrl],
    ['@type' => 'ListItem','position' => 2,'name' => 'Katalóg','item' => $baseUrl . '/eshop/index.php']
  ]
];

$itemList = [
  '@context' => 'https://schema.org',
  '@type' => 'ItemList',
  'itemListElement' => []
];

$pos = 1;
foreach ($books as $b) {
    $prodUrl = $baseUrl . '/eshop/book.php?slug=' . urlencode($b['slug']);
    $img = !empty($b['obrazok']) ? ($baseUrl . '/books-img/' . $b['obrazok']) : ($baseUrl . '/assets/placeholder-book.png');
    $itemList['itemListElement'][] = [
        '@type' => 'ListItem',
        'position' => $pos++,
        'url' => $prodUrl,
        'item' => [
            '@type' => 'Product',
            'name' => $b['nazov'],
            'image' => $img,
            'description' => mb_strimwidth((string)$b['popis'], 0, 250, '...'),
            'sku' => $b['slug'],
            'offers' => [
                '@type' => 'Offer',
                'url' => $prodUrl,
                'priceCurrency' => $b['mena'],
                'price' => number_format((float)$b['cena'], 2, '.', ''),
                'availability' => !empty($b['pdf_file']) ? 'https://schema.org/InStock' : 'https://schema.org/InStock'
            ]
        ]
    ];
}

$allLd = [$org, $website, $breadcrumbs, $itemList];
echo '<script type="application/ld+json">' . PHP_EOL;
echo json_encode($allLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
echo PHP_EOL . '</script>' . PHP_EOL;

/* ---------- include footer ---------- */
require __DIR__ . '/templates/footer.php';

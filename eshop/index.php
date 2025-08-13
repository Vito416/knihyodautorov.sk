<?php
// /eshop/index.php
require __DIR__ . '/_init.php';

/**
 * Simple catalogue with pagination, optional category & search.
 * - fulltext search via books.FULLTEXT (if supported) or LIKE fallback
 */

$perPage = 12;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$q = trim((string)($_GET['q'] ?? ''));
$cat = trim((string)($_GET['category'] ?? ''));

// build SQL
$where = ['is_active = 1'];
$params = [];

if ($cat !== '') {
    $where[] = 'c.slug = ?';
    $params[] = $cat;
}

if ($q !== '') {
    // try fulltext available?
    $useFT = false;
    try {
        // check that ft index exists
        $res = $pdo->query("SHOW INDEX FROM books WHERE Key_name = 'ft_title_popis'")->fetchAll();
        if (!empty($res)) $useFT = true;
    } catch (Throwable $e){}

    if ($useFT) {
        $where[] = "MATCH(nazov,popis) AGAINST (? IN NATURAL LANGUAGE MODE)";
        $params[] = $q;
    } else {
        $where[] = "(nazov LIKE ? OR popis LIKE ?)";
        $params[] = "%$q%";
        $params[] = "%$q%";
    }
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$totalStmt = $pdo->prepare("SELECT COUNT(*) FROM books b LEFT JOIN categories c ON b.category_id = c.id $whereSql");
$totalStmt->execute($params);
$total = (int)$totalStmt->fetchColumn();

$stmt = $pdo->prepare("SELECT b.id, b.nazov, b.slug, b.cena, b.obrazok, a.meno AS autor, c.nazov AS category FROM books b LEFT JOIN authors a ON b.author_id = a.id LEFT JOIN categories c ON b.category_id = c.id $whereSql ORDER BY b.created_at DESC LIMIT {$perPage} OFFSET {$offset}");
$stmt->execute($params);
$books = $stmt->fetchAll(PDO::FETCH_ASSOC);

// categories for filter
$cats = $pdo->query("SELECT id, nazov, slug FROM categories ORDER BY nazov ASC")->fetchAll(PDO::FETCH_ASSOC);

// include header (you can add site header), simple layout here:
?>
<!doctype html>
<html lang="sk">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Knihy — Knihy od autorov</title>
  <link rel="stylesheet" href="<?php echo eshop_asset('eshop/css/eshop.css'); ?>">
</head>
<body class="eshop-page">
  <header class="eshop-header">
    <div class="wrap">
      <a class="brand" href="/"><img src="<?php echo eshop_asset('assets/logoobdelnikbezpozadi.png');?>" alt="logo" /></a>
      <form class="search-form" action="/eshop/index.php" method="get">
        <input name="q" type="search" placeholder="Hľadať knihu..." value="<?php echo eshop_esc($q); ?>">
        <button>Hľadať</button>
      </form>
      <nav class="mini-nav">
        <a href="/eshop/cart.php">Košík (<?php echo eshop_cart_count(); ?>)</a>
      </nav>
    </div>
  </header>

  <main class="eshop-wrap">
    <div class="container">
      <aside class="catalog-sidebar">
        <h4>Kategórie</h4>
        <ul>
          <li><a href="/eshop/index.php">Všetko</a></li>
          <?php foreach ($cats as $c): ?>
            <li><a href="/eshop/index.php?category=<?php echo eshop_esc($c['slug']); ?>"><?php echo eshop_esc($c['nazov']); ?></a></li>
          <?php endforeach; ?>
        </ul>
      </aside>

      <section class="catalog-main">
        <div class="catalog-header">
          <h1>Knihy</h1>
          <p class="muted"><?php echo (int)$total; ?> výsledkov</p>
        </div>

        <div class="products-grid">
          <?php if (empty($books)): ?>
            <div class="empty">Nenašli sa žiadne knihy.</div>
          <?php else: foreach ($books as $b): ?>
            <article class="product-card">
              <a class="cover" href="/eshop/book.php?slug=<?php echo eshop_esc($b['slug']); ?>">
                <img src="<?php echo eshop_asset('books-img/' . ($b['obrazok'] ?: 'placeholder.png')); ?>" alt="<?php echo eshop_esc($b['nazov']); ?>">
              </a>
              <div class="meta">
                <h3><a href="/eshop/book.php?slug=<?php echo eshop_esc($b['slug']); ?>"><?php echo eshop_esc($b['nazov']); ?></a></h3>
                <div class="author"><?php echo eshop_esc($b['autor'] ?? ''); ?></div>
                <div class="price"><?php echo number_format((float)$b['cena'], 2, ',', '.'); ?> €</div>
                <form class="add-form" action="/eshop/actions/cart-add.php" method="post">
                  <input type="hidden" name="book_id" value="<?php echo (int)$b['id']; ?>">
                  <input type="hidden" name="csrf" value="<?php echo eshop_csrf_token(); ?>">
                  <button class="btn-add">Pridať do košíka</button>
                </form>
              </div>
            </article>
          <?php endforeach; endif; ?>
        </div>

        <div class="pagination">
          <?php
            $pages = max(1, ceil($total / $perPage));
            for ($i=1;$i<=$pages;$i++):
                $url = '/eshop/index.php?page='.$i;
                if ($q) $url .= '&q='.urlencode($q);
                if ($cat) $url .= '&category='.urlencode($cat);
          ?>
            <a class="<?php echo $i==$page?'active':''; ?>" href="<?php echo $url; ?>"><?php echo $i; ?></a>
          <?php endfor; ?>
        </div>
      </section>
    </div>
  </main>

  <footer class="eshop-footer">
    <div class="container"><p>© <?php echo date('Y'); ?> Knihy od autorov</p></div>
  </footer>

  <script src="<?php echo eshop_asset('eshop/js/eshop.js'); ?>"></script>
</body>
</html>
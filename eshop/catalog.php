<?php
declare(strict_types=1);
/**
 * /eshop/catalog.php
 *
 * Zobrazenie katalógu kníh s podporou:
 * - fulltext vyhľadávania (MATCH...AGAINST) na poli (nazov,popis) ak súbor DB to podporuje
 * - filter podľa autora (author_id) a kategórie (category_id)
 * - stránkovanie
 *
 * GET parametre:
 * - q (string) - fulltext dotaz
 * - author (int) - id autora
 * - category (int) - id kategórie
 * - page (int) - číslo strany (1..n)
 * - per_page (int) - položiek na stránku (max 48)
 */

require_once __DIR__ . '/_init.php';
$pdoLocal = $GLOBALS['pdo'] ?? null;
if (!($pdoLocal instanceof PDO)) {
    eshop_log('ERROR', 'PDO nie je dostupné v catalog.php');
    flash_set('error', 'Interná chyba (DB).');
    redirect('./');
}

// parametre
$q = trim((string)($_GET['q'] ?? ''));
$authorId = isset($_GET['author']) ? (int)$_GET['author'] : 0;
$categoryId = isset($_GET['category']) ? (int)$_GET['category'] : 0;
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = (int)($_GET['per_page'] ?? 12);
$perPage = max(6, min(48, $perPage)); // obmedzenie rozumného rozsahu

// základ WHERE
$where = ['b.is_active = 1'];
$params = [];

// filtre
if ($authorId > 0) {
    $where[] = 'b.author_id = :author';
    $params[':author'] = $authorId;
}
if ($categoryId > 0) {
    $where[] = 'b.category_id = :category';
    $params[':category'] = $categoryId;
}

// search: prefer MATCH...AGAINST (FULLTEXT) ak dotaz nie je prázdny; fallback na LIKE
$useFulltext = ($q !== '');
$searchSql = '';
if ($useFulltext) {
    // použijeme NATURAL LANGUAGE MODE (ak DB podporuje FULLTEXT). Ak FULLTEXT nie je, query môže vrátiť chybu - ale predpokladáme, že index existuje podľa DB návrhu.
    $where[] = 'MATCH(b.nazov, b.popis) AGAINST(:q IN NATURAL LANGUAGE MODE)';
    $params[':q'] = $q;
}

// zostavíme WHERE klauzulu
$whereSql = implode(' AND ', $where);

// COUNT pre stránkovanie
try {
    $countSql = "SELECT COUNT(*) FROM books b WHERE {$whereSql}";
    $stmtC = $pdoLocal->prepare($countSql);
    $stmtC->execute($params);
    $total = (int)$stmtC->fetchColumn();
} catch (Throwable $e) {
    // ak fulltext spôsobil chybu (napr. nie je index) - fallback na LIKE a prepočet
    eshop_log('WARN', 'COUNT v catalog.php zlyhal: ' . $e->getMessage());
    if ($useFulltext) {
        // fallback: použijeme LIKE na nazov a popis
        $where = ['b.is_active = 1'];
        if ($authorId > 0) { $where[] = 'b.author_id = :author'; }
        if ($categoryId > 0) { $where[] = 'b.category_id = :category'; }
        $where[] = '(b.nazov LIKE :likeq OR b.popis LIKE :likeq)';
        $whereSql = implode(' AND ', $where);
        $params = [];
        if ($authorId > 0) $params[':author'] = $authorId;
        if ($categoryId > 0) $params[':category'] = $categoryId;
        $params[':likeq'] = '%' . $q . '%';
        $stmtC = $pdoLocal->prepare("SELECT COUNT(*) FROM books b WHERE {$whereSql}");
        $stmtC->execute($params);
        $total = (int)$stmtC->fetchColumn();
    } else {
        throw $e;
    }
}

// pagination math
$totalPages = $total > 0 ? (int)ceil($total / $perPage) : 1;
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

// načítanie položiek
try {
    // base select s joinami pre autor / kategóriu
    $selectSql = "SELECT b.id, b.slug, b.nazov, b.popis, b.cena, b.mena, b.obrazok, a.meno AS autor, c.nazov AS kategoria
                  FROM books b
                  LEFT JOIN authors a ON b.author_id = a.id
                  LEFT JOIN categories c ON b.category_id = c.id
                  WHERE {$whereSql}
                  ORDER BY b.created_at DESC
                  LIMIT :limit OFFSET :offset";

    $stmt = $pdoLocal->prepare($selectSql);
    // bind params (parametre vyplnime)
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $books = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    eshop_log('ERROR', 'Chyba pri načítaní katalógu: ' . $e->getMessage());
    flash_set('error', 'Chyba pri načítaní katalógu.');
    $books = [];
}

// Načítame zoznam autorov a kategórií pre filtre (ľahké)
$authors = $categories = [];
try {
    $authors = $pdoLocal->query("SELECT id, meno FROM authors ORDER BY meno ASC")->fetchAll(PDO::FETCH_ASSOC);
    $categories = $pdoLocal->query("SELECT id, nazov FROM categories ORDER BY nazov ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // ignorujeme ne-kritickú chybu
    eshop_log('WARN', 'Nepodarilo sa načítať autori/kategórie: ' . $e->getMessage());
}

// helper pre query string pri paginácii (zachováme filtre)
function qs(array $over = []): string {
    $base = $_GET;
    foreach ($over as $k => $v) {
        if ($v === null) unset($base[$k]);
        else $base[$k] = $v;
    }
    return http_build_query($base);
}
?>
<!doctype html>
<html lang="sk">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Katalóg kníh — Knihy od Autorov</title>
  <link rel="stylesheet" href="/eshop/css/eshop.css">
  <style>
    .wrap { max-width:1100px; margin:36px auto; padding:24px; background:var(--paper,#fff); border-radius:12px; }
    .grid { display:grid; grid-template-columns: repeat(auto-fill,minmax(220px,1fr)); gap:18px; }
    .book-card { padding:12px; border-radius:12px; background:#fff; box-shadow:0 6px 12px rgba(0,0,0,0.04); }
    .book-card img { width:100%; height:260px; object-fit:cover; border-radius:8px; }
    .meta { margin-top:8px; font-size:0.9rem; color:var(--muted,#9e8e7b); }
    .price { font-weight:700; margin-top:6px; }
    .filters { display:flex; gap:12px; flex-wrap:wrap; margin-bottom:12px; }
    .search { flex:1; }
    .btn { padding:8px 12px; border-radius:8px; text-decoration:none; background:var(--accent,#c08a2e); color:#fff; display:inline-block; }
  </style>
</head>
<body>
    <!-- SITE HEADER (vložit do všech stránek které chybí navigace) -->
  <header class="site-header">
    <div class="container">
      <a class="brand" href="/eshop/index.php">Knihy od Autorov</a>
      <nav class="nav">
        <a href="/eshop/index.php">Domov</a>
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
  <div class="wrap paper-wrap">
    <h1>Katalóg</h1>

    <?php foreach (flash_all() as $m) echo '<div class="note">'.htmlspecialchars((string)$m,ENT_QUOTES|ENT_HTML5).'</div>'; ?>

    <form method="get" action="/eshop/catalog.php" style="margin-bottom:12px;">
      <div class="filters">
        <div class="search" style="flex:2;">
          <input type="search" name="q" placeholder="Hľadať podľa názvu alebo popisu..." value="<?php echo htmlspecialchars($q, ENT_QUOTES|ENT_HTML5); ?>" style="width:100%; padding:10px; border-radius:8px; border:1px solid #e6dfd0;">
        </div>

        <div>
          <select name="author">
            <option value="">Všetci autori</option>
            <?php foreach ($authors as $a): ?>
              <option value="<?php echo (int)$a['id']; ?>" <?php if ($authorId === (int)$a['id']) echo 'selected'; ?>><?php echo htmlspecialchars($a['meno'], ENT_QUOTES|ENT_HTML5); ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <select name="category">
            <option value="">Všetky kategórie</option>
            <?php foreach ($categories as $c): ?>
              <option value="<?php echo (int)$c['id']; ?>" <?php if ($categoryId === (int)$c['id']) echo 'selected'; ?>><?php echo htmlspecialchars($c['nazov'], ENT_QUOTES|ENT_HTML5); ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <select name="per_page">
            <?php foreach ([6,12,24,48] as $pp): ?>
              <option value="<?php echo $pp; ?>" <?php if ($perPage === $pp) echo 'selected'; ?>><?php echo $pp; ?> / strana</option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <button class="btn" type="submit">Hľadať</button>
        </div>
      </div>
    </form>

    <?php if (empty($books)): ?>
      <p class="muted">Nenašli sa žiadne knihy pre zadané kritériá.</p>
    <?php else: ?>
      <div class="grid">
        <?php foreach ($books as $b): ?>
          <article class="book-card">
            <?php if (!empty($b['obrazok'])): ?>
              <a href="/eshop/book.php?slug=<?php echo urlencode($b['slug']); ?>"><img src="/books-img/<?php echo htmlspecialchars($b['obrazok'], ENT_QUOTES|ENT_HTML5); ?>" alt="<?php echo htmlspecialchars($b['nazov'], ENT_QUOTES|ENT_HTML5); ?>"></a>
            <?php else: ?>
              <div style="height:260px; background:linear-gradient(180deg,#f7f4ef,#fffaf1); border-radius:8px; display:flex; align-items:center; justify-content:center; color:var(--muted,#9e8e7b);">Bez obrázku</div>
            <?php endif; ?>

            <h3 style="margin:10px 0 4px;"><a href="/eshop/book.php?slug=<?php echo urlencode($b['slug']); ?>"><?php echo htmlspecialchars($b['nazov'], ENT_QUOTES|ENT_HTML5); ?></a></h3>
            <div class="meta"><?php echo htmlspecialchars($b['autor'] ?? '-', ENT_QUOTES|ENT_HTML5); ?> &middot; <?php echo htmlspecialchars($b['kategoria'] ?? '-', ENT_QUOTES|ENT_HTML5); ?></div>

            <div class="price"><?php echo number_format((float)$b['cena'], 2, ',', ' ') . ' ' . htmlspecialchars($b['mena'], ENT_QUOTES|ENT_HTML5); ?></div>

            <div style="margin-top:8px;">
              <form action="/eshop/actions/cart-add.php" method="post" style="display:inline">
                <?php csrf_field('cart'); ?>
                <input type="hidden" name="book_id" value="<?php echo (int)$b['id']; ?>">
                <button class="btn" type="submit">Pridať do košíka</button>
              </form>
              <a class="btn" href="/eshop/book.php?slug=<?php echo urlencode($b['slug']); ?>" style="background:#6b5a44; margin-left:6px;">Detail</a>
            </div>
          </article>
        <?php endforeach; ?>
      </div>

      <!-- pagination -->
      <div style="margin-top:18px; display:flex; justify-content:center; gap:8px; align-items:center;">
        <?php if ($page > 1): ?>
          <a class="btn" href="/eshop/catalog.php?<?php echo qs(['page'=> $page - 1]); ?>">&laquo; Predchádzajúca</a>
        <?php endif; ?>

        <span class="muted">Strana <?php echo $page; ?> z <?php echo $totalPages; ?> (<?php echo $total; ?> položiek)</span>

        <?php if ($page < $totalPages): ?>
          <a class="btn" href="/eshop/catalog.php?<?php echo qs(['page'=> $page + 1]); ?>">Ďalšia &raquo;</a>
        <?php endif; ?>
      </div>
    <?php endif; ?>

  </div>
</body>
</html>
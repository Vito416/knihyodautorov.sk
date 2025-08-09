<?php
// eshop/eshop.php
// Výpis produktov (PDF knihy) s filtrovaním a stránkovaním
// Uprav include cesty podľa tvojej štruktúry

require_once __DIR__ . '/../db/config/config.php'; // musí vracať $pdo

// voliteľne include header/footer (cesty môžu byť ../header.php)
if (file_exists(__DIR__ . '/../partials/header.php')) {
    include __DIR__ . '/../partials/header.php';
} else {
    // jednoduchý fallback header
    echo '<!doctype html><html lang="sk"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>E-shop — Knihy od Autorov</title>';
    echo '</head><body>';
}

// Načítanie CSS/JS pre túto stránku
echo '<link rel="stylesheet" href="css/eshop.css">';
echo '<script src="js/eshop.js" defer></script>';

// --- načítanie filtrov z GET ---
$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$category = isset($_GET['category']) ? trim($_GET['category']) : 'all';
$author = isset($_GET['author']) ? trim($_GET['author']) : 'all';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 9;
$offset = ($page - 1) * $perPage;

// --- získanie kategórií a autorov pre filter ---
$cats = $pdo->query("SELECT id, nazov, slug FROM categories ORDER BY nazov")->fetchAll(PDO::FETCH_ASSOC);
$authors = $pdo->query("SELECT id, meno, slug FROM authors ORDER BY meno")->fetchAll(PDO::FETCH_ASSOC);

// --- zostavenie WHERE + parametrov ---
$where = [];
$params = [];

if ($q !== '') {
    $where[] = "(books.nazov LIKE :q OR books.popis LIKE :q)";
    $params[':q'] = '%' . $q . '%';
}
if ($category !== 'all' && ctype_digit($category)) {
    $where[] = "books.category_id = :cat";
    $params[':cat'] = (int)$category;
}
if ($author !== 'all' && ctype_digit($author)) {
    $where[] = "books.author_id = :author";
    $params[':author'] = (int)$author;
}

$whereSQL = '';
if (!empty($where)) {
    $whereSQL = 'WHERE ' . implode(' AND ', $where);
}

// --- spočítanie celkového počtu výsledkov ---
$countSql = "SELECT COUNT(*) FROM books LEFT JOIN authors ON books.author_id = authors.id LEFT JOIN categories ON books.category_id = categories.id $whereSQL";
$stmt = $pdo->prepare($countSql);
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();
$totalPages = (int)ceil($total / $perPage);

// --- načítanie produktov ---
$sql = "SELECT books.*, authors.meno AS author_name, categories.nazov AS category_name
        FROM books
        LEFT JOIN authors ON books.author_id = authors.id
        LEFT JOIN categories ON books.category_id = categories.id
        $whereSQL
        ORDER BY books.created_at DESC
        LIMIT :limit OFFSET :offset";
$stmt = $pdo->prepare($sql);
// bind params
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindValue(':limit', (int)$perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
$stmt->execute();
$books = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- HTML výstup ---
?>

<section class="eshop-hero">
  <div class="eshop-hero-inner">
    <h1>Obchod — PDF knihy</h1>
    <p>Vyber si knihu od slovenských a českých autorov. Podporujeme babyboxy — časť z výnosov putuje na pomoc.</p>
  </div>
</section>

<section class="eshop-controls">
  <form id="eshop-filter-form" method="get" action="eshop.php">
    <input type="search" name="q" placeholder="Hľadať podľa názvu alebo popisu..." value="<?= htmlspecialchars($q) ?>" class="eshop-input">
    <select name="category" class="eshop-select">
      <option value="all">Všetky kategórie</option>
      <?php foreach ($cats as $c): ?>
        <option value="<?= $c['id'] ?>" <?= ($category !== 'all' && (int)$category === (int)$c['id']) ? 'selected' : '' ?>><?= htmlspecialchars($c['nazov']) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="author" class="eshop-select">
      <option value="all">Všetci autori</option>
      <?php foreach ($authors as $a): ?>
        <option value="<?= $a['id'] ?>" <?= ($author !== 'all' && (int)$author === (int)$a['id']) ? 'selected' : '' ?>><?= htmlspecialchars($a['meno']) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="eshop-btn">Filtrovať</button>
  </form>
</section>

<section class="eshop-list">
  <?php if (count($books) === 0): ?>
    <div class="eshop-empty">Nenašli sa žiadne knihy pre zadané kritériá.</div>
  <?php else: ?>
    <div class="eshop-grid">
      <?php foreach ($books as $b): ?>
        <article class="eshop-card">
          <div class="eshop-card-image">
            <img data-src="../books-img/<?= htmlspecialchars($b['obrazok'] ?: 'placeholder.jpg') ?>" alt="<?= htmlspecialchars($b['nazov']) ?>" class="eshop-lazy">
          </div>
          <div class="eshop-card-body">
            <h3 class="eshop-card-title"><?= htmlspecialchars($b['nazov']) ?></h3>
            <div class="eshop-card-meta">
              <span class="eshop-author"><?= htmlspecialchars($b['author_name'] ?: 'Neznámy autor') ?></span> •
              <span class="eshop-category"><?= htmlspecialchars($b['category_name'] ?: 'Neurčené') ?></span>
            </div>
            <p class="eshop-card-desc"><?= nl2br(htmlspecialchars(mb_substr($b['popis'],0,180))) ?>…</p>
            <div class="eshop-card-footer">
              <span class="eshop-price"><?= htmlspecialchars(number_format($b['cena'],2,',','')) ?> €</span>
              <a class="eshop-action" href="../book-detail.php?id=<?= (int)$b['id'] ?>">Detaily / kúpiť</a>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>

    <!-- pagination -->
    <?php if ($totalPages > 1): ?>
      <nav class="eshop-pagination" aria-label="Stránkovanie">
        <?php for ($p=1;$p<=$totalPages;$p++): ?>
          <?php
            $qs = $_GET;
            $qs['page'] = $p;
            $link = 'eshop.php?' . http_build_query($qs);
          ?>
          <a class="eshop-page <?= $p === $page ? 'active' : '' ?>" href="<?= $link ?>"><?= $p ?></a>
        <?php endfor; ?>
      </nav>
    <?php endif; ?>

  <?php endif; ?>
</section>

<?php
// include footer ak existuje
if (file_exists(__DIR__ . '/../partials/footer.php')) {
    include __DIR__ . '/../partials/footer.php';
} else {
    echo '</body></html>';
}

<?php
// partials/top-books.php
// Dynamická sekcia "Top knihy" - načítava TOP podľa predajov (orders + order_items).
// Bezpečné výstupy, pripravené pre epický styling.

// robustné načítanie PDO (skúša viac ciest)
$pdo = null;
$candidates = [
    __DIR__ . '/../db/config/config.php',
    __DIR__ . '/db/config/config.php',
    __DIR__ . '/../../db/config/config.php',
];
foreach ($candidates as $c) {
    if (file_exists($c)) {
        $maybe = require $c;
        if ($maybe instanceof PDO) { $pdo = $maybe; break; }
        if (isset($pdo) && $pdo instanceof PDO) break;
    }
}
if (!($pdo instanceof PDO)) {
    // tichý fallback - zobrazí info, ale neukončí stránku
    echo "<!-- TOP BOOKS: DB pripojenie nie je dostupné -->";
}

// esc helper (unikátne meno)
if (!function_exists('esc_topbooks')) {
    function esc_topbooks($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

// počet zobrazených titulov
$limit = 8;

// dotaz: zober knihy s najvyšším súčtom quantity v order_items pre platné objednávky (paid)
$topBooks = [];
if ($pdo instanceof PDO) {
    try {
        $sql = "
          SELECT b.id, b.nazov, b.slug, b.obrazok, b.cena, b.pdf_file,
                 a.meno AS autor,
                 SUM(oi.quantity) AS sold_qty
          FROM books b
          LEFT JOIN order_items oi ON oi.book_id = b.id
          LEFT JOIN orders o ON o.id = oi.order_id
          LEFT JOIN authors a ON a.id = b.author_id
          WHERE COALESCE(b.is_active,1) = 1
            AND (o.status = 'paid' OR o.status IS NULL)
          GROUP BY b.id
          ORDER BY sold_qty DESC, b.created_at DESC
          LIMIT :lim
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':lim', (int)$limit, PDO::PARAM_INT);
        $stmt->execute();
        $topBooks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // log, nech stránka funguje
        error_log("top-books.php SQL error: " . $e->getMessage());
    }
}
?>
<link rel="stylesheet" href="/css/top-books.css">

<section class="topbooks-section" aria-label="Top knihy">
  <div class="topbooks-paper-wrap">
    <span class="topbooks-grain-overlay" aria-hidden="true"></span>
    <div class="topbooks-header">
      <h2 class="topbooks-title">Top knihy</h2>
      <p class="topbooks-sub">Najpredávanejšie a najobľúbenejšie tituly — vybrané pre teba.</p>
    </div>

    <div class="topbooks-grid" role="list">
      <?php if (empty($topBooks)): ?>
        <div class="topbooks-empty">Zatiaľ nie sú dostupné žiadne top knihy.</div>
      <?php else: foreach ($topBooks as $b): ?>
        <?php
          $img = !empty($b['obrazok']) ? '/books-img/' . ltrim($b['obrazok'], '/') : '/assets/books-imgFB.png';
          $pdf = !empty($b['pdf_file']) ? '/books-pdf/' . ltrim($b['pdf_file'], '/') : '';
        ?>
        <article class="topbook-card" role="listitem" data-book-id="<?php echo esc_topbooks($b['id']); ?>">
          <div class="topbook-cover-wrap">
            <img class="topbook-cover" src="<?php echo esc_topbooks($img); ?>" alt="<?php echo esc_topbooks($b['nazov']); ?>"
                 onerror="this.onerror=null;this.src='/assets/books-imgFB.png'">
            <span class="topbook-frame" aria-hidden="true"></span>
          </div>

          <div class="topbook-meta">
            <h3 class="topbook-name"><?php echo esc_topbooks($b['nazov']); ?></h3>
            <div class="topbook-author"><?php echo esc_topbooks($b['autor'] ?? 'Neznámy autor'); ?></div>
            <div class="topbook-bottom">
              <div class="topbook-price"><?php echo number_format((float)$b['cena'], 2, ',', ' ') . ' €'; ?></div>
              <div class="topbook-actions">
                <a class="btn-topview" href="/book-detail.php?id=<?php echo (int)$b['id']; ?>">Zobraziť</a>
                <?php if (!empty($pdf)): ?>
                  <a class="btn-topdownload" href="<?php echo esc_topbooks($pdf); ?>" download>Stiahnuť</a>
                <?php else: ?>
                  <button class="btn-topdownload disabled" disabled>Stiahnuť</button>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </article>
      <?php endforeach; endif; ?>
    </div>
  </div>
</section>

<script src="/js/top-books.js" defer></script>

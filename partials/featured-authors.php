<?php
// partials/featured-authors.php
// Feature: autori zoradení podľa počtu kníh a priemerného ratingu.

// robustné načítanie PDO
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

if (!function_exists('esc_fauth')) {
    function esc_fauth($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

// načítanie autorov: počet kníh, priemerné hodnotenie
$limit = 6;
$authors = [];
if ($pdo instanceof PDO) {
    try {
        $sql = "
          SELECT a.id, a.meno, a.slug, a.bio, a.foto,
                 COUNT(b.id) AS books_count,
                 ROUND(AVG(r.rating),2) AS avg_rating
          FROM authors a
          LEFT JOIN books b ON b.author_id = a.id AND COALESCE(b.is_active,1)=1
          LEFT JOIN reviews r ON r.book_id = b.id
          GROUP BY a.id
          ORDER BY books_count DESC, avg_rating DESC
          LIMIT :lim
        ";
        $st = $pdo->prepare($sql);
        $st->bindValue(':lim', (int)$limit, PDO::PARAM_INT);
        $st->execute();
        $authors = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log("featured-authors.php SQL error: " . $e->getMessage());
    }
}
?>
<link rel="stylesheet" href="/css/featured-authors.css">

<section class="fauthors-section" aria-label="Autori v centre pozornosti">
  <div class="fauthors-paper-wrap">
    <span class="fauthors-grain-overlay" aria-hidden="true"></span>

    <header class="fauthors-head">
      <h2 class="fauthors-title">Autori v centre pozornosti</h2>
      <p class="fauthors-sub">Predstavujeme autorov, ktorí tvoria príbehy, ktoré si zamiluješ.</p>
    </header>

    <div class="fauthors-grid" role="list">
      <?php if (empty($authors)): ?>
        <div class="fauthors-empty">Žiadni autori na zobrazenie.</div>
      <?php else: foreach ($authors as $a): ?>
        <?php $photo = !empty($a['foto']) ? '/assets/authors/' . ltrim($a['foto'],'/') : '/assets/author-placeholder.png'; ?>
        <article class="fauthor-card" role="listitem" data-author-id="<?php echo (int)$a['id']; ?>">
          <img class="fauthor-photo" src="<?php echo esc_fauth($photo); ?>" alt="<?php echo esc_fauth($a['meno']); ?>"
               onerror="this.onerror=null;this.src='/assets/author-placeholder.png'">
          <h3 class="fauthor-name"><?php echo esc_fauth($a['meno']); ?></h3>
          <div class="fauthor-meta">
            <span class="fauthor-books"><?php echo (int)$a['books_count']; ?> knih</span>
            <span class="fauthor-rating">★ <?php echo $a['avg_rating'] ? esc_fauth($a['avg_rating']) : '—'; ?></span>
          </div>
          <p class="fauthor-bio"><?php echo esc_fauth(mb_strimwidth($a['bio'] ?? '', 0, 220, '...')); ?></p>
          <a class="fauthor-cta" href="/authors.php?author=<?php echo urlencode($a['slug']); ?>">Zobraziť diela</a>
        </article>
      <?php endforeach; endif; ?>
    </div>
  </div>
</section>

<script src="/js/featured-authors.js" defer></script>

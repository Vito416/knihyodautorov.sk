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

//  načítanie autorov: počet kníh, priemerné hodnotenie
$limit = 4;
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

<section id="fauthorsSection" class="style-section">
<div class="paper-wrap">
    <span class="paper-grain-overlay" aria-hidden="true"></span>
    <span class="paper-edge" aria-hidden="true"></span>
  <div class="fauthors-container">
    <div class="fauthors-header">
      <div class="fauthors-head">
      <h2 class="section-title" data-lines="3">Autori v <span>centre</span> pozornosti</h2>
      <p class="section-subtitle">Predstavujeme autorov, ktorí tvoria príbehy, ktoré si zamiluješ.</p>
      </div>
    </div>

    <div class="fauthors-grid" role="list">
      <?php if (empty($authors)): ?>
        <div class="fauthors-empty">Žiadni autori na zobrazenie.</div>
      <?php else: foreach ($authors as $a): ?>
        <?php $photo = !empty($a['foto']) ? '/assets/authors/' . ltrim($a['foto'],'/') : '/assets/author-placeholder.png'; ?>
        <div class="fauthor-card" role="listitem" data-author-id="<?php echo (int)$a['id']; ?>">
          <div class="fauthor-card-inner">
          <div class="fauthor-photo-wrap" style="transform-style:preserve-3d;">
          <img class="fauthor-photo" src="<?php echo esc_fauth($photo); ?>" alt="<?php echo esc_fauth($a['meno']); ?>"
               onerror="this.onerror=null;this.src='/assets/author-placeholder.png'">
          <div class="fauthor-card-frame"></div>
          </div>
          <div class="fauthor-card-info">
          <h3 class="fauthor-name"><?php echo esc_fauth($a['meno']); ?></h3>
          <div class="fauthor-meta">
            <span class="fauthor-books"><?php echo (int)$a['books_count']; ?> knih</span>
            <span class="fauthor-rating">★ <?php echo $a['avg_rating'] ? esc_fauth($a['avg_rating']) : '—'; ?></span>
          </div>
          <p class="fauthor-bio"><?php echo esc_fauth(mb_strimwidth($a['bio'] ?? '', 0, 220, '...')); ?></p>
          </div>
          <div class="fauthor-card-action">
          <button 
            class="fauthor-btn fauthor-cta" 
            type="button"
            aria-label="Zobraziť diela <?php echo esc_fauth($a['meno']); ?>">
            <span class="btn-text">Zobraziť diela</span>
          </button>
          <!-- <a class="fauthor-cta" href="/authors.php?author=<?php echo urlencode($a['slug']); ?>">Zobraziť diela</a> -->
          </div>
        </div>
      </div>
      <?php endforeach; endif; ?>
    </div>
  </div>  
  </div>
</section>

<script src="/js/featured-authors.js" defer></script>

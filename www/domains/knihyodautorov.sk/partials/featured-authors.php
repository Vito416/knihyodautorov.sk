<?php
// partials/featured-authors.php
// Feature: autori zoradení podľa počtu kníh a priemerného ratingu.
if (!($pdo instanceof PDO)) {
  $PROJECT_ROOT = realpath(dirname(__DIR__, 4));
  $configFile = $PROJECT_ROOT . '/secure/config.php';
  require_once $configFile;
  $autoloadPath = $PROJECT_ROOT . '/libs/Database.php';
  require_once $autoloadPath;
  try {
      if (!class_exists('Database')) {
          throw new RuntimeException('Database class not available (autoload error)');
      }
      if (empty($config['db']) || !is_array($config['db'])) {
          throw new RuntimeException('Missing $config[\'db\']');
      }
      Database::init($config['db']);
      $database = Database::getInstance();
      $pdo = $database->getPdo();
  } catch (Throwable $e) {
      $logBootstrapError('Database initialization failed', $e);
  }
  if (!($pdo instanceof PDO)) {
      $logBootstrapError('DB variable is not a PDO instance after init');
  }
}

if (!function_exists('esc_fauth')) {
    function esc_fauth($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

// načítanie autorov (používa uložené agregáty v authors: books_count, ratings_count, rating_sum, avg_rating)
$limit = 4;
$authors = [];

if ($pdo instanceof PDO) {
    try {
        $sql = "
            SELECT
                a.id,
                a.meno,
                a.slug,
                a.bio,
                a.foto,
                COALESCE(a.books_count, 0) AS books_count,
                COALESCE(a.ratings_count, 0) AS ratings_count,
                COALESCE(a.rating_sum, 0) AS rating_sum,
                -- použij uložený avg_rating, inak vypočti fallback z rating_sum/ratings_count
                COALESCE(
                    a.avg_rating,
                    (CASE WHEN a.ratings_count > 0 THEN ROUND(a.rating_sum / a.ratings_count, 2) ELSE NULL END)
                ) AS avg_rating,
                a.last_rating_at
            FROM authors a
            ORDER BY books_count DESC, avg_rating DESC, a.updated_at DESC
            LIMIT :lim
        ";

        $st = $pdo->prepare($sql);
        $st->bindValue(':lim', (int)$limit, PDO::PARAM_INT);
        $st->execute();
        $authors = $st->fetchAll(PDO::FETCH_ASSOC);

        // voliteľné: zabezpečiť, že numerické polia sú typu int/float v PHP (pre UI)
        foreach ($authors as &$au) {
            $au['books_count'] = isset($au['books_count']) ? (int)$au['books_count'] : 0;
            $au['ratings_count'] = isset($au['ratings_count']) ? (int)$au['ratings_count'] : 0;
            $au['rating_sum'] = isset($au['rating_sum']) ? (int)$au['rating_sum'] : 0;
            $au['avg_rating'] = ($au['avg_rating'] !== null) ? (float)$au['avg_rating'] : null;
        }
        unset($au);

    } catch (Throwable $e) {
        error_log("featured-authors.php SQL error: " . $e->getMessage());
        $authors = [];
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
          <div class="fauthors-empty section-subtitle">Žiadni autori na zobrazenie.</div>
        <?php else: foreach ($authors as $a): ?>
          <?php $photo = !empty($a['foto']) ? '/assets/authors/' . ltrim($a['foto'],'/') : '/assets/author-placeholder.png'; ?>
          
          <div class="fauthor-card" role="listitem" data-author-id="<?php echo (int)$a['id']; ?>">
            <div class="fauthor-card-inner">

              <!-- Hlavička kroniky -->
              <div class="fauthor-card-header">
                <h3 class="fauthor-name"><span><?php echo esc_fauth($a['meno']); ?></span></h3>
                <div class="fauthor-debut">
                  <span class="fauthor-books"><?php echo (int)$a['books_count']; ?> knih</span> • <span class="fauthor-rating">★ <?php echo $a['avg_rating'] ? esc_fauth($a['avg_rating']) : '—'; ?></span>
                </div>
              </div>

              <!-- Foto + základní info -->
              <div class="fauthor-main">
                <div class="fauthor-photo-wrap">
                  <img class="fauthor-photo" 
                      src="<?php echo esc_fauth($photo); ?>" 
                      alt="<?php echo esc_fauth($a['meno']); ?>"
                      onerror="this.onerror=null;this.src='/assets/author-placeholder.png'">
                </div>
                <div class="fauthor-seal"></div>
              </div>
              <div class="fauthor-card-info">
                 <p class="fauthor-bio">
                    <?php echo esc_fauth(mb_strimwidth($a['bio'] ?? '', 0, 220, '...')); ?>
                  </p>
              </div>
              <!-- Pečeť + tlačítko -->
              <div class="fauthor-footer">
                <div class="fauthor-card-action">
                  <button class="fauthor-btn fauthor-cta" type="button"
                    aria-label="Zobraziť diela <?php echo esc_fauth($a['meno']); ?>">
                    <span class="btn-text">Přečíst příběh autora</span>
                  </button>
                </div>
              </div>

            </div>
          </div>
        <?php endforeach; endif; ?>
      </div>

  </div>  
  </div>
</section>

<script src="/js/featured-authors.js" defer></script>

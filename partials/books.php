<?php
// partials/books.php
// Sekcia + AJAX endpoint pre náhodné / search výsledky (SK)

// -------- robustné načítanie PDO (skúsi niekoľko relatívnych ciest) ----------
if (!isset($pdo) || !($pdo instanceof PDO)) {
    $candidates = [
        __DIR__ . '/../db/config/config.php',
        __DIR__ . '/db/config/config.php',
        __DIR__ . '/../../db/config/config.php',
    ];
    foreach ($candidates as $cfg) {
        if (!file_exists($cfg)) continue;
        try {
            $maybe = require $cfg;
            if ($maybe instanceof PDO) { $pdo = $maybe; break; }
            if (isset($pdo) && $pdo instanceof PDO) break;
        } catch (Throwable $e) {
            // ignoruj a skúšaj ďalej
        }
    }
}

// helper
function h($s){ return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

// ---------- AJAX endpoint ----------
if ((isset($_GET['ajax']) && $_GET['ajax'] === '1') || (isset($_POST['ajax']) && $_POST['ajax'] === '1')) {
    header('Content-Type: application/json; charset=utf-8');

    $limit = isset($_REQUEST['limit']) ? (int)$_REQUEST['limit'] : 4;
    if ($limit < 1) $limit = 1;
    if ($limit > 50) $limit = 50;

    $q = isset($_REQUEST['q']) ? trim((string)$_REQUEST['q']) : '';

    if (!isset($pdo) || !($pdo instanceof PDO)) {
        echo json_encode(['error' => 'DB connection not available'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        if ($q !== '') {
            $sql = "SELECT b.id, b.nazov, b.popis, b.pdf_file, b.obrazok,
                           a.meno AS autor, a.id AS author_id,
                           c.nazov AS category_nazov, c.slug AS category_slug
                    FROM books b
                    LEFT JOIN authors a ON b.author_id = a.id
                    LEFT JOIN categories c ON b.category_id = c.id
                    WHERE COALESCE(b.is_active,1) = 1
                      AND (
                           COALESCE(b.nazov,'') COLLATE utf8mb4_unicode_ci LIKE :t
                        OR COALESCE(a.meno,'')  COLLATE utf8mb4_unicode_ci LIKE :t
                        OR COALESCE(c.nazov,'') COLLATE utf8mb4_unicode_ci LIKE :t
                      )
                    ORDER BY b.id DESC
                    LIMIT {$limit}";
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':t', '%' . $q . '%', PDO::PARAM_STR);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $sql = "SELECT b.id, b.nazov, b.popis, b.pdf_file, b.obrazok,
                           a.meno AS autor, a.id AS author_id,
                           c.nazov AS category_nazov, c.slug AS category_slug
                    FROM books b
                    LEFT JOIN authors a ON b.author_id = a.id
                    LEFT JOIN categories c ON b.category_id = c.id
                    WHERE COALESCE(b.is_active,1) = 1
                    ORDER BY RAND()
                    LIMIT :lim";
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // uprava ciest (prispôsob si ak treba)
        $baseImg = '/books-img/';
        $basePdf = '/books-pdf/';
        $fallbackImg = '/assets/cover-fallback.png';

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => (int)$r['id'],
                'nazov' => $r['nazov'],
                'popis' => $r['popis'],
                'autor' => $r['autor'],
                'author_id' => isset($r['author_id']) ? (int)$r['author_id'] : null,
                'category_nazov' => $r['category_nazov'],
                'category_slug' => $r['category_slug'],
                'obrazok' => !empty($r['obrazok']) ? $baseImg . ltrim($r['obrazok'], '/') : $fallbackImg,
                'pdf' => !empty($r['pdf_file']) ? $basePdf . ltrim($r['pdf_file'], '/') : ''
            ];
        }

        echo json_encode(['items' => $out], JSON_UNESCAPED_UNICODE);
        exit;

    } catch (Throwable $e) {
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// ---------- Bežné renderovanie sekcie (non-AJAX) ----------
$books = [];
if (isset($pdo) && ($pdo instanceof PDO)) {
    try {
        $sql = "SELECT b.id, b.nazov, b.popis, b.pdf_file, b.obrazok,
                       a.meno AS autor, a.id AS author_id,
                       c.nazov AS category_nazov, c.slug AS category_slug
                FROM books b
                LEFT JOIN authors a ON b.author_id = a.id
                LEFT JOIN categories c ON b.category_id = c.id
                WHERE COALESCE(b.is_active,1) = 1
                ORDER BY RAND()
                LIMIT 4";
        $books = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { $books = []; }
}

$fallbackImg = '/assets/cover-fallback.png';
?>
<link rel="stylesheet" href="/css/books.css">
<script src="/js/books.js" defer></script>

<section id="booksPromo" class="style-section">
  <div class="paper-wrap">
    <span class="paper-grain-overlay" aria-hidden="true"></span>
    <span class="paper-edge" aria-hidden="true"></span>
    <div class="books-container">
      <div class="books-header">
        <div class="books-header-left">
          <h1 class="section-title">Vybrané <span>knihy</span></h1>
          <p class="section-subtitle">
            Nechte se inspirovat naším aktuálním výběrem každý den nový mix žánrů a autorů.
          </p>
          <div class="search-row">
            <input id="unifiedSearch" type="search" placeholder="Zadaj žáner, názov alebo autora" aria-label="Hľadaj">
          </div>
        </div>
      </div>

      <div id="booksGrid" class="books-grid">
        <?php if (empty($books)): ?>
          <div class="no-books">Zatiaľ neboli pridané žiadne knihy.</div>
        <?php else: foreach ($books as $b):
          $cover = !empty($b['obrazok']) ? '/books-img/' . ltrim($b['obrazok'], '/') : $fallbackImg;
          $pdf   = !empty($b['pdf_file']) ? '/books-pdf/' . ltrim($b['pdf_file'], '/') : '';
          $catSlug = !empty($b['category_slug']) ? $b['category_slug'] : 'uncategorized';
          $authorTag = 'author-' . ((int)($b['author_id'] ?? 0));
        ?>
        <div class="book-card"
             tabindex="0"
             data-category="<?= h($catSlug) ?>"
             data-author="<?= h($authorTag) ?>"
             data-title="<?= h(mb_strtolower($b['nazov'])) ?>"
             data-cover="<?= h($cover) ?>">
          <div class="card-inner">
            <div class="cover-wrap">
              <img class="book-cover"
                   src="<?= h($cover) ?>"
                   alt="<?= h($b['nazov']) ?>"
                   onerror="this.onerror=null;this.src='<?= h($fallbackImg) ?>'">
            </div>
          </div>
        </div>
        <?php endforeach; endif; ?>
      </div>
      <div class="book-stand"></div>
    </div>
  </div>
</section>

<!-- modal detail -->
<div class="book-modal" id="bookModal" aria-hidden="true" role="dialog" aria-label="Detail knihy">
  <div class="modal-inner" role="document">
    <button class="modal-close" aria-label="Zavrieť">✕</button>
    <div class="modal-grid">
      <img id="modalCover" src="" alt="Obálka knihy">
      <div class="modal-info">
        <h3 id="modalTitle"></h3>
        <p class="muted" id="modalAuthor"></p>
        <p id="modalDesc"></p>
        <div style="margin-top:12px">
          <a id="modalDownload" class="btn btn-primary" href="#" target="_blank" rel="noopener">Stiahnuť</a>
        </div>
      </div>
    </div>
  </div>
</div>
<?php
// /admin/books.php
require_once __DIR__ . '/bootstrap.php';
require_admin();
require_once __DIR__ . '/inc/helpers.php';

$books = $pdo->query("SELECT b.id, b.nazov, b.slug, b.cena, b.obrazok, b.created_at, a.meno AS autor
                      FROM books b
                      LEFT JOIN authors a ON b.author_id = a.id
                      ORDER BY b.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/header.php';
?>
<section class="adm-section">
  <h1>Knihy</h1>
  <div class="adm-actions">
    <a class="adm-btn" href="/admin/book-edit.php">Pridať knihu</a>
    <a class="adm-btn" href="/admin/export-books.php">Export CSV</a>
  </div>

  <div class="adm-grid">
    <?php foreach ($books as $b): ?>
      <article class="card">
        <div class="card-cover">
          <img src="<?= adm_esc($b['obrazok'] ? '/books-img/'.ltrim($b['obrazok'],'/') : '/assets/books-imgFB.png') ?>" alt="<?= adm_esc($b['nazov']) ?>">
        </div>
        <div class="card-body">
          <h3><?= adm_esc($b['nazov']) ?></h3>
          <p class="muted">Autor: <?= adm_esc($b['autor'] ?? '-') ?></p>
          <div class="card-meta">
            <span><?= adm_esc(adm_money($b['cena'])) ?></span>
            <span class="muted"><?= adm_esc($b['created_at']) ?></span>
          </div>
          <div class="card-actions">
            <a class="adm-btn-small" href="/admin/book-edit.php?id=<?= adm_esc($b['id']) ?>">Upraviť</a>
            <a class="adm-btn-small" href="/admin/book-delete.php?id=<?= adm_esc($b['id']) ?>" onclick="return confirm('Vymazať knihu?');">Vymazať</a>
          </div>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
</section>
<?php include __DIR__ . '/footer.php'; ?>

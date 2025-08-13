<?php
// /admin/books.php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
require __DIR__ . '/bootstrap.php';
require_admin();

function admin_esc($s){ if (function_exists('esc')) return esc($s); return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

// CSRF token
if (!isset($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf_token'];

// file dirs
$imgDir = realpath(__DIR__ . '/../books-img') ?: (__DIR__ . '/../books-img');
$pdfDir = realpath(__DIR__ . '/../books-pdf') ?: (__DIR__ . '/../books-pdf');
@mkdir($imgDir, 0755, true);
@mkdir($pdfDir, 0755, true);

// actions: create/update/delete
$action = $_POST['action'] ?? $_GET['action'] ?? null;
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf'] ?? '')) {
        $error = 'Neplatný CSRF token.';
    } else {
        try {
            if (($action === 'create') || ($action === 'update')) {
                $nazov = trim((string)($_POST['nazov'] ?? ''));
                $popis = trim((string)($_POST['popis'] ?? ''));
                $cena = (float)($_POST['cena'] ?? 0.0);
                $mena = trim((string)($_POST['mena'] ?? 'EUR'));
                $author_id = !empty($_POST['author_id']) ? (int)$_POST['author_id'] : null;
                $category_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
                $slug = preg_replace('/[^a-z0-9\-]+/','', strtolower(str_replace(' ','-',iconv('UTF-8','ASCII//TRANSLIT//IGNORE', $nazov))));
                if ($nazov === '') throw new Exception('Názov knihy je povinný.');

                // handle obrazok
                $obrazok_name = null;
                if (!empty($_FILES['obrazok']['name'])) {
                    $f = $_FILES['obrazok'];
                    if ($f['error'] === UPLOAD_ERR_OK) {
                        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
                        $allowImg = ['jpg','jpeg','png','webp'];
                        if (!in_array($ext, $allowImg, true)) throw new Exception('Nepovolený formát obrázka.');
                        $safe = preg_replace('/[^a-z0-9_\-\.]/i','_', basename($f['name']));
                        $target = $imgDir . '/' . time() . '_' . $safe;
                        if (!move_uploaded_file($f['tmp_name'], $target)) throw new Exception('Nepodarilo sa uložiť obrázok.');
                        // uložíme len relatívny názov (bez cesty)
                        $obrazok_name = basename($target);
                    }
                }

                // handle pdf
                $pdf_name = null;
                if (!empty($_FILES['pdf_file']['name'])) {
                    $f = $_FILES['pdf_file'];
                    if ($f['error'] === UPLOAD_ERR_OK) {
                        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
                        $allowPdf = ['pdf'];
                        if (!in_array($ext, $allowPdf, true)) throw new Exception('Nepovolený formát pdf.');
                        $safe = preg_replace('/[^a-z0-9_\-\.]/i','_', basename($f['name']));
                        $target = $pdfDir . '/' . time() . '_' . $safe;
                        if (!move_uploaded_file($f['tmp_name'], $target)) throw new Exception('Nepodarilo sa uložiť PDF.');
                        $pdf_name = basename($target);
                    }
                }

                if ($action === 'create') {
                    $stmt = $pdo->prepare("INSERT INTO books (nazov, slug, popis, cena, mena, pdf_file, obrazok, author_id, category_id, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
                    $stmt->execute([$nazov, $slug, $popis, number_format($cena,2,'.',''), $mena, $pdf_name, $obrazok_name, $author_id, $category_id]);
                    $message = 'Kniha bola pridaná.';
                } else {
                    $id = (int)($_POST['id'] ?? 0);
                    if ($id <= 0) throw new Exception('Neplatné ID pre update.');
                    // build update, only set new filenames if uploaded
                    $fields = ['nazov'=> $nazov, 'slug'=>$slug, 'popis'=>$popis, 'cena'=>number_format($cena,2,'.',''), 'mena'=>$mena, 'author_id'=>$author_id, 'category_id'=>$category_id];
                    if ($obrazok_name !== null) $fields['obrazok'] = $obrazok_name;
                    if ($pdf_name !== null) $fields['pdf_file'] = $pdf_name;
                    $setParts = [];
                    $params = [];
                    foreach ($fields as $k=>$v) { $setParts[] = "`$k` = ?"; $params[] = $v; }
                    $params[] = $id;
                    $sql = "UPDATE books SET " . implode(', ', $setParts) . " WHERE id = ?";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                    $message = 'Kniha aktualizovaná.';
                }

            } elseif ($action === 'delete') {
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) throw new Exception('Neplatné ID pre zmazanie.');
                // optional: zmaž súbory (nepovinné)
                $row = $pdo->prepare("SELECT obrazok, pdf_file FROM books WHERE id = ? LIMIT 1");
                $row->execute([$id]);
                $r = $row->fetch(PDO::FETCH_ASSOC);
                if ($r) {
                    if (!empty($r['obrazok'])) @unlink($imgDir . '/' . $r['obrazok']);
                    if (!empty($r['pdf_file'])) @unlink($pdfDir . '/' . $r['pdf_file']);
                }
                $pdo->prepare("DELETE FROM books WHERE id = ?")->execute([$id]);
                $message = 'Kniha zmazaná.';
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

// FETCH authors + categories pre formulare
$authors = $pdo->query("SELECT id, meno FROM authors ORDER BY meno ASC")->fetchAll(PDO::FETCH_ASSOC);
$categories = $pdo->query("SELECT id, nazov FROM categories ORDER BY nazov ASC")->fetchAll(PDO::FETCH_ASSOC);

// list knih (paginate)
$page = max(1, (int)($_GET['p'] ?? 1));
$perPage = 12;
$offset = ($page - 1) * $perPage;
$total = (int)$pdo->query("SELECT COUNT(*) FROM books")->fetchColumn();
$books = $pdo->prepare("SELECT b.*, a.meno AS autor, c.nazov AS category FROM books b LEFT JOIN authors a ON b.author_id=a.id LEFT JOIN categories c ON b.category_id=c.id ORDER BY b.created_at DESC LIMIT ? OFFSET ?");
$books->bindValue(1, $perPage, PDO::PARAM_INT);
$books->bindValue(2, $offset, PDO::PARAM_INT);
$books->execute();
$booksList = $books->fetchAll(PDO::FETCH_ASSOC);

// include header
include __DIR__ . '/partials/header.php';
?>

<main class="admin-main container">
  <section class="page-title">
    <h1>Knihy — správa</h1>
    <?php if ($message): ?><div class="notice success"><?php echo admin_esc($message); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="notice error"><?php echo admin_esc($error); ?></div><?php endif; ?>
  </section>

  <section class="book-create">
    <h2>Pridať novú knihu</h2>
    <form method="post" enctype="multipart/form-data" class="card" style="padding:14px; border-radius:10px;">
      <input type="hidden" name="csrf" value="<?php echo admin_esc($csrf); ?>">
      <input type="hidden" name="action" value="create">
      <label>Názov<br><input type="text" name="nazov" required></label><br>
      <label>Autor<br>
        <select name="author_id">
          <option value="">— vybrať —</option>
          <?php foreach ($authors as $a): ?>
            <option value="<?php echo (int)$a['id']; ?>"><?php echo admin_esc($a['meno']); ?></option>
          <?php endforeach; ?>
        </select>
      </label><br>
      <label>Kategória<br>
        <select name="category_id">
          <option value="">— vybrať —</option>
          <?php foreach ($categories as $c): ?>
            <option value="<?php echo (int)$c['id']; ?>"><?php echo admin_esc($c['nazov']); ?></option>
          <?php endforeach; ?>
        </select>
      </label><br>
      <label>Cena (EUR)<br><input type="number" step="0.01" name="cena" value="0.00"></label><br>
      <label>Popis<br><textarea name="popis" rows="4"></textarea></label><br>
      <label>Obálka (jpg/png/webp)<br><input type="file" name="obrazok" accept=".jpg,.jpeg,.png,.webp"></label><br>
      <label>PDF súbor<br><input type="file" name="pdf_file" accept=".pdf"></label><br>
      <button class="btn-primary" type="submit">Pridať knihu</button>
    </form>
  </section>

  <section class="books-list">
    <h2>Existujúce knihy</h2>
    <div class="books-grid">
      <?php foreach ($booksList as $b): ?>
        <article class="book-card card" style="padding:12px;">
          <div style="display:flex; gap:12px;">
            <div style="width:120px;">
              <?php if (!empty($b['obrazok']) && file_exists($imgDir . '/' . $b['obrazok'])): ?>
                <img src="<?php echo admin_esc('/books-img/' . $b['obrazok']); ?>" alt="" style="width:100%; border-radius:8px;">
              <?php else: ?>
                <div style="width:100%;height:160px;background:#efe7d0;border-radius:8px;display:flex;align-items:center;justify-content:center;color:#60401f;">Bez obálky</div>
              <?php endif; ?>
            </div>
            <div style="flex:1;">
              <h3><?php echo admin_esc($b['nazov']); ?></h3>
              <p class="muted"><?php echo admin_esc($b['autor'] ?? ''); ?> — <?php echo admin_esc($b['category'] ?? ''); ?></p>
              <p><?php echo nl2br(admin_esc(mb_strimwidth($b['popis'] ?? '', 0, 220, '...'))); ?></p>
              <div style="margin-top:8px;">
                <a class="btn" href="/admin/book-edit.php?id=<?php echo (int)$b['id']; ?>">Upraviť</a>
                <form method="post" style="display:inline-block" onsubmit="return confirm('Naozaj zmazať knihu?');">
                  <input type="hidden" name="csrf" value="<?php echo admin_esc($csrf); ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?php echo (int)$b['id']; ?>">
                  <button class="btn-ghost" type="submit">Zmazať</button>
                </form>
                <?php if (!empty($b['pdf_file'])): ?>
                  <a class="btn" href="/books-pdf/<?php echo admin_esc($b['pdf_file']); ?>" target="_blank">Stiahnuť PDF</a>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>

    <?php
      // pagination
      $pages = (int)ceil($total / $perPage);
      if ($pages > 1):
    ?>
      <div class="pagination">
        <?php for ($i=1;$i<=$pages;$i++): ?>
          <a class="page <?php echo $i===$page ? 'active' : ''; ?>" href="/admin/books.php?p=<?php echo $i; ?>"><?php echo $i; ?></a>
        <?php endfor; ?>
      </div>
    <?php endif; ?>

  </section>

</main>

<?php include __DIR__ . '/partials/footer.php'; ?>
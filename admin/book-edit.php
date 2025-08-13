<?php
// /admin/book-edit.php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
require __DIR__ . '/bootstrap.php';
require_admin();

function admin_esc($s){ if (function_exists('esc')) return esc($s); return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: /admin/books.php');
    exit;
}

// ensure directories
$imgDir = realpath(__DIR__ . '/../books-img') ?: (__DIR__ . '/../books-img');
$pdfDir = realpath(__DIR__ . '/../books-pdf') ?: (__DIR__ . '/../books-pdf');
@mkdir($imgDir, 0755, true);
@mkdir($pdfDir, 0755, true);

// fetch book
try {
    $stmt = $pdo->prepare("SELECT * FROM books WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $book = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$book) {
        $_SESSION['flash_error'] = 'Kniha nebola nájdená.';
        header('Location: /admin/books.php');
        exit;
    }
} catch (Throwable $e) {
    $_SESSION['flash_error'] = 'DB chyba: ' . $e->getMessage();
    header('Location: /admin/books.php');
    exit;
}

// CSRF
if (!isset($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf_token'];
$message = '';
$error = '';

// handle POST update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf'] ?? '')) {
        $error = 'Neplatný CSRF token.';
    } else {
        try {
            $nazov = trim((string)($_POST['nazov'] ?? $book['nazov']));
            $popis = trim((string)($_POST['popis'] ?? $book['popis']));
            $cena = (float)($_POST['cena'] ?? $book['cena']);
            $mena = trim((string)($_POST['mena'] ?? $book['mena'] ?? 'EUR'));
            $author_id = !empty($_POST['author_id']) ? (int)$_POST['author_id'] : null;
            $category_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
            $slug = preg_replace('/[^a-z0-9\-]+/','', strtolower(str_replace(' ','-',iconv('UTF-8','ASCII//TRANSLIT//IGNORE', $nazov))));
            // upload obrazok
            if (!empty($_FILES['obrazok']['name']) && $_FILES['obrazok']['error'] === UPLOAD_ERR_OK) {
                $f = $_FILES['obrazok'];
                $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
                $allowImg = ['jpg','jpeg','png','webp'];
                if (!in_array($ext, $allowImg, true)) throw new Exception('Nepovolený formát obrázka.');
                $safe = preg_replace('/[^a-z0-9_\-\.]/i','_', basename($f['name']));
                $target = $imgDir . '/' . time() . '_' . $safe;
                if (!move_uploaded_file($f['tmp_name'], $target)) throw new Exception('Nepodarilo sa uložiť obrázok.');
                // remove old
                if (!empty($book['obrazok']) && file_exists($imgDir . '/' . $book['obrazok'])) @unlink($imgDir . '/' . $book['obrazok']);
                $book['obrazok'] = basename($target);
            }
            // upload pdf
            if (!empty($_FILES['pdf_file']['name']) && $_FILES['pdf_file']['error'] === UPLOAD_ERR_OK) {
                $f = $_FILES['pdf_file'];
                $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
                $allowPdf = ['pdf'];
                if (!in_array($ext, $allowPdf, true)) throw new Exception('Nepovolený formát PDF.');
                $safe = preg_replace('/[^a-z0-9_\-\.]/i','_', basename($f['name']));
                $target = $pdfDir . '/' . time() . '_' . $safe;
                if (!move_uploaded_file($f['tmp_name'], $target)) throw new Exception('Nepodarilo sa uložiť PDF.');
                if (!empty($book['pdf_file']) && file_exists($pdfDir . '/' . $book['pdf_file'])) @unlink($pdfDir . '/' . $book['pdf_file']);
                $book['pdf_file'] = basename($target);
            }
            // update DB
            $stmt = $pdo->prepare("UPDATE books SET nazov = ?, slug = ?, popis = ?, cena = ?, mena = ?, pdf_file = ?, obrazok = ?, author_id = ?, category_id = ? WHERE id = ?");
            $stmt->execute([$nazov, $slug, $popis, number_format($cena,2,'.',''), $mena, $book['pdf_file'], $book['obrazok'], $author_id, $category_id, $id]);
            $message = 'Kniha bola aktualizovaná.';
            // refresh book
            $stmt = $pdo->prepare("SELECT * FROM books WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $book = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

// authors / categories
$authors = $pdo->query("SELECT id, meno FROM authors ORDER BY meno ASC")->fetchAll(PDO::FETCH_ASSOC);
$categories = $pdo->query("SELECT id, nazov FROM categories ORDER BY nazov ASC")->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/partials/header.php';
?>
<main class="admin-main container">
  <h1>Upraviť knihu: <?php echo admin_esc($book['nazov']); ?></h1>
  <?php if ($message): ?><div class="notice success"><?php echo admin_esc($message); ?></div><?php endif; ?>
  <?php if ($error): ?><div class="notice error"><?php echo admin_esc($error); ?></div><?php endif; ?>

  <form method="post" enctype="multipart/form-data" class="card">
    <input type="hidden" name="csrf" value="<?php echo admin_esc($csrf); ?>">
    <label>Názov<br><input type="text" name="nazov" value="<?php echo admin_esc($book['nazov']); ?>" required></label>
    <label>Autor<br>
      <select name="author_id">
        <option value="">— vybrať —</option>
        <?php foreach ($authors as $a): ?>
          <option value="<?php echo (int)$a['id']; ?>" <?php if ((int)$a['id'] === (int)$book['author_id']) echo 'selected'; ?>><?php echo admin_esc($a['meno']); ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Kategória<br>
      <select name="category_id">
        <option value="">— vybrať —</option>
        <?php foreach ($categories as $c): ?>
          <option value="<?php echo (int)$c['id']; ?>" <?php if ((int)$c['id'] === (int)$book['category_id']) echo 'selected'; ?>><?php echo admin_esc($c['nazov']); ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Cena (EUR)<br><input type="number" step="0.01" name="cena" value="<?php echo admin_esc($book['cena']); ?>"></label>
    <label>Popis<br><textarea name="popis" rows="6"><?php echo admin_esc($book['popis']); ?></textarea></label>

    <div style="display:flex;gap:12px;align-items:flex-start;">
      <div style="flex:0 0 160px;">
        <?php if (!empty($book['obrazok']) && file_exists($imgDir . '/' . $book['obrazok'])): ?>
          <img src="<?php echo admin_esc('/books-img/' . $book['obrazok']); ?>" alt="" style="width:100%;border-radius:6px;">
        <?php else: ?>
          <div style="width:100%;height:160px;background:#efe7d0;border-radius:6px;display:flex;align-items:center;justify-content:center;color:#60401f;">Bez obálky</div>
        <?php endif; ?>
        <label style="margin-top:8px">Nová obálka<br><input type="file" name="obrazok" accept=".jpg,.jpeg,.png,.webp"></label>
      </div>

      <div style="flex:1">
        <p>Aktuálne PDF: <?php echo admin_esc($book['pdf_file'] ?? '—'); ?></p>
        <label>Nový PDF súbor<br><input type="file" name="pdf_file" accept=".pdf"></label>
      </div>
    </div>

    <div style="margin-top:12px;"><button class="btn-primary" type="submit">Uložiť zmeny</button> <a class="btn-ghost" href="/admin/books.php">Späť na zoznam</a></div>
  </form>
</main>
<?php include __DIR__ . '/partials/footer.php'; ?>
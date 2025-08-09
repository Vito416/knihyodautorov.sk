<?php
// admin/products.php
session_start();
require_once __DIR__ . '/../db/config/config.php';

// pre demo: jednoduché admin "autorizovanie" cez session (ak nie je, redirect na root)
if (!isset($_SESSION['admin_id'])) {
    // môžeme povoliť: ak nie je admin, redirect na login (nie je pripravený)
    header('Location: /auth/login.php?next=/admin/products.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    $nazov = trim($_POST['nazov'] ?? '');
    $popis = trim($_POST['popis'] ?? '');
    $cena = (float)($_POST['cena'] ?? 0);
    $pdf = trim($_POST['pdf_file'] ?? '');
    $img = trim($_POST['obrazok'] ?? '');
    $author_id = (int)($_POST['author_id'] ?? 0);
    $category_id = (int)($_POST['category_id'] ?? 0);
    if ($nazov) {
        $slug = preg_replace('/[^a-z0-9]+/','-',strtolower(iconv('UTF-8','ASCII//TRANSLIT',$nazov)));
        $stmt = $pdo->prepare("INSERT INTO books (nazov, slug, popis, cena, pdf_file, obrazok, author_id, category_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$nazov, $slug, $popis, $cena, $pdf, $img, $author_id ?: null, $category_id ?: null]);
        header('Location: products.php');
        exit;
    }
}

// načítaj produkty/autoři/kategórie
$products = $pdo->query("SELECT b.*, a.meno AS author_name, c.nazov AS category_name FROM books b LEFT JOIN authors a ON b.author_id=a.id LEFT JOIN categories c ON b.category_id=c.id ORDER BY b.created_at DESC")->fetchAll();
$authors = $pdo->query("SELECT id, meno FROM authors ORDER BY meno")->fetchAll();
$cats = $pdo->query("SELECT id, nazov FROM categories ORDER BY nazov")->fetchAll();

if (file_exists(__DIR__ . '/admin/css/admin-products.css')) echo '<link rel="stylesheet" href="/admin/css/admin-products.css">';
if (file_exists(__DIR__ . '/admin/js/admin-products.js')) echo '<script src="/admin/js/admin-products.js" defer></script>';
if (file_exists(__DIR__ . '/partials/header.php')) include __DIR__ . '/partials/header.php';
?>

<section class="admin-products">
  <div class="admin-inner" style="max-width:1100px;margin:30px auto">
    <h1>Admin — Produkty</h1>

    <h2>Pridať produkt</h2>
    <form method="post" class="admin-form">
      <input type="hidden" name="action" value="create">
      <label>Názov</label><input name="nazov" required>
      <label>Popis</label><textarea name="popis"></textarea>
      <label>Cena</label><input name="cena" type="number" step="0.01" value="0.00">
      <label>PDF file (názov súboru)</label><input name="pdf_file" placeholder="nazov.pdf">
      <label>Obrázok (názov súboru)</label><input name="obrazok" placeholder="book1.jpg">
      <label>Autor</label>
      <select name="author_id"><option value="">-- bez autora --</option><?php foreach($authors as $a) echo "<option value='{$a['id']}'>".htmlspecialchars($a['meno'])."</option>"; ?></select>
      <label>Kategória</label>
      <select name="category_id"><option value="">-- bez kategórie --</option><?php foreach($cats as $c) echo "<option value='{$c['id']}'>".htmlspecialchars($c['nazov'])."</option>"; ?></select>
      <button type="submit">Pridať</button>
    </form>

    <h2>Zoznam produktov</h2>
    <table class="admin-table">
      <thead><tr><th>ID</th><th>Názov</th><th>Autor</th><th>Kategória</th><th>Cena</th></tr></thead>
      <tbody>
      <?php foreach ($products as $p): ?>
        <tr>
          <td><?= (int)$p['id'] ?></td>
          <td><?= htmlspecialchars($p['nazov']) ?></td>
          <td><?= htmlspecialchars($p['author_name']) ?></td>
          <td><?= htmlspecialchars($p['category_name']) ?></td>
          <td><?= htmlspecialchars(number_format($p['cena'],2,',','')) ?> €</td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<?php if (file_exists(__DIR__ . '/partials/footer.php')) include __DIR__ . '/partials/footer.php'; ?>

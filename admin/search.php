<?php
// /admin/search.php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/partials/notifications.php';

require_admin();

$q = trim((string)($_GET['q'] ?? ''));
if ($q === '') {
    // zobraz formulár s hintom
    include __DIR__ . '/partials/header.php';
    ?>
    <section style="padding:28px;">
      <h1>Vyhľadávanie v administrácii</h1>
      <form action="/admin/search.php" method="get">
        <input name="q" type="search" value="" placeholder="Hľadaj: kniha, autor, email, #objednávky" style="padding:10px 12px;width:380px;border-radius:8px;border:1px solid #e6dfc8">
        <button type="submit" style="padding:10px 14px;background:#cf9b3a;border:none;border-radius:8px;font-weight:800">Hľadaj</button>
      </form>
    </section>
    <?php
    include __DIR__ . '/partials/footer.php';
    exit;
}

// prevent wildcard abuse - escape for LIKE
$like = '%' . str_replace(['%','_'], ['\\%','\\_'], $q) . '%';
$rows = [
    'books' => [],
    'authors' => [],
    'users' => [],
    'orders' => []
];

// books
try {
    $stmt = $pdo->prepare("SELECT id, nazov, slug FROM books WHERE nazov LIKE ? LIMIT 20");
    $stmt->execute([$like]);
    $rows['books'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e){}

// authors
try {
    $stmt = $pdo->prepare("SELECT id, meno, slug FROM authors WHERE meno LIKE ? LIMIT 20");
    $stmt->execute([$like]);
    $rows['authors'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e){}

// users (email or meno)
try {
    $stmt = $pdo->prepare("SELECT id, meno, email FROM users WHERE email LIKE ? OR meno LIKE ? LIMIT 30");
    $stmt->execute([$like, $like]);
    $rows['users'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e){}

// orders by id or total_price (search numeric)
try {
    if (is_numeric($q)) {
        $stmt = $pdo->prepare("SELECT id, user_id, total_price, status, created_at FROM orders WHERE id = ? LIMIT 20");
        $stmt->execute([(int)$q]);
        $rows['orders'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // search by approximate total_price formatted
        $stmt = $pdo->prepare("SELECT id, user_id, total_price, status, created_at FROM orders WHERE total_price LIKE ? LIMIT 20");
        $stmt->execute([$like]);
        $rows['orders'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e){}

// Render fragment
include __DIR__ . '/partials/header.php';
?>
<section style="padding:22px;">
  <h1>Výsledky hľadania pre: <?php echo admin_esc($q); ?></h1>

  <h3>Knihy (<?php echo count($rows['books']); ?>)</h3>
  <?php if ($rows['books']): ?>
    <ul>
      <?php foreach ($rows['books'] as $b): ?>
        <li><a href="/admin/book-edit.php?id=<?php echo (int)$b['id']; ?>"><?php echo admin_esc($b['nazov']); ?></a></li>
      <?php endforeach; ?>
    </ul>
  <?php else: ?><p class="muted">Žiadne výsledky</p><?php endif; ?>

  <h3>Autori (<?php echo count($rows['authors']); ?>)</h3>
  <?php if ($rows['authors']): ?>
    <ul><?php foreach ($rows['authors'] as $a): ?><li><a href="/admin/author-edit.php?id=<?php echo (int)$a['id']; ?>"><?php echo admin_esc($a['meno']); ?></a></li><?php endforeach; ?></ul>
  <?php else: ?><p class="muted">Žiadne výsledky</p><?php endif; ?>

  <h3>Užívatelia (<?php echo count($rows['users']); ?>)</h3>
  <?php if ($rows['users']): ?>
    <ul><?php foreach ($rows['users'] as $u): ?><li><a href="/admin/user-edit.php?id=<?php echo (int)$u['id']; ?>"><?php echo admin_esc($u['meno']); ?> — <?php echo admin_esc($u['email']); ?></a></li><?php endforeach; ?></ul>
  <?php else: ?><p class="muted">Žiadne výsledky</p><?php endif; ?>

  <h3>Objednávky (<?php echo count($rows['orders']); ?>)</h3>
  <?php if ($rows['orders']): ?>
    <table style="width:100%;border-collapse:collapse">
      <thead><tr><th>ID</th><th>Užívateľ</th><th>Celkom</th><th>Stav</th><th>Dátum</th></tr></thead>
      <tbody>
      <?php foreach ($rows['orders'] as $o): ?>
        <?php
          $uName = '';
          try { $uName = $pdo->prepare("SELECT meno FROM users WHERE id=? LIMIT 1")->execute([(int)$o['user_id']]) ? $pdo->query("SELECT meno FROM users WHERE id=".(int)$o['user_id'])->fetchColumn() : ''; } catch(Throwable $e){}
        ?>
        <tr>
          <td><?php echo (int)$o['id']; ?></td>
          <td><?php echo admin_esc($uName); ?></td>
          <td><?php echo admin_esc(number_format((float)$o['total_price'],2,',','.')); ?> €</td>
          <td><?php echo admin_esc($o['status']); ?></td>
          <td><?php echo admin_esc($o['created_at']); ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php else: ?><p class="muted">Žiadne výsledky</p><?php endif; ?>

  <p><a href="/admin/index.php">Späť na prehľad</a></p>
</section>
<?php include __DIR__ . '/partials/footer.php'; ?>
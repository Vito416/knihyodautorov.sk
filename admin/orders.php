<?php
// /admin/orders.php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
require __DIR__ . '/bootstrap.php';
require_admin();

function admin_esc($s){ if (function_exists('esc')) return esc($s); return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

if (!isset($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf_token'];

$page = max(1, (int)($_GET['p'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

// total
$total = (int)$pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();

// orders list with user
$stmt = $pdo->prepare("SELECT o.*, u.meno AS user_name, u.email AS user_email FROM orders o LEFT JOIN users u ON o.user_id = u.id ORDER BY o.created_at DESC LIMIT ? OFFSET ?");
$stmt->bindValue(1, $perPage, PDO::PARAM_INT);
$stmt->bindValue(2, $offset, PDO::PARAM_INT);
$stmt->execute();
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/partials/header.php';
?>
<main class="admin-main container">
  <h1>Objednávky</h1>
  <div style="margin-bottom:12px;">
    <a class="btn" href="/admin/exports.php?type=orders&format=csv">Export CSV</a>
    <a class="btn" href="/admin/exports.php?type=orders&format=xlsx">Export XLSX</a>
  </div>

  <?php foreach ($orders as $o): ?>
    <article class="card order-row" style="margin-bottom:14px;padding:12px;">
      <div style="display:flex;justify-content:space-between;gap:12px;">
        <div>
          <strong>#<?php echo admin_esc($o['id']); ?></strong>
          &nbsp;|&nbsp;<span class="muted"><?php echo admin_esc($o['created_at'] ?? ''); ?></span>
          <div><strong><?php echo admin_esc($o['user_name'] ?? ''); ?></strong> — <?php echo admin_esc($o['user_email'] ?? ''); ?></div>
          <div class="muted">Stav: <?php echo admin_esc($o['status']); ?> / Platba: <?php echo admin_esc($o['payment_method'] ?? '-'); ?></div>
        </div>
        <div style="text-align:right;">
          <div style="font-weight:800"><?php echo admin_esc(number_format((float)$o['total_price'],2,'.','')) . ' ' . admin_esc($o['currency']); ?></div>
          <div style="margin-top:8px;">
            <form method="post" action="/admin/actions/mark-order-paid.php" style="display:inline-block">
              <input type="hidden" name="csrf" value="<?php echo admin_esc($csrf); ?>">
              <input type="hidden" name="order_id" value="<?php echo (int)$o['id']; ?>">
              <button class="btn" type="submit">Označiť ako zaplatené</button>
            </form>
            <form method="post" action="/admin/actions/generate-invoice.php" style="display:inline-block">
              <input type="hidden" name="csrf" value="<?php echo admin_esc($csrf); ?>">
              <input type="hidden" name="order_id" value="<?php echo (int)$o['id']; ?>">
              <button class="btn-primary" type="submit">Vytvoriť faktúru</button>
            </form>
          </div>
        </div>
      </div>

      <?php
        // fetch items for this order
        $items = $pdo->prepare("SELECT oi.*, b.nazov AS book_name FROM order_items oi LEFT JOIN books b ON oi.book_id=b.id WHERE oi.order_id = ?");
        $items->execute([(int)$o['id']]);
        $it = $items->fetchAll(PDO::FETCH_ASSOC);
      ?>
      <div style="margin-top:10px;">
        <strong>Položky:</strong>
        <ul>
          <?php foreach ($it as $row): ?>
            <li><?php echo admin_esc($row['book_name'] ?? ('ID:' . (int)$row['book_id'])) . ' × ' . (int)$row['quantity'] . ' — ' . admin_esc(number_format((float)$row['unit_price'],2,'.','')) . ' ' . admin_esc($o['currency']); ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    </article>
  <?php endforeach; ?>

  <?php
    $pages = (int)ceil($total / $perPage);
    if ($pages > 1):
  ?>
    <div class="pagination">
      <?php for ($i=1;$i<=$pages;$i++): ?>
        <a class="page <?php echo $i===$page ? 'active' : ''; ?>" href="/admin/orders.php?p=<?php echo $i; ?>"><?php echo $i; ?></a>
      <?php endfor; ?>
    </div>
  <?php endif; ?>

</main>
<?php include __DIR__ . '/partials/footer.php'; ?>
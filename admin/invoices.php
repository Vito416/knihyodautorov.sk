<?php
// /admin/invoices.php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
require __DIR__ . '/bootstrap.php';
require_admin();

function admin_esc($s){ if (function_exists('esc')) return esc($s); return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

// ensure invoices table exists (idempotent)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS invoices (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      order_id INT UNSIGNED NOT NULL,
      invoice_number VARCHAR(100) NOT NULL,
      pdf_file VARCHAR(255) DEFAULT NULL,
      total DECIMAL(10,2) DEFAULT 0.00,
      currency CHAR(3) DEFAULT 'EUR',
      tax_rate DECIMAL(5,2) DEFAULT 0.00,
      variable_symbol VARCHAR(50) DEFAULT NULL,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (Throwable $e) {
    // ignore creation errors but show below if necessary
}

$page = max(1, (int)($_GET['p'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

// fetch invoices with order info
$stmt = $pdo->prepare("SELECT inv.*, o.user_id, u.meno AS user_name, u.email AS user_email FROM invoices inv LEFT JOIN orders o ON inv.order_id=o.id LEFT JOIN users u ON o.user_id=u.id ORDER BY inv.created_at DESC LIMIT ? OFFSET ?");
$stmt->bindValue(1, $perPage, PDO::PARAM_INT);
$stmt->bindValue(2, $offset, PDO::PARAM_INT);
$stmt->execute();
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total = (int)$pdo->query("SELECT COUNT(*) FROM invoices")->fetchColumn();

include __DIR__ . '/partials/header.php';
?>
<main class="admin-main container">
  <h1>Faktúry</h1>
  <div style="margin-bottom:12px;">
    <a class="btn" href="/admin/exports.php?type=invoices&format=csv">Export CSV</a>
  </div>

  <table class="table">
    <thead><tr><th>#</th><th>Faktúra</th><th>Objednávka</th><th>Klient</th><th>Čiastka</th><th>Vytvorená</th><th>Akcie</th></tr></thead>
    <tbody>
      <?php foreach ($invoices as $inv): ?>
        <tr>
          <td><?php echo admin_esc($inv['id']); ?></td>
          <td><?php echo admin_esc($inv['invoice_number']); ?></td>
          <td>#<?php echo admin_esc($inv['order_id']); ?></td>
          <td><?php echo admin_esc($inv['user_name'] ?? ''); ?> <?php if (!empty($inv['user_email'])) echo '<br><small class="muted">'.admin_esc($inv['user_email']).'</small>'; ?></td>
          <td><?php echo admin_esc(number_format((float)$inv['total'],2,'.','')) . ' ' . admin_esc($inv['currency']); ?></td>
          <td><?php echo admin_esc($inv['created_at']); ?></td>
          <td>
            <?php if (!empty($inv['pdf_file']) && file_exists(__DIR__ . '/../eshop/invoices/' . $inv['pdf_file'])): ?>
              <a class="btn" href="/admin/actions/download-invoice.php?id=<?php echo (int)$inv['id']; ?>">Stiahnuť</a>
              <a class="btn-ghost" href="/eshop/invoices/<?php echo admin_esc($inv['pdf_file']); ?>" target="_blank">Otvoriť</a>
            <?php else: ?>
              <em>PDF chýba</em>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <?php
    $pages = (int)ceil($total / $perPage);
    if ($pages > 1):
  ?>
    <div class="pagination">
      <?php for ($i=1;$i<=$pages;$i++): ?>
        <a class="page <?php echo $i===$page ? 'active' : ''; ?>" href="/admin/invoices.php?p=<?php echo $i; ?>"><?php echo $i; ?></a>
      <?php endfor; ?>
    </div>
  <?php endif; ?>

</main>
<?php include __DIR__ . '/partials/footer.php'; ?>
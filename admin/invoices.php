<?php
// /admin/invoices.php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
if (!admin_is_logged()) { header('Location: login.php'); exit; }

$perPage = 20;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$filterOrder = trim((string)($_GET['order_id'] ?? ''));
$filterDateFrom = trim((string)($_GET['from'] ?? ''));
$filterDateTo = trim((string)($_GET['to'] ?? ''));

// build where
$where = [];
$params = [];
if ($filterOrder !== '') { $where[] = "order_id = :order_id"; $params[':order_id'] = (int)$filterOrder; }
if ($filterDateFrom !== '') { $where[] = "created_at >= :from"; $params[':from'] = $filterDateFrom . ' 00:00:00'; }
if ($filterDateTo !== '') { $where[] = "created_at <= :to"; $params[':to'] = $filterDateTo . ' 23:59:59'; }
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// count
$total = (int)$pdo->query("SELECT COUNT(*) FROM invoices $whereSql", $params)->fetchColumn(); // PDO->query doesn't accept params; we prepare
$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM invoices $whereSql");
$stmtCount->execute($params);
$total = (int)$stmtCount->fetchColumn();

// fetch page
$stmt = $pdo->prepare("SELECT id, invoice_number, order_id, total_amount, currency, created_at, pdf_file FROM invoices $whereSql ORDER BY created_at DESC LIMIT :lim OFFSET :off");
foreach ($params as $k=>$v) { /* leave to execute bind */ }
$stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':off', $offset, PDO::PARAM_INT);
foreach ($params as $k=>$v) {
    // bind other params (string or int)
    $stmt->bindValue($k, $v);
}
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// pagination helper
$totalPages = (int)max(1, ceil($total / $perPage));

?>
<!doctype html>
<html lang="sk">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Admin — Faktúry</title>
  <link rel="stylesheet" href="/admin/css/admin.css">
  <link rel="stylesheet" href="/admin/css/invoices.css">
  <script src="/admin/js/admin.js" defer></script>
  <script src="/admin/js/invoices.js" defer></script>
</head>
<body>
  <main class="admin-shell">
    <header class="admin-top">
      <h1>Faktúry</h1>
      <div class="actions">
        <a class="btn" href="/admin/invoice-generate.php">Vygenerovať faktúru (ručné)</a>
        <a class="btn ghost" href="/admin/invoices.php">Obnoviť</a>
      </div>
    </header>

    <section class="panel">
      <form method="get" class="form-inline">
        <label>Order ID <input type="text" name="order_id" value="<?php echo htmlspecialchars($filterOrder); ?>"></label>
        <label>Od <input type="date" name="from" value="<?php echo htmlspecialchars($filterDateFrom); ?>"></label>
        <label>Do <input type="date" name="to" value="<?php echo htmlspecialchars($filterDateTo); ?>"></label>
        <button class="btn" type="submit">Filtrovať</button>
        <a class="btn ghost" href="/admin/invoices.php">Vymazať filtre</a>
      </form>
    </section>

    <section class="panel">
      <?php if (empty($rows)): ?>
        <p class="muted">Žiadne faktúry.</p>
      <?php else: ?>
        <table class="table">
          <thead>
            <tr>
              <th>#</th><th>Číslo faktúry</th><th>Objednávka</th><th>Suma</th><th>Dátum</th><th>PDF</th><th>Akcie</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($rows as $r): ?>
              <tr>
                <td><?php echo (int)$r['id']; ?></td>
                <td><?php echo htmlspecialchars($r['invoice_number']); ?></td>
                <td><?php echo (int)$r['order_id']; ?></td>
                <td><?php echo number_format((float)$r['total_amount'],2,',','.').' '.htmlspecialchars($r['currency']); ?></td>
                <td><?php echo htmlspecialchars($r['created_at']); ?></td>
                <td>
                  <?php if (!empty($r['pdf_file'])): ?>
                    <a class="btn small" href="/admin/invoice-download.php?id=<?php echo (int)$r['id']; ?>">Stiahnuť</a>
                  <?php else: ?>
                    <span class="muted">PDF chýba</span>
                  <?php endif; ?>
                </td>
                <td>
                  <a class="btn small ghost" href="/admin/invoice-view.php?id=<?php echo (int)$r['id']; ?>" target="_blank">Zobraziť</a>
                  <a class="btn small" href="/admin/invoice-generate.php?order_id=<?php echo (int)$r['order_id']; ?>&regen=1">Obnoviť PDF</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <div class="pagination">
          <?php for ($p=1;$p<=$totalPages;$p++): ?>
            <a class="pager <?php if ($p===$page) echo 'active'; ?>" href="?page=<?php echo $p; ?><?php echo $filterOrder?('&order_id=' . urlencode($filterOrder)) : ''; ?>">&nbsp;<?php echo $p; ?>&nbsp;</a>
          <?php endfor; ?>
        </div>
      <?php endif; ?>
    </section>
  </main>
</body>
</html>
<?php
// /admin/audit-log.php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
require __DIR__ . '/bootstrap.php';
require_admin();

function admin_esc($s){ if (function_exists('esc')) return esc($s); return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

// ensure table
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS audit_log (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT DEFAULT NULL, action VARCHAR(255), meta TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

$stmt = $pdo->query("SELECT * FROM audit_log ORDER BY created_at DESC LIMIT 200");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/partials/header.php';
?>
<main class="admin-main container">
  <h1>Audit — posledné akcie</h1>
  <table class="table">
    <thead><tr><th>ID</th><th>Užívateľ</th><th>Akcia</th><th>Meta</th><th>Čas</th></tr></thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?php echo admin_esc($r['id']); ?></td>
          <td><?php echo admin_esc($r['user_id']); ?></td>
          <td><?php echo admin_esc($r['action']); ?></td>
          <td><pre style="white-space:pre-wrap"><?php echo admin_esc($r['meta']); ?></pre></td>
          <td><?php echo admin_esc($r['created_at']); ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</main>
<?php include __DIR__ . '/partials/footer.php'; ?>
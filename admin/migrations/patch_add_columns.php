<?php
// /admin/migrations/patch_add_columns.php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
require __DIR__ . '../../../db/config/config.php';
if (!($pdo instanceof PDO)) die('Chýba PDO');

$actions = [];

try {
    // admin_users: must_change_password
    $pdo->exec("ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS must_change_password TINYINT(1) NOT NULL DEFAULT 0");
    $actions[] = 'admin_users.must_change_password OK';
} catch (Throwable $e) { $actions[] = 'admin_users.must_change_password ERR: '.$e->getMessage(); }

try {
    // users: ensure reset_token, download_token exist (initdb probably added but safe)
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS reset_token VARCHAR(255) DEFAULT NULL");
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS download_token VARCHAR(255) DEFAULT NULL");
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS newsletter TINYINT(1) DEFAULT 0");
    $actions[] = 'users.* columns OK';
} catch (Throwable $e) { $actions[] = 'users.* ERR: '.$e->getMessage(); }

try {
    // invoices table (if not present)
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
    $actions[] = 'invoices table OK';
} catch (Throwable $e) { $actions[] = 'invoices ERR: '.$e->getMessage(); }

echo "<h1>Migration výsledok</h1><ul>";
foreach ($actions as $a) echo "<li>" . htmlspecialchars($a) . "</li>";
echo "</ul><p>Ak sú chyby, skopíruj si tu správy a pošli mi ich.</p>";
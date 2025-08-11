<?php
// /admin/invoice-download.php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
if (!admin_is_logged()) { header('Location: login.php'); exit; }

$invoiceId = isset($_GET['invoice_id']) ? (int)$_GET['invoice_id'] : 0;
$invNumber = isset($_GET['inv']) ? $_GET['inv'] : null;

if ($invoiceId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ? LIMIT 1");
    $stmt->execute([$invoiceId]);
    $inv = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$inv) { echo "Faktúra nenájdená."; exit; }
    $file = __DIR__ . '/../eshop/invoices/' . $inv['file_path'];
    if (!file_exists($file)) { echo "Súbor nie je k dispozícii."; exit; }
    // serve HTML content inline (safe)
    echo file_get_contents($file);
    exit;
} elseif ($invNumber) {
    // search by invoice_number
    $stmt = $pdo->prepare("SELECT * FROM invoices WHERE invoice_number = ? LIMIT 1");
    $stmt->execute([$invNumber]);
    $inv = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($inv) {
        $file = __DIR__ . '/../eshop/invoices/' . $inv['file_path'];
        if (file_exists($file)) {
            echo file_get_contents($file);
            exit;
        }
    }
    echo "Faktúra nenájdená.";
    exit;
} else {
    echo "Chýba ID faktúry.";
    exit;
}
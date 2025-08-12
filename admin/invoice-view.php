<?php
// /admin/invoice-view.php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
if (!admin_is_logged()) { header('Location: login.php'); exit; }

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { die('Neplatná faktúra'); }

// fetch invoice
$stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$inv = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$inv) { die('Faktúra neexistuje'); }

// load order/items/user/settings
$order_id = (int)$inv['order_id'];
$s = $pdo->prepare("SELECT * FROM orders WHERE id = ? LIMIT 1");
$s->execute([$order_id]);
$order = $s->fetch(PDO::FETCH_ASSOC);
$si = $pdo->prepare("SELECT oi.*, b.nazov, b.pdf_file FROM order_items oi JOIN books b ON b.id = oi.book_id WHERE oi.order_id = ?");
$si->execute([$order_id]);
$items = $si->fetchAll(PDO::FETCH_ASSOC);
$su = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
$su->execute([$order['user_id']]);
$user = $su->fetch(PDO::FETCH_ASSOC);
$settings = [];
$rs = $pdo->query("SELECT k,v FROM settings")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rs as $_r) $settings[$_r['k']] = $_r['v'] ?? '';

$total = (float)$inv['total_amount'];
$currency = $inv['currency'] ?? ($settings['currency'] ?? 'EUR');
$invoiceNumber = $inv['invoice_number'];
$invoiceId = (int)$inv['id'];

include __DIR__ . '/invoice-template-html.php';
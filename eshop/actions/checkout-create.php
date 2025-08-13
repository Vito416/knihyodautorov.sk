<?php
// /eshop/actions/checkout-create.php
declare(strict_types=1);
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

/*
 Robustní checkout-create handler:
 - podporuje host objednávky i prihláseného užívateľa
 - kontrola CSRF (pokud máte funkce eshop_csrf_verify/eshop_csrf_token používá je, jinak lehký fallback)
 - validace košíku v $_SESSION['cart'] ve formátu: [bookId => qty] nebo [[book_id, qty], ...]
 - vytvoří záznam v orders + order_items + invoices
 - vytvoří jednoduchý invoice HTML soubor do /eshop/invoices/invoice-{id}.html
 - vygeneruje QR obrazek pomocí phpqrcode (pokud existuje)
 - vyprázdní košík (unset $_SESSION['cart']) a redirect na thank-you.php?order={id}
 - loguje chyby do /eshop/tmp/checkout.log
*/

$root = dirname(__DIR__); // /eshop
require_once $root . '/_init.php'; // očekává $pdo a helpery (current_user, eshop_settings, eshop_asset, eshop_esc, eshop_csrf_verify - pokud ne, fallback použije vlastní)

/* -- helpers ---------------------------------------------------------------- */
function outLog($msg) {
    $d = __DIR__ . '/tmp';
    if (!is_dir($d)) @mkdir($d, 0755, true);
    @file_put_contents($d . '/checkout.log', date('c') . " " . $msg . PHP_EOL, FILE_APPEND);
}
function safe($v) { return htmlspecialchars((string)$v, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }

/* CSRF: preferované funkce z init, fallback: check method + referer optional */
$hasCsrfFunc = function_exists('eshop_verify_csrf') || function_exists('eshop_csrf_verify');
$csrfOk = true;
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    outLog("Bad method");
    echo "Invalid request method"; exit;
}
$posted = $_POST;
if ($hasCsrfFunc) {
    $tok = $posted['csrf'] ?? '';
    if (function_exists('eshop_verify_csrf')) $csrfOk = eshop_verify_csrf($tok);
    elseif (function_exists('eshop_csrf_verify')) $csrfOk = eshop_csrf_verify($tok);
} else {
    // fallback: require presence of a token in session if it was set by the site earlier
    if (!empty($_SESSION['csrf_token']) && !empty($posted['csrf']) && hash_equals($_SESSION['csrf_token'], (string)$posted['csrf'])) {
        $csrfOk = true;
    } else {
        // lehký fallback: nepovolit bez tokenu
        $csrfOk = false;
    }
}
if (!$csrfOk) {
    outLog("CSRF failed");
    http_response_code(403);
    $_SESSION['eshop_error'] = "Chyba bezpečnosti (CSRF). Ak problém pretrváva, skúste obnoviť stránku.";
    header('Location: /eshop/cart.php');
    exit;
}

/* Košík */
$cart = $_SESSION['cart'] ?? null;
if (!$cart || (is_array($cart) && count($cart) === 0)) {
    $_SESSION['eshop_error'] = "Váš košík je prázdny.";
    header('Location: /eshop/cart.php');
    exit;
}

/* Normalize cart: accept [id=>qty] or [[book_id, qty],...] */
$normalized = [];
if (array_values($cart) === $cart) {
    // numeric-indexed
    foreach ($cart as $row) {
        if (is_array($row)) {
            $id = (int)($row[0] ?? 0); $q = max(1, (int)($row[1] ?? 1));
            if ($id > 0) $normalized[$id] = ($normalized[$id] ?? 0) + $q;
        }
    }
} else {
    foreach ($cart as $k=>$v) {
        $id = (int)$k; $q = max(1, (int)$v);
        if ($id > 0) $normalized[$id] = ($normalized[$id] ?? 0) + $q;
    }
}
if (empty($normalized)) {
    $_SESSION['eshop_error'] = "Chybný obsah košíka.";
    header('Location: /eshop/cart.php'); exit;
}

/* Customer info (guest allowed) */
$customer_email = trim((string)($posted['email'] ?? ''));
$customer_name  = trim((string)($posted['name'] ?? ''));
if ($customer_email === '') {
    $_SESSION['eshop_error'] = "Zadajte e-mail pre potvrdenie objednávky.";
    header('Location: /eshop/cart.php'); exit;
}

/* If logged in, attach user */
$user = null;
if (function_exists('current_user')) $user = current_user($pdo);
$userId = $user['id'] ?? null;

/* Ensure orders table has guest_email and guest_name (safe, idempotent) */
try {
    $cols = $pdo->query("SHOW COLUMNS FROM `orders`")->fetchAll(PDO::FETCH_COLUMN, 0);
    $need = [];
    if (!in_array('guest_email', $cols)) $need[] = "ADD COLUMN guest_email VARCHAR(255) DEFAULT NULL";
    if (!in_array('guest_name', $cols)) $need[] = "ADD COLUMN guest_name VARCHAR(255) DEFAULT NULL";
    if (!empty($need)) {
        foreach ($need as $a) {
            $pdo->exec("ALTER TABLE `orders` {$a};");
            outLog("ALTER TABLE orders: {$a}");
        }
    }
} catch (Throwable $e) {
    outLog("SHOW COLUMNS orders failed: " . $e->getMessage());
    // pokračujeme, nemusí být kritické (pokud ALTER neprošlo, INSERT s extra sloupci selže a zachytíme níže)
}

/* Fetch current prices + validate books exist and are active */
$placeholders = implode(',', array_fill(0, count($normalized), '?'));
$ids = array_keys($normalized);

try {
    $stmt = $pdo->prepare("SELECT id, nazov, cena, is_active, pdf_file, obrazok FROM books WHERE id IN ({$placeholders})");
    $stmt->execute($ids);
    $books = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $found = [];
    foreach ($books as $b) $found[(int)$b['id']] = $b;
    // Validate
    foreach ($ids as $bid) {
        if (!isset($found[$bid])) {
            $_SESSION['eshop_error'] = "Kniha s ID {$bid} sa nenašla.";
            header('Location: /eshop/cart.php'); exit;
        }
        if ((int)$found[$bid]['is_active'] !== 1) {
            $_SESSION['eshop_error'] = "Kniha '" . safe($found[$bid]['nazov']) . "' momentálne nie je dostupná.";
            header('Location: /eshop/cart.php'); exit;
        }
    }
} catch (Throwable $e) {
    outLog("DB select books failed: " . $e->getMessage());
    $_SESSION['eshop_error'] = "Chyba servera pri spracovaní košíka.";
    header('Location: /eshop/cart.php'); exit;
}

/* Compute total */
$total = 0.0;
$itemsForInsert = [];
foreach ($normalized as $bid => $qty) {
    $price = (float)($found[$bid]['cena'] ?? 0.0);
    $subtotal = $price * $qty;
    $total += $subtotal;
    $itemsForInsert[] = [
        'book_id' => (int)$bid,
        'quantity' => (int)$qty,
        'unit_price' => number_format($price, 2, '.', '')
    ];
}

/* Payment / order meta */
$currency = (function_exists('eshop_settings') ? eshop_settings($pdo, 'currency') : 'EUR') ?? 'EUR';
$status = 'pending';
$payment_method = $posted['payment_method'] ?? 'bank_transfer';

/* create order + items + invoice in transaction */
try {
    $pdo->beginTransaction();

    // Insert order (user_id nullable)
    $orderInsertSql = "INSERT INTO orders (user_id, total_price, currency, status, payment_method, created_at, guest_email, guest_name) VALUES (?, ?, ?, ?, ?, NOW(), ?, ?)";
    $orderStmt = $pdo->prepare($orderInsertSql);
    $orderStmt->execute([
        $userId,
        number_format($total,2,'.',''),
        $currency,
        $status,
        $payment_method,
        $customer_email,
        $customer_name
    ]);
    $orderId = (int)$pdo->lastInsertId();

    // Insert items
    $itemStmt = $pdo->prepare("INSERT INTO order_items (order_id, book_id, quantity, unit_price, created_at) VALUES (?, ?, ?, ?, NOW())");
    foreach ($itemsForInsert as $it) {
        $itemStmt->execute([$orderId, $it['book_id'], $it['quantity'], $it['unit_price']]);
    }

    // Create invoice record + simple invoice html
    // invoice_number: YYYYMMDD-ORDERID (unikátne)
    $invNumber = date('Ymd') . '-' . $orderId;
    $invoiceDir = __DIR__ . '/../invoices';
    if (!is_dir($invoiceDir)) @mkdir($invoiceDir, 0755, true);
    $htmlPath = $invoiceDir . "/invoice-{$orderId}.html";
    $pdfPath  = $invoiceDir . "/invoice-{$orderId}.pdf"; // optionally generate later
    // Build minimal HTML invoice (epic design can be done in separate template)
    ob_start();
    ?>
    <!doctype html><html lang="sk"><head><meta charset="utf-8"><title>Faktúra <?php echo htmlspecialchars($invNumber); ?></title>
    <style>
      body{font-family:Arial,Helvetica,sans-serif;background:#fff;color:#222;padding:20px}
      .inv-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px}
      .inv-header h1{margin:0;font-size:20px;color:#6a4518}
      .inv-meta{font-size:0.9rem;color:#555}
      table{width:100%;border-collapse:collapse;margin-top:12px}
      th,td{padding:8px;border-bottom:1px solid #eee;text-align:left}
      .total{font-weight:800;text-align:right;padding:8px}
    </style>
    </head><body>
      <div class="inv-header">
        <div><h1>Knihy od Autorov — Faktúra</h1><div class="inv-meta">Číslo: <?php echo safe($invNumber); ?></div></div>
        <div class="inv-meta">Dátum: <?php echo date('Y-m-d H:i'); ?></div>
      </div>
      <div><strong>Objednávateľ:</strong> <?php echo safe($customer_name ?: ($user['meno'] ?? 'Host')); ?> — <?php echo safe($customer_email); ?></div>
      <table><thead><tr><th>Názov</th><th>Množstvo</th><th>Cena</th><th>Spolu</th></tr></thead><tbody>
      <?php foreach ($itemsForInsert as $it):
          $b = $found[$it['book_id']];
          $line = number_format($it['quantity'] * (float)$it['unit_price'], 2, ',', '.'); ?>
        <tr>
          <td><?php echo safe($b['nazov'] ?? ''); ?></td>
          <td><?php echo (int)$it['quantity']; ?></td>
          <td><?php echo number_format((float)$it['unit_price'],2,',','.'); ?> €</td>
          <td><?php echo $line; ?> €</td>
        </tr>
      <?php endforeach; ?>
      </tbody></table>
      <div class="total">Celkom: <?php echo number_format($total,2,',','.'); ?> €</div>
      <div style="margin-top:18px;font-size:0.9rem;color:#666">Variabilný symbol: <?php echo safe(str_pad((string)$orderId, 10, '0', STR_PAD_LEFT)); ?></div>
    </body></html>
    <?php
    $html = ob_get_clean();
    file_put_contents($htmlPath, $html);

    // insert invoice record
    $insInv = $pdo->prepare("INSERT INTO invoices (order_id, invoice_number, html_path, amount, created_at) VALUES (?, ?, ?, ?, NOW())");
    $insInv->execute([$orderId, $invNumber, $htmlPath, number_format($total,2,'.','')]);
    $pdo->commit();

    // optional: try to generate simple QR for payment (Banka QR / SEPA formatted)
    try {
        // payment payload example (simple format for many banks: SEPA not implemented here fully)
        $iban = (function_exists('eshop_settings') ? eshop_settings($pdo,'company_iban') : null) ?? null;
        $vs = str_pad((string)$orderId, 10, '0', STR_PAD_LEFT);
        $amountForQR = number_format($total, 2, '.', '');
        $qrText = "iban:{$iban};amount:{$amountForQR};currency:{$currency};vs:{$vs};note:Objednavka%20{$orderId}";
        $qrFile = $invoiceDir . "/qr-{$orderId}.png";
        if (file_exists(__DIR__ . '/../../libs/phpqrcode/phpqrcode.php') || file_exists(__DIR__ . '/../../libs/phpqrcode/qrlib.php')) {
            // include whichever exists
            @include_once __DIR__ . '/../../libs/phpqrcode/phpqrcode.php';
            @include_once __DIR__ . '/../../libs/phpqrcode/qrlib.php';
            if (class_exists('QRcode')) {
                \QRcode::png($qrText, $qrFile, 'L', 6, 2);
            } elseif (function_exists('QRcode::png')) {
                // unlikely
            }
        }
    } catch (Throwable $e) {
        outLog("QR generation failed: " . $e->getMessage());
    }

    // finally empty the cart (session)
    unset($_SESSION['cart']);
    $_SESSION['eshop_success'] = "Objednávka bola vytvorená. Číslo objednávky: {$orderId}.";
    header('Location: /eshop/thank-you.php?order=' . $orderId);
    exit;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    outLog("Checkout transaction failed: " . $e->getMessage());
    $_SESSION['eshop_error'] = "Chyba pri vytváraní objednávky: " . $e->getMessage();
    header('Location: /eshop/cart.php');
    exit;
}
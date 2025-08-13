<?php
declare(strict_types=1);
/**
 * /eshop/actions/checkout-create.php
 * Vytvorenie objednávky (transakčne). Podporuje guest objednávky.
 */
require_once __DIR__ . '/../_init.php';

$pdoLocal = $GLOBALS['pdo'] ?? null;
if (!($pdoLocal instanceof PDO)) {
    eshop_log('ERROR', 'checkout-create: PDO nie je dostupné.');
    flash_set('error', 'Interná chyba (DB). Prosím kontaktujte podporu.');
    redirect('/eshop/cart.php');
}

// CSRF
$token = $_POST['_csrf'] ?? null;
if (!csrf_verify_token($token, 'checkout')) {
    eshop_log('WARN', 'checkout-create: CSRF fail');
    flash_set('error', 'Chyba formulára (CSRF). Skúste to znova.');
    redirect('/eshop/cart.php');
}

// získáme košík
$cart = $_SESSION['cart'] ?? [];
if (empty($cart) || !is_array($cart)) {
    flash_set('error', 'Košík je prázdny.');
    redirect('/eshop/cart.php');
}

// vstupní data
$email = trim((string)($_POST['email'] ?? ''));
$payment_method = trim((string)($_POST['payment_method'] ?? 'bank'));
if (empty($email) && !auth_user_id()) {
    flash_set('error', 'Musíte zadať email (alebo prihlásiť sa).');
    redirect('/eshop/cart.php');
}

// sestavíme mapu book_id=>qty (z session)
$map = [];
foreach ($cart as $r) {
    if (!is_array($r)) continue;
    $bid = isset($r['book_id']) ? (int)$r['book_id'] : 0;
    $qty = isset($r['qty']) ? (int)$r['qty'] : 0;
    if ($bid <= 0 || $qty <= 0) continue;
    if (!isset($map[$bid])) $map[$bid] = 0;
    $map[$bid] += $qty;
}
if (empty($map)) {
    flash_set('error', 'Košík je prázdny.');
    redirect('/eshop/cart.php');
}

// načteme ceny a typy produktů z DB
$placeholders = implode(',', array_fill(0, count($map), '?'));
$stmt = $pdoLocal->prepare("SELECT id, cena, mena, pdf_file FROM books WHERE id IN ($placeholders) AND is_active = 1");
$stmt->execute(array_keys($map));
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (!$rows) {
    eshop_log('ERROR', 'checkout-create: žiadne knihy nenájdené pre položky: ' . json_encode(array_keys($map)));
    flash_set('error', 'Niektoré položky v košíku nie sú dostupné.');
    redirect('/eshop/cart.php');
}

// spočítáme sumu
$total = 0.0;
$itemsToInsert = [];
foreach ($rows as $r) {
    $id = (int)$r['id'];
    $qty = $map[$id] ?? 0;
    if ($qty <= 0) continue;
    // pokud je digitální, v košíku máme qty=1 obvykle
    if (!empty($r['pdf_file'])) $qty = 1;
    $price = (float)$r['cena'];
    $sub = round($price * $qty, 2);
    $total += $sub;
    $itemsToInsert[] = [
        'book_id' => $id,
        'quantity' => $qty,
        'unit_price' => $price
    ];
}

// user handling: pokud je přihlášen, použij ID; pokud ne, pokus se najít podle emailu, jinak ponech NULL (guest)
$userId = auth_user_id();
if ($userId === null && $email !== '') {
    try {
        $stmtU = $pdoLocal->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $stmtU->execute([$email]);
        $uRow = $stmtU->fetch(PDO::FETCH_ASSOC);
        if ($uRow) {
            $userId = (int)$uRow['id'];
        } else {
            // nebudeme automaticky vytvářet uživatele — objednávka zůstane guest
            $userId = null;
        }
    } catch (Throwable $e) {
        eshop_log('ERROR', 'checkout-create: chyba pri hľadaní užívateľa: ' . $e->getMessage());
    }
}

// vytvoření variabilního symbolu (jednoduchý unikátní kód)
$variabilny_symbol = strtoupper(substr(md5(uniqid('', true)), 0, 10));

try {
    $pdoLocal->beginTransaction();

    $stmtOrder = $pdoLocal->prepare("INSERT INTO orders (user_id, total_price, currency, status, payment_method, variabilny_symbol, dph_rate, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
    $stmtOrder->execute([$userId, number_format($total,2,'.',''), 'EUR', 'pending', $payment_method, $variabilny_symbol, 0.00]);
    $orderId = (int)$pdoLocal->lastInsertId();

    $stmtItem = $pdoLocal->prepare("INSERT INTO order_items (order_id, book_id, quantity, unit_price, created_at) VALUES (?, ?, ?, ?, NOW())");
    foreach ($itemsToInsert as $it) {
        $stmtItem->execute([$orderId, $it['book_id'], $it['quantity'], number_format($it['unit_price'], 2, '.', '')]);
    }

    $pdoLocal->commit();
} catch (Throwable $e) {
    $pdoLocal->rollBack();
    eshop_log('ERROR', 'checkout-create: chyba pri vytváraní objednávky: ' . $e->getMessage());
    flash_set('error', 'Počas vytvárania objednávky nastala chyba. Kontaktujte podporu.');
    redirect('/eshop/cart.php');
}

// smazat košík
unset($_SESSION['cart']);

// pokusíme se poslat potvrzovací email (neblokující)
try {
    if (class_exists('\PHPMailer\PHPMailer\PHPMailer')) {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $smtp = $GLOBALS['smtp'] ?? [];
        if (is_array($smtp) && !empty($smtp['host'])) {
            $mail->isSMTP();
            $mail->Host = $smtp['host'];
            $mail->Port = $smtp['port'] ?? 587;
            $mail->SMTPAuth = true;
            $mail->Username = $smtp['username'] ?? '';
            $mail->Password = $smtp['password'] ?? '';
            if (!empty($smtp['secure'])) $mail->SMTPSecure = $smtp['secure'];
        }
        $fromEmail = $smtp['from_email'] ?? 'info@knihyodautorov.sk';
        $fromName = $smtp['from_name'] ?? 'Knihy od Autorov';
        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($email);
        $mail->Subject = "Potvrdenie objednávky #{$orderId}";
        $body = "Ďakujeme za objednávku. Číslo objednávky: {$orderId}\nSuma: " . number_format($total,2,',',' ') . " EUR\nOdkaz: " . site_base_url() . "/eshop/thank-you.php?order_id={$orderId}";
        $mail->Body = $body;
        @$mail->send();
        eshop_log('INFO', "checkout-create: potvrzovací email odoslaný na {$email} pre order {$orderId}");
    }
} catch (Throwable $e) {
    eshop_log('WARN', 'checkout-create: posielanie emailu zlyhalo: ' . $e->getMessage());
}

// redirect na thank-you
flash_set('success', 'Objednávka vytvorená. Ďakujeme!');
redirect('/eshop/thank-you.php?order_id=' . $orderId);
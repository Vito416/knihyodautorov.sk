<?php
// /eshop/eshop.php
// Hlavná eshop stránka — zoznam PDF kníh, rychlý nákup (bankový prevod) a modal detail
// Predpoklad: db/config/config.php vracia PDO ako $pdo

declare(strict_types=1);
session_start();

require_once __DIR__ . '/../db/config/config.php'; // vracia $pdo

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

// ---------- handle add-to-cart / create order (jednoduchý prevodný checkout) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_order') {
    header('Content-Type: application/json; charset=utf-8');

    $user_email = trim((string)($_POST['email'] ?? ''));
    $book_ids = $_POST['books'] ?? []; // pole id => quantity (ale my predáme 1)
    if (!filter_var($user_email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['error'=>'Neplatný e-mail.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!is_array($book_ids) || count($book_ids) === 0) {
        echo json_encode(['error'=>'Musíte vybrať aspoň jednu knihu.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        // vytvor/našli užívateľa podľa emailu (ak neexistuje, vytvorí sa dočasný)
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$user_email]);
        $uid = $stmt->fetchColumn();
        if (!$uid) {
            $stmt2 = $pdo->prepare("INSERT INTO users (meno,email,heslo) VALUES (?, ?, ?)");
            $tempName = explode('@', $user_email)[0];
            $stmt2->execute([$tempName, $user_email, password_hash(bin2hex(random_bytes(6)), PASSWORD_DEFAULT)]);
            $uid = (int)$pdo->lastInsertId();
        } else $uid = (int)$uid;

        // spočítaj cenu a vlož objednávku
        $placeholders = implode(',', array_fill(0, count($book_ids), '?'));
        $stmt = $pdo->prepare("SELECT id, cena FROM books WHERE id IN ($placeholders) AND COALESCE(is_active,1)=1");
        $stmt->execute(array_values($book_ids));
        $books = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$books) throw new Exception('Vybrané knihy nenájdené.');

        $total = 0.0;
        foreach ($books as $b) $total += (float)$b['cena'];

        $pdo->beginTransaction();
        $stmtOrder = $pdo->prepare("INSERT INTO orders (user_id, total_price, currency, status, payment_method) VALUES (?, ?, ?, ?, ?)");
        $stmtOrder->execute([$uid, number_format($total,2,'.',''), 'EUR', 'pending', 'bank_transfer']);
        $orderId = (int)$pdo->lastInsertId();

        $stmtItem = $pdo->prepare("INSERT INTO order_items (order_id, book_id, quantity, unit_price) VALUES (?, ?, ?, ?)");
        foreach ($books as $b) {
            $stmtItem->execute([$orderId, (int)$b['id'], 1, number_format((float)$b['cena'],2,'.','')]);
        }

        // vytvor invoice záznam (HTML sa vygeneruje pomocou generate_invoice.php)
        $stmtInv = $pdo->prepare("INSERT INTO invoices (order_id, invoice_number, html_path, amount) VALUES (?, ?, ?, ?)");
        $invNumber = 'INV-' . date('Ymd') . '-' . str_pad((string)$orderId,4,'0',STR_PAD_LEFT);
        $invPath = '/eshop/invoices/' . $invNumber . '.html';
        $stmtInv->execute([$orderId, $invNumber, $invPath, number_format($total,2,'.','')]);

        $pdo->commit();

        echo json_encode(['ok'=>true, 'order_id'=>$orderId, 'invoice_number'=>$invNumber, 'invoice_path'=>$invPath], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("eshop create_order error: " . $e->getMessage());
        echo json_encode(['error'=>'Chyba pri vytváraní objednávky. Skúste neskôr.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// ---------- AJAX endpoint: vráti náhodné promo knihy (limit param) ----------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'promo') {
    header('Content-Type: application/json; charset=utf-8');
    $limit = isset($_GET['limit']) ? max(1, min(8, (int)$_GET['limit'])) : 4;
    try {
        $stmt = $pdo->prepare("SELECT b.id,b.nazov,b.popis,b.cena,b.obrazok,a.meno AS autor FROM books b LEFT JOIN authors a ON b.author_id=a.id WHERE COALESCE(b.is_active,1)=1 ORDER BY RAND() LIMIT :lim");
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $baseImg = '/books-img/';
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => (int)$r['id'],
                'nazov' => $r['nazov'],
                'popis' => $r['popis'],
                'cena' => $r['cena'],
                'autor' => $r['autor'],
                'obrazok' => (!empty($r['obrazok']) ? $baseImg . ltrim($r['obrazok'],'/') : '/assets/books-imgFB.png')
            ];
        }
        echo json_encode(['items'=>$out], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['error'=>'DB error'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ---------- PAGE RENDER (non-AJAX) ----------
?><!doctype html>
<html lang="sk">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>E-shop — Knihy od autorov</title>
  <link rel="stylesheet" href="/css/styles.css">
  <link rel="stylesheet" href="/eshop/css/eshop.css">
  <script src="/eshop/js/eshop.js" defer></script>
</head>
<body>
<?php include_once __DIR__ . '/../partials/header.php'; ?>

<main class="eshop-main">
  <section class="eshop-hero">
    <div class="eshop-hero-inner">
      <h1>Obchod — PDF knihy</h1>
      <p>Vyber si epickú knihu. Po vykonaní platby (prevod) obdržíš faktúru a link na stiahnutie.</p>
    </div>
  </section>

  <section class="eshop-promo">
    <div class="paper-wrap eshop-paper">
      <div class="eshop-header">
        <h2 class="section-title">Odporúčané knihy</h2>
        <div class="search-row">
          <input id="eshopSearch" placeholder="Hľadať názov, autora alebo žáner..." />
          <button id="eshopSearchBtn" class="btn">Hľadaj</button>
        </div>
      </div>

      <div id="promoGrid" class="promo-grid" aria-live="polite">
        <!-- naplní eshop/js/eshop.js cez AJAX -->
      </div>

      <div class="eshop-note">
        <p>Všetky PDF sú chránené. Po uhradení obdržíte faktúru (platby cez prevod). Časť výťažku je venovaná babyboxom.</p>
      </div>
    </div>
  </section>

  <section class="eshop-actions">
    <div class="paper-wrap eshop-paper small">
      <h3>Rýchly nákup</h3>
      <form id="eshopCheckout" class="eshop-checkout">
        <label for="checkoutEmail">E-mail (na faktúru a potvrdenie)</label>
        <input id="checkoutEmail" name="email" type="email" required placeholder="tvoja@posta.sk">
        <input type="hidden" name="action" value="create_order">
        <div id="checkoutBooks" class="checkout-books" aria-hidden="true"></div>
        <button type="submit" class="btn btn-primary">Vytvoriť objednávku (prevod)</button>
      </form>
      <div id="checkoutResult" class="checkout-result" role="status" aria-live="polite"></div>
    </div>
  </section>
</main>

<?php include_once __DIR__ . '/../partials/footer.php'; ?>
</body>
</html>

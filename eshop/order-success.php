<?php
// eshop/order-success.php
session_start();
require_once __DIR__ . '/../db/config/config.php';

$orderId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($orderId <= 0) {
    header('Location: eshop.php');
    exit;
}

// načítaj objednávku
$stmt = $pdo->prepare("SELECT o.*, u.meno AS user_name FROM orders o LEFT JOIN users u ON o.user_id = u.id WHERE o.id = ?");
$stmt->execute([$orderId]);
$order = $stmt->fetch();

if (!$order) {
    http_response_code(404);
    echo "Objednávka nenájdená.";
    exit;
}

// kontrola práv: majiteľ objednávky alebo admin (session admin_id)
$canView = false;
if (isset($_SESSION['admin_id'])) $canView = true;
if (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] === (int)$order['user_id']) $canView = true;

if (!$canView) {
    http_response_code(403);
    echo "Nemáte právo vidieť túto objednávku.";
    exit;
}

// načítaj položky objednávky
$stmt = $pdo->prepare("SELECT oi.*, b.nazov, b.pdf_file, b.obrazok FROM order_items oi JOIN books b ON oi.book_id = b.id WHERE oi.order_id = ?");
$stmt->execute([$orderId]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// include header/footer
if (file_exists(__DIR__ . '/partials/header.php')) include __DIR__ . '/partials/header.php';
?>
<link rel="stylesheet" href="/eshop/css/checkout.css">

<section class="order-success">
  <div class="order-inner" style="max-width:1000px;margin:40px auto;padding:16px">
    <h1>Ďakujeme za objednávku</h1>
    <p>Číslo objednávky: <strong>#<?= (int)$order['id'] ?></strong></p>
    <p>Stav: <strong><?= htmlspecialchars($order['status']) ?></strong></p>
    <p>Celková suma: <strong><?= htmlspecialchars(number_format($order['total_price'],2,',','')) ?> <?= htmlspecialchars($order['currency']) ?></strong></p>
    <hr>

    <h2>Položky</h2>
    <ul>
      <?php foreach ($items as $it): ?>
        <li style="margin:12px 0;padding:12px;background:#fff;border-radius:8px;box-shadow:0 6px 18px rgba(0,0,0,0.06);">
          <div style="display:flex;gap:12px;align-items:center">
            <div style="width:84px;height:120px;background:#eee;border-radius:6px;overflow:hidden">
              <img src="<?= '../books-img/' . htmlspecialchars($it['obrazok'] ?: 'placeholder.jpg') ?>" alt="<?= htmlspecialchars($it['nazov']) ?>" style="width:100%;height:100%;object-fit:cover">
            </div>
            <div style="flex:1">
              <strong><?= htmlspecialchars($it['nazov']) ?></strong><br>
              Množstvo: <?= (int)$it['quantity'] ?> • Cena: <?= htmlspecialchars(number_format($it['unit_price'],2,',','')) ?> €
            </div>
            <div style="text-align:right">
              <?php
                // Ak objednávka zaplatená => ukážeme link na download
                if ($order['status'] === 'paid' || isset($_SESSION['admin_id'])):
                  // download odkaz vedie na /download.php?book=ID (máš hotový)
              ?>
                <a class="btn-download" href="/download.php?book=<?= (int)$it['book_id'] ?>">Stiahnuť PDF</a>
              <?php else: ?>
                <span style="color:#666">Stiahnutie po zaplatení</span>
              <?php endif; ?>
            </div>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>

    <p style="margin-top:18px">Ak máte otázky ohľadom objednávky, kontaktujte nás cez <a href="/contact.php">kontakt</a>.</p>

  </div>
</section>

<?php if (file_exists(__DIR__ . '/partials/footer.php')) include __DIR__ . '/partials/footer.php'; ?>

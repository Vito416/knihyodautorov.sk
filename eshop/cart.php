<?php
declare(strict_types=1);
/**
 * /eshop/cart.php
 * Zobrazení košíka + support pro odstranění položky přes AJAX POST do /eshop/actions/cart-remove.php
 * Tento soubor NEobsahuje vnořené formy.
 */

require_once __DIR__ . '/_init.php';

$pdoLocal = $GLOBALS['pdo'] ?? null;
if (!($pdoLocal instanceof PDO)) {
    eshop_log('ERROR', 'PDO nie je dostupné v cart.php');
    flash_set('error', 'Interná chyba (DB).');
    redirect('./');
}

// Normalizujeme košík do mapy book_id => qty
$cartRaw = $_SESSION['cart'] ?? [];
$map = [];
foreach ($cartRaw as $r) {
    if (!is_array($r)) continue;
    $bid = isset($r['book_id']) ? (int)$r['book_id'] : 0;
    $qty = isset($r['qty']) ? (int)$r['qty'] : 0;
    if ($bid <= 0 || $qty <= 0) continue;
    if (!isset($map[$bid])) $map[$bid] = 0;
    $map[$bid] += $qty;
}

// Pokud prázdný košík
if (empty($map)) {
    $items = [];
    $total = 0.0;
} else {
    $placeholders = implode(',', array_fill(0, count($map), '?'));
    $stmt = $pdoLocal->prepare("SELECT id, nazov, cena, mena, pdf_file, obrazok FROM books WHERE id IN ($placeholders) AND is_active = 1");
    $stmt->execute(array_keys($map));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $items = [];
    $total = 0.0;
    foreach ($rows as $r) {
        $id = (int)$r['id'];
        $qty = $map[$id] ?? 0;
        if ($qty <= 0) continue;
        if (!empty($r['pdf_file'])) $qty = 1;
        $sub = (float)$r['cena'] * $qty;
        $items[] = [
            'id' => $id,
            'nazov' => $r['nazov'],
            'qty' => $qty,
            'unit' => (float)$r['cena'],
            'sub' => $sub,
            'mena' => $r['mena'],
            'pdf' => !empty($r['pdf_file']),
            'obrazok' => $r['obrazok'] ?? null
        ];
        $total += $sub;
    }
}

// CSRF token pro remove (budeme ho předávat v AJAX)
$csrfToken = csrf_get_token('cart');

?>
<!doctype html>
<html lang="sk">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Košík — Knihy od Autorov</title>
  <link rel="stylesheet" href="/eshop/css/eshop.css">
  <style>
    .wrap { max-width:980px; margin:36px auto; padding:24px; background:var(--paper,#fff); border-radius:12px; }
    table { width:100%; border-collapse: collapse; margin-top: 12px; }
    th, td { padding:10px; border-bottom:1px solid #eee; vertical-align: middle; }
    .actions { margin-top:16px; }
    .btn { padding:8px 12px; border-radius:8px; text-decoration:none; background:var(--accent,#c08a2e); color:#fff; display:inline-block; }
    input.qty { width:64px; }
    .muted { color:#6b6155; }
    .note { margin-bottom:12px; padding:10px; background:#fff5e6; border-radius:8px; color:#5a472f; }
  </style>
</head>
<body>
  <div class="wrap paper-wrap">
    <h1>Váš košík</h1>

    <?php foreach (flash_all() as $m) echo '<div class="note">'.htmlspecialchars((string)$m,ENT_QUOTES|ENT_HTML5).'</div>'; ?>

    <?php if (empty($items)): ?>
      <p class="muted">V košíku momentálne nič nie je. <a href="/eshop/catalog.php">Pokračovať v nákupe</a></p>
    <?php else: ?>
      <!-- Form pro aktualizaci množství -->
      <form id="cart-update-form" action="/eshop/actions/cart-update.php" method="post">
        <?php csrf_field('cart'); ?>
        <table>
          <thead>
            <tr><th>Kniha</th><th>Množstvo</th><th>Jednotková cena</th><th>Medzisúčet</th><th></th></tr>
          </thead>
          <tbody>
            <?php foreach ($items as $it): ?>
              <tr data-book-id="<?php echo $it['id']; ?>">
                <td style="width:45%;">
                  <?php if ($it['obrazok']): ?>
                    <img src="/books-img/<?php echo htmlspecialchars($it['obrazok'], ENT_QUOTES | ENT_HTML5); ?>" alt="" style="height:64px; vertical-align:middle; margin-right:8px;">
                  <?php endif; ?>
                  <strong><?php echo htmlspecialchars($it['nazov'], ENT_QUOTES | ENT_HTML5); ?></strong>
                  <?php if ($it['pdf']): ?><div class="muted"> (digitálna verzia)</div><?php endif; ?>
                </td>
                <td style="width:12%;">
                  <?php if ($it['pdf']): ?>
                    <input type="hidden" name="items[<?php echo $it['id']; ?>]" value="1">
                    1
                  <?php else: ?>
                    <input class="qty" type="number" name="items[<?php echo $it['id']; ?>]" value="<?php echo $it['qty']; ?>" min="1">
                  <?php endif; ?>
                </td>
                <td style="width:15%;"><?php echo number_format($it['unit'], 2, ',', ' ') . ' ' . htmlspecialchars($it['mena'], ENT_QUOTES | ENT_HTML5); ?></td>
                <td style="width:15%;"><?php echo number_format($it['sub'], 2, ',', ' ') . ' ' . htmlspecialchars($it['mena'], ENT_QUOTES | ENT_HTML5); ?></td>
                <td style="width:13%;">
                  <!-- Remove button: spouští JS, který POSTne na cart-remove.php -->
                  <button type="button" class="btn btn-remove" data-book-id="<?php echo $it['id']; ?>">Odstrániť</button>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr>
              <td colspan="3" style="text-align:right"><strong>Spolu</strong></td>
              <td colspan="2"><strong><?php echo number_format($total, 2, ',', ' '); ?> <?php echo !empty($items[0]['mena']) ? htmlspecialchars($items[0]['mena'], ENT_QUOTES | ENT_HTML5) : 'EUR'; ?></strong></td>
            </tr>
          </tfoot>
        </table>

        <div class="actions" style="margin-top:12px;">
          <button class="btn" type="submit">Aktualizovať košík</button>
          &nbsp;
          <a class="btn" href="/eshop/catalog.php">Pokračovať v nákupe</a>
        </div>
      </form>

      <hr>

      <h2>Dokončiť objednávku</h2>
      <form action="/eshop/actions/checkout-create.php" method="post">
        <?php csrf_field('checkout'); ?>

        <?php if (auth_user_id()): 
            $user = auth_user($pdoLocal);
            $emailVal = $user['email'] ?? '';
        else:
            $emailVal = '';
        endif;
        ?>

        <?php if (!auth_user_id()): ?>
          <p>
            <label>Email (pre potvrdenie a odkaz na stiahnutie):<br>
            <input type="email" name="email" required value="<?php echo htmlspecialchars($emailVal, ENT_QUOTES | ENT_HTML5); ?>"></label>
          </p>
        <?php else: ?>
          <p class="muted">Ste prihlásený. Objednávka bude spojená s vaším účtom.</p>
        <?php endif; ?>

        <p>
          <label>Spôsob platby:<br>
            <select name="payment_method">
              <option value="card">Online platba (card)</option>
              <option value="bank">Bankový prevod</option>
            </select>
          </label>
        </p>

        <button class="btn" type="submit">Prejsť k objednávke</button>
      </form>
    <?php endif; ?>
  </div>

  <script>
    (function(){
      // CSRF token z PHP (bezpečně vložený)
      const CSRF_TOKEN = '<?php echo addslashes($csrfToken); ?>';
      const removeButtons = document.querySelectorAll('.btn-remove');

      function postForm(url, data) {
        // jednoduchý POST form submission via fetch
        return fetch(url, {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
          },
          body: new URLSearchParams(data).toString()
        }).then(resp => {
          if (!resp.ok) throw new Error('HTTP ' + resp.status);
          return resp.text();
        });
      }

      removeButtons.forEach(function(btn){
        btn.addEventListener('click', function(e){
          const bookId = this.getAttribute('data-book-id');
          if (!confirm('Naozaj chcete odstrániť túto položku z košíka?')) return;
          // POST na /eshop/actions/cart-remove.php (existujúci endpoint)
          postForm('/eshop/actions/cart-remove.php', { _csrf: CSRF_TOKEN, book_id: bookId })
            .then(() => {
              // Po úspechu -> reload stránky
              window.location.reload();
            })
            .catch(err => {
              alert('Chyba pri odstraňovaní položky. Skúste to prosím znovu.');
              console.error(err);
            });
        });
      });
    })();
  </script>
</body>
</html>
<?php
declare(strict_types=1);
/**
 * /eshop/thank-you.php
 *
 * Stránka po úspešnom dokončení objednávky.
 * Očakáva GET param 'order_id'.
 *
 * Zobrazí:
 * - číslo objednávky
 * - variabilný symbol
 * - sumu objednávky
 * - prehľad položiek (názov, množstvo, cena)
 * - (ak existuje) dočasný odkaz na stiahnutie založený na download_token v users tabuľke
 *
 * Bezpečnostné poznámky:
 * - stiahnutie súborov vyžaduje token overovaný v /eshop/downloads.php
 * - táto stránka sama o sebe nepredpokladá prihláseného užívateľa
 */

require_once __DIR__ . '/_init.php';

$pdoLocal = $GLOBALS['pdo'] ?? null;
if (!($pdoLocal instanceof PDO)) {
    eshop_log('ERROR', 'PDO nie je dostupné v thank-you.php');
    flash_set('error', 'Interná chyba (DB). Kontaktujte administrátora.');
    redirect('./');
}

// získať order_id z GET
$orderId = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
if ($orderId <= 0) {
    flash_set('error', 'Neplatné ID objednávky.');
    redirect('./');
}

try {
    // Načítame objednávku + download_token (ak existuje užívateľ viazaný na objednávku)
    $stmt = $pdoLocal->prepare("
        SELECT o.id, o.user_id, o.total_price, o.currency, o.variabilny_symbol, o.created_at, u.download_token
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.id
        WHERE o.id = ? LIMIT 1
    ");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        flash_set('error', 'Objednávka nenájdená.');
        redirect('./');
    }

    // Načítať položky objednávky + názvy kníh
    $stmt2 = $pdoLocal->prepare("
        SELECT oi.book_id, oi.quantity, oi.unit_price, b.nazov
        FROM order_items oi
        LEFT JOIN books b ON oi.book_id = b.id
        WHERE oi.order_id = ?
    ");
    $stmt2->execute([$orderId]);
    $items = $stmt2->fetchAll(PDO::FETCH_ASSOC);

    // Pripravíme download link, ak máme download_token
    $downloadLink = null;
    if (!empty($order['download_token'])) {
        $downloadLink = site_base_url() . '/eshop/downloads.php?token=' . rawurlencode($order['download_token']);
    }

} catch (Throwable $e) {
    eshop_log('ERROR', "Chyba pri načítaní objednávky v thank-you.php: " . $e->getMessage());
    flash_set('error', 'Chyba pri načítaní objednávky. Kontaktujte podporu.');
    redirect('./');
}

// Zobrazenie — jednoduché HTML (slovensky)
?>
<!doctype html>
<html lang="sk">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Ďakujeme za objednávku — Knihy od Autorov</title>
  <link rel="stylesheet" href="/eshop/css/eshop.css">
  <style>
    /* Malé lokálne úpravy pre bezpečné zobrazovanie bez závislosti na plnom CSS */
    .wrap { max-width: 980px; margin: 36px auto; padding: 24px; background: var(--paper, #fff); border-radius: 12px; box-shadow: 0 6px 18px rgba(0,0,0,0.06); }
    h1 { font-size: 28px; margin-bottom: 8px; color: var(--ink, #3b2a1a); }
    .muted { color: #6b6155; }
    table { width:100%; border-collapse: collapse; margin-top: 16px; }
    th, td { padding: 10px; text-align: left; border-bottom: 1px solid #eee; }
    .total { font-weight:700; font-size:1.05rem; }
    .btn { display:inline-block; padding:10px 16px; border-radius:8px; text-decoration:none; background:var(--accent,#c08a2e); color:#fff; margin-top:12px; }
    .note { margin-top:12px; padding:12px; background:#fff5e6; border-radius:8px; color:#5a472f; }
  </style>
</head>
<body class="page-thankyou">
  <div class="wrap paper-wrap">
    <?php
      // flash zprávy
      $fl = flash_all();
      foreach ($fl as $k => $v) {
          echo '<div class="note">' . htmlspecialchars((string)$v, ENT_QUOTES | ENT_HTML5) . '</div>';
      }
    ?>

    <h1>Ďakujeme za objednávku</h1>
    <p class="muted">Vaša objednávka bola prijatá. Nižšie sú základné informácie — prosím, skontrolujte ich a vykonajte platbu podľa pokynov.</p>

    <dl>
      <dt>Číslo objednávky:</dt>
      <dd><strong><?php echo htmlspecialchars((string)$order['id'], ENT_QUOTES | ENT_HTML5); ?></strong></dd>

      <dt>Variabilný symbol:</dt>
      <dd><strong><?php echo htmlspecialchars((string)$order['variabilny_symbol'], ENT_QUOTES | ENT_HTML5); ?></strong></dd>

      <dt>Vytvorené:</dt>
      <dd><?php echo htmlspecialchars((string)$order['created_at'], ENT_QUOTES | ENT_HTML5); ?></dd>
    </dl>

    <h3>Položky objednávky</h3>
    <table>
      <thead>
        <tr><th>Kniha</th><th>Množstvo</th><th>Jednotková cena</th><th>Medzisúčet</th></tr>
      </thead>
      <tbody>
      <?php
        $sum = 0.0;
        foreach ($items as $it) {
            $title = $it['nazov'] ?? ('#' . (int)$it['book_id']);
            $qty = (int)$it['quantity'];
            $unit = number_format((float)$it['unit_price'], 2, ',', ' ');
            $sub = $qty * (float)$it['unit_price'];
            $sum += $sub;
            echo '<tr>';
            echo '<td>' . htmlspecialchars($title, ENT_QUOTES | ENT_HTML5) . '</td>';
            echo '<td>' . $qty . '</td>';
            echo '<td>' . $unit . ' ' . htmlspecialchars((string)$order['currency'], ENT_QUOTES | ENT_HTML5) . '</td>';
            echo '<td>' . number_format($sub, 2, ',', ' ') . ' ' . htmlspecialchars((string)$order['currency'], ENT_QUOTES | ENT_HTML5) . '</td>';
            echo '</tr>';
        }
      ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="3" class="total">Spolu</td>
          <td class="total"><?php echo number_format((float)$order['total_price'], 2, ',', ' ') . ' ' . htmlspecialchars((string)$order['currency'], ENT_QUOTES | ENT_HTML5); ?></td>
        </tr>
      </tfoot>
    </table>

    <div class="note">
      <strong>Pokyny k platbe:</strong>
      <p>Využite variabilný symbol pre identifikáciu platby: <strong><?php echo htmlspecialchars((string)$order['variabilny_symbol'], ENT_QUOTES | ENT_HTML5); ?></strong>.
      Po pripísaní platby prevedieme expedíciu / sprístupníme sťahovanie. Ak potrebujete faktúru alebo pomoc, kontaktujte nás.</p>
    </div>

    <?php if ($downloadLink): ?>
      <div style="margin-top:16px;">
        <p><strong>Stiahnutie:</strong> Ak ste si kúpili digitálne PDF, môžete ich stiahnuť cez nasledujúci dočasný odkaz:</p>
        <a class="btn" href="<?php echo htmlspecialchars($downloadLink, ENT_QUOTES | ENT_HTML5); ?>">Stiahnuť zakúpené súbory</a>
        <p class="muted" style="margin-top:8px;">Tento odkaz je dočasný a chránený tokenom. Ak link nefunguje, skontrolujte email, ktorý sme vám zaslali (ak bol odoslaný).</p>
      </div>
    <?php else: ?>
      <p class="muted">Ak sú k dispozícii digitálne súbory, odošleme vám v emaili odkaz na stiahnutie.</p>
    <?php endif; ?>

    <p style="margin-top:18px;">
      <a href="/eshop/index.php">Pokračovať na stránky</a> |
      <?php if (auth_user_id()): ?>
        <a href="/eshop/account/account.php">Môj účet</a>
      <?php else: ?>
        <a href="/eshop/account/register.php">Vytvoriť účet</a>
      <?php endif; ?>
    </p>

    <p class="muted" style="margin-top:22px; font-size:0.9rem;">Ak potrebujete pomoc, odpíšte na email alebo použite kontaktný formulár na stránkach.</p>
  </div>
</body>
</html>
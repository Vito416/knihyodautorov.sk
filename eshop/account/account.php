<?php
declare(strict_types=1);
/**
 * /eshop/account/account.php
 * Zobrazenie profilu užívateľa a zakúpených položiek.
 */

require_once __DIR__ . '/../_init.php';
$pdoLocal = $GLOBALS['pdo'] ?? null;
if (!($pdoLocal instanceof PDO)) {
    eshop_log('ERROR','PDO nie je dostupné v account/account.php');
    flash_set('error','Interná chyba.');
    redirect('/eshop/');
}

$userId = auth_user_id();
if ($userId === null) {
    flash_set('error','Pre prístup do profilu sa prihláste.');
    redirect('/eshop/account/login.php');
}

try {
    $stmt = $pdoLocal->prepare("SELECT id, meno, email, telefon, adresa, download_token FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        flash_set('error','Užívateľ nenájdený.');
        redirect('/eshop/account/login.php');
    }

    // získame zoznam objednávok (len základ)
    $stmt = $pdoLocal->prepare("SELECT id, total_price, currency, status, created_at, variabilny_symbol FROM orders WHERE user_id = ? ORDER BY created_at DESC LIMIT 50");
    $stmt->execute([$userId]);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    eshop_log('ERROR','Chyba pri načítaní účtu: '.$e->getMessage());
    flash_set('error','Chyba pri načítaní účtu.');
    redirect('/eshop/');
}
?>
<!doctype html>
<html lang="sk">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Môj účet</title><link rel="stylesheet" href="/eshop/css/eshop.css"></head>
<body>
  <div class="wrap paper-wrap">
    <h1>Môj účet</h1>
    <?php foreach (flash_all() as $m) echo '<div class="note">'.htmlspecialchars((string)$m,ENT_QUOTES|ENT_HTML5).'</div>'; ?>

    <h2>Údaje</h2>
    <p><strong>Meno:</strong> <?php echo htmlspecialchars($user['meno'], ENT_QUOTES|ENT_HTML5); ?></p>
    <p><strong>Email:</strong> <?php echo htmlspecialchars($user['email'], ENT_QUOTES|ENT_HTML5); ?></p>

    <?php if (!empty($user['download_token'])): 
      $dl = site_base_url() . '/eshop/downloads.php?token=' . rawurlencode($user['download_token']);
    ?>
      <p><strong>Dočasný odkaz na stiahnutie:</strong> <a href="<?php echo htmlspecialchars($dl, ENT_QUOTES|ENT_HTML5); ?>">Stiahnuť zakúpené súbory</a></p>
    <?php endif; ?>

    <h2>Posledné objednávky</h2>
    <?php if (empty($orders)): ?>
      <p class="muted">Zatiaľ žiadne objednávky.</p>
    <?php else: ?>
      <table>
        <thead><tr><th>#</th><th>Vytvorené</th><th>Suma</th><th>Stav</th><th>VS</th></tr></thead>
        <tbody>
        <?php foreach ($orders as $o): ?>
          <tr>
            <td><a href="/eshop/thank-you.php?order_id=<?php echo (int)$o['id']; ?>"><?php echo (int)$o['id']; ?></a></td>
            <td><?php echo htmlspecialchars($o['created_at'], ENT_QUOTES|ENT_HTML5); ?></td>
            <td><?php echo number_format((float)$o['total_price'],2,',',' ') . ' ' . htmlspecialchars($o['currency'], ENT_QUOTES|ENT_HTML5); ?></td>
            <td><?php echo htmlspecialchars($o['status'], ENT_QUOTES|ENT_HTML5); ?></td>
            <td><?php echo htmlspecialchars($o['variabilny_symbol'] ?? '', ENT_QUOTES|ENT_HTML5); ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <p style="margin-top:12px;">
      <a class="btn" href="/eshop/account/logout.php">Odhlásiť sa</a>
      &nbsp;
      <a href="/eshop/catalog.php">Pokračovať v nákupe</a>
    </p>
  </div>
</body>
</html>
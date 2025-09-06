<?php
require __DIR__ . '/../../inc/bootstrap.php';
$action = $_GET['action'] ?? '';
if ($action === 'delete') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id) { $db->prepare('DELETE FROM coupons WHERE id=?')->execute([$id]); }
    header('Location: coupons.php'); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::validate($_POST['csrf_token'] ?? '')) { die('CSRF'); }
    $code = trim($_POST['code'] ?? '');
    $type = $_POST['type'] ?? 'percent';
    $value = (float)($_POST['value'] ?? 0);
    $starts = $_POST['starts_at'] ?? null; $ends = $_POST['ends_at'] ?? null;
    $stmt = $db->prepare('INSERT INTO coupons (code, type, value, currency, starts_at, ends_at, max_redemptions, min_order_amount, applies_to, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
    $stmt->execute([$code,$type,$value,'EUR',$starts,$ends,(int)($_POST['max_redemptions']??0),(float)($_POST['min_order_amount']??0),json_encode([]),(isset($_POST['is_active'])?1:0)]);
    header('Location: coupons.php'); exit;
}
$rows = $db->query('SELECT * FROM coupons ORDER BY created_at DESC LIMIT 200')->fetchAll();
?><!doctype html><html lang="sk"><head><meta charset="utf-8"><title>Kupony</title></head><body>
<?php include __DIR__ . '/../templates/admin-header.php'; ?>
<main>
  <h1>Kupóny</h1>
  <h2>Pridať kupón</h2>
  <form method="post">
    <label>Kód<input name="code" required></label><br>
    <label>Typ<select name="type"><option value="percent">percent</option><option value="fixed">fixed</option></select></label><br>
    <label>Hodnota<input name="value" type="number" step="0.01"></label><br>
    <label>Platné od<input type="date" name="starts_at"></label><br>
    <label>Platné do<input type="date" name="ends_at"></label><br>
    <label>Max uplatnení<input type="number" name="max_redemptions"></label><br>
    <label>Min. objednávka<input type="number" name="min_order_amount" step="0.01"></label><br>
    <label>Aktívny<input type="checkbox" name="is_active" checked></label><br>
    <input type="hidden" name="csrf_token" value="<?=e(CSRF::token())?>">
    <button type="submit">Vytvoriť</button>
  </form>

  <h2>Existujúce</h2>
  <table border="1"><tr><th>ID</th><th>Kód</th><th>Typ</th><th>Hodnota</th><th>Akcie</th></tr>
  <?php foreach($rows as $r): ?>
    <tr>
      <td><?=e($r['id'])?></td>
      <td><?=e($r['code'])?></td>
      <td><?=e($r['type'])?></td>
      <td><?=e($r['value'])?></td>
      <td><a href="?action=delete&id=<?=e($r['id'])?>" onclick="return confirm('Vymazať?')">Vymazať</a></td>
    </tr>
  <?php endforeach; ?>
  </table>
</main>
</body></html>
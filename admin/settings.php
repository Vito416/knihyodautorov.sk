<?php
// /admin/settings.php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();

require __DIR__ . '/bootstrap.php'; // musí nastaviť $pdo, admin_is_logged(), require_admin(), esc()
require_admin();

header('X-Frame-Options: SAMEORIGIN');

// helpery pre settings
function get_setting(PDO $pdo, string $k, $default = null) {
    $s = $pdo->prepare("SELECT v FROM settings WHERE k = ? LIMIT 1");
    $s->execute([$k]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    return $row ? $row['v'] : $default;
}
function set_setting(PDO $pdo, string $k, $v) {
    $up = $pdo->prepare("INSERT INTO settings (k,v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)");
    return $up->execute([$k, (string)$v]);
}

// načítaj predvyplnené hodnoty (kombinácia DB settings + db/config/configsmtp.php ak existuje)
$defaults = [
    'invoice_prefix' => 'FAKT-',
    'invoice_next' => '1000',
    'tax_rate' => '20.00',
    'currency' => 'EUR',
    'invoice_dir' => '/eshop/invoices',
    'admin_email' => '',
    'support_babybox' => '1',
    'require_admin_pwd_change' => '1',
];

// načítaj zo settings DB
$settings = [];
foreach (array_keys($defaults) as $k) {
    $settings[$k] = get_setting($pdo, $k, $defaults[$k]);
}

// pokus načítať db/config/configsmtp.php (ak existuje) a zobraziť hodnoty (bez hesiel)
$smtp_config_file = __DIR__ . '/../db/config/configsmtp.php';
$smtp_from_config = null;
if (file_exists($smtp_config_file)) {
    // config môže vracať pole alebo nastavovať $SMTP_CONFIG
    $maybe = require $smtp_config_file;
    if (is_array($maybe)) {
        $smtp_from_config = $maybe;
    } elseif (isset($SMTP_CONFIG) && is_array($SMTP_CONFIG)) {
        $smtp_from_config = $SMTP_CONFIG;
    } else {
        // niektoré konfigy môžu vracať null, takže sa nič nedeje
        $smtp_from_config = null;
    }
}

// AJAX handling
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax']) && $_POST['ajax'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? 'save';
    try {
        if ($action === 'save') {
            // prijmi a ulož základné nastavenia
            $map = [
                'invoice_prefix' => FILTER_SANITIZE_STRING,
                'invoice_next' => FILTER_SANITIZE_NUMBER_INT,
                'tax_rate' => FILTER_SANITIZE_NUMBER_FLOAT,
                'currency' => FILTER_SANITIZE_STRING,
                'invoice_dir' => FILTER_SANITIZE_STRING,
                'admin_email' => FILTER_SANITIZE_EMAIL,
                'support_babybox' => FILTER_SANITIZE_NUMBER_INT,
                'require_admin_pwd_change' => FILTER_SANITIZE_NUMBER_INT
            ];
            $data = filter_var_array($_POST, $map);
            // fallbacky
            $data['invoice_prefix'] = trim($data['invoice_prefix'] ?? $defaults['invoice_prefix']);
            if ($data['invoice_prefix'] === '') $data['invoice_prefix'] = $defaults['invoice_prefix'];
            $data['invoice_next'] = max(1, (int)($data['invoice_next'] ?? $defaults['invoice_next']));
            $data['tax_rate'] = number_format((float)($data['tax_rate'] ?? $defaults['tax_rate']), 2, '.', '');
            $data['currency'] = strtoupper(trim($data['currency'] ?? $defaults['currency']));
            $data['invoice_dir'] = trim($data['invoice_dir'] ?? $defaults['invoice_dir']);
            $data['admin_email'] = trim($data['admin_email'] ?? '');
            $data['support_babybox'] = (int)($data['support_babybox'] ?? 0) ? 1 : 0;
            $data['require_admin_pwd_change'] = (int)($data['require_admin_pwd_change'] ?? 0) ? 1 : 0;

            // uloz do DB
            foreach ($data as $k => $v) {
                set_setting($pdo, $k, (string)$v);
            }

            echo json_encode(['ok'=>true,'message'=>'Nastavenia uložené.']);
            exit;
        }

        if ($action === 'test_smtp') {
            // test konektivity k SMTP serveru (neodosiela mail iba testuje spojenie)
            $host = trim($_POST['smtp_host'] ?? '');
            $port = (int)($_POST['smtp_port'] ?? 25);
            $timeout = 6;
            $result = ['ok'=>false,'message'=>'Nepodarilo sa pripojiť.'];

            if ($host === '') {
                echo json_encode(['ok'=>false,'message'=>'SMTP: chýba host.']);
                exit;
            }

            $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
            if (!$fp) {
                echo json_encode(['ok'=>false,'message'=>"Chyba: $errstr ($errno)"]);
                exit;
            }
            stream_set_timeout($fp, $timeout);
            $banner = fgets($fp, 512);
            $code = intval(substr(trim($banner),0,3));
            $ok = ($code >= 200 && $code < 400);
            // pošleme EHLO / QUIT
            fwrite($fp, "EHLO localhost\r\n");
            $eh = '';
            while (($line = fgets($fp, 512)) !== false) {
                $eh .= $line;
                if (substr($line,3,1) === ' ') break;
            }
            fwrite($fp, "QUIT\r\n");
            fclose($fp);
            if ($ok) {
                echo json_encode(['ok'=>true,'message'=>'SMTP pripojenie OK. Banner: '.trim($banner),'banner'=>trim($banner),'hello'=>$eh]);
            } else {
                echo json_encode(['ok'=>false,'message'=>'SMTP server odpovedal: '.trim($banner)]);
            }
            exit;
        }

        echo json_encode(['ok'=>false,'message'=>'Neznáma akcia.']);
        exit;

    } catch (Throwable $e) {
        echo json_encode(['ok'=>false,'message'=>'Chyba: '.$e->getMessage()]);
        exit;
    }
}

// ---------- RENDER HTML (GET) ----------
$csrf_token = bin2hex(random_bytes(16));
$_SESSION['admin_csrf'] = $csrf_token;

?><!doctype html>
<html lang="sk">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Administrácia — Nastavenia</title>
  <link rel="stylesheet" href="/admin/css/settings.css">
</head>
<body class="admin-settings-body">
<?php // header (môžeš mať vlastný include) ?>
<?php // include __DIR__ . '/partials/header.php'; ?>

<main class="settings-wrap">
  <div class="settings-card">
    <header class="settings-header">
      <h1>Nastavenia fakturácie & e-shopu</h1>
      <p class="muted">Upravte nastavenia fakturácie, DPH, adresár faktúr a e-mail správcu. Test SMTP slúži len na rýchlu kontrolu spojenia.</p>
    </header>

    <form id="settingsForm" class="settings-form" action="/admin/settings.php" method="post" novalidate>
      <input type="hidden" name="ajax" value="1">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES | ENT_SUBSTITUTE); ?>">

      <div class="form-row">
        <label>Prefix faktúr</label>
        <input type="text" name="invoice_prefix" value="<?php echo esc($settings['invoice_prefix']); ?>" />
      </div>

      <div class="form-row">
        <label>Nasledujúce číslo faktúry</label>
        <input type="number" name="invoice_next" value="<?php echo esc($settings['invoice_next']); ?>" min="1" />
      </div>

      <div class="form-row">
        <label>DPH (%)</label>
        <input type="text" name="tax_rate" value="<?php echo esc($settings['tax_rate']); ?>" />
      </div>

      <div class="form-row">
        <label>Mena</label>
        <input type="text" name="currency" value="<?php echo esc($settings['currency']); ?>" maxlength="3" />
      </div>

      <div class="form-row">
        <label>Adresár faktúr (relatívne k projekt root)</label>
        <input type="text" name="invoice_dir" value="<?php echo esc($settings['invoice_dir']); ?>" />
      </div>

      <div class="form-row">
        <label>E-mail administrátora</label>
        <input type="email" name="admin_email" value="<?php echo esc($settings['admin_email']); ?>" />
      </div>

      <div class="form-row form-row-inline">
        <label><input type="checkbox" name="support_babybox" value="1" <?php echo $settings['support_babybox'] == '1' ? 'checked' : ''; ?> /> Podpora babyboxov (časť výťažku)</label>
        <label><input type="checkbox" name="require_admin_pwd_change" value="1" <?php echo $settings['require_admin_pwd_change'] == '1' ? 'checked' : ''; ?> /> Vyžadovať zmenu hesla pri prvom prihlásení</label>
      </div>

      <div class="form-row">
        <button class="btn-save" type="submit">Uložiť nastavenia</button>
        <button type="button" id="btnTestSmtp" class="btn-test">Otestovať SMTP</button>
        <span id="settingsMsg" class="settings-msg" aria-live="polite"></span>
      </div>
    </form>

    <section class="smtp-config">
      <h2>SMTP konfigurácia (rýchly test)</h2>
      <p class="muted">Ak máte súbor <code>/db/config/configsmtp.php</code>, hodnoty sa zobrazia tu len na kontrolu (heslá sa neukazujú).</p>

      <form id="smtpForm" class="smtp-form" action="/admin/settings.php" method="post" novalidate>
        <input type="hidden" name="ajax" value="1">
        <input type="hidden" name="action" value="test_smtp">
        <div class="form-row"><label>SMTP host</label><input type="text" id="smtp_host" name="smtp_host" value="<?php echo esc($smtp_from_config['host'] ?? get_setting($pdo,'smtp_host','')); ?>" /></div>
        <div class="form-row"><label>Port</label><input type="number" id="smtp_port" name="smtp_port" value="<?php echo esc($smtp_from_config['port'] ?? get_setting($pdo,'smtp_port','25')); ?>" /></div>
        <div class="form-row"><label>Užívateľ (login)</label><input type="text" id="smtp_user" name="smtp_user" value="<?php echo esc($smtp_from_config['user'] ?? ''); ?>" /></div>
        <div class="form-row">
          <button type="button" id="btnRunSmtpTest" class="btn-test">Spusti test pripojenia</button>
          <span id="smtpMsg" class="settings-msg" aria-live="polite"></span>
        </div>
      </form>
    </section>

  </div>
</main>

<script src="/admin/js/settings.js" defer></script>
</body>
</html>
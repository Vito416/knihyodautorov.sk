<?php
// admin/debug/lib-test.php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', 1);

/*
  Rozšírený testovací skript pre libs (mPDF, FPDI, Intervention, PhpSpreadsheet, PHP QR Code, PHPMailer, atď.)
  Umiestni do /admin/debug/lib-test.php a otvori v prehliadači.
  Všetky správy sú v slovenčine.
*/

// ------------------ HELPERS ------------------
function esc(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function btn(string $label, string $href): string { return '<a class="btn" href="'.esc($href).'" target="_blank">'.esc($label).'</a>'; }
function mkpath(string $p) { if (!is_dir($p)) @mkdir($p, 0755, true); }
function fileRel(string $abs): string {
    $root = realpath(__DIR__);
    $absReal = realpath($abs) ?: $abs;
    if ($absReal && strpos($absReal, $root) === 0) {
        return '.' . str_replace(DIRECTORY_SEPARATOR, '/', substr($absReal, strlen($root)));
    }
    return $abs;
}

// ------------------ DETEKCIA AUTOLOAD ------------------
$autoloadCandidates = [
    __DIR__ . '/../../libs/autoload.php',
    __DIR__ . '/../libs/autoload.php',
    __DIR__ . '/../../../libs/autoload.php',
    __DIR__ . '/../../vendor/autoload.php',
    __DIR__ . '/../../libs/autoload.php',
    __DIR__ . '/../../vendor/autoload.php',
];
$autoloadFound = null;
foreach ($autoloadCandidates as $c) {
    if (file_exists($c)) { $autoloadFound = $c; break; }
}

// ------------------ TMP DIR ------------------
$tmpDir = __DIR__ . '/tmp';
mkpath($tmpDir);
$artifacts = []; // uložené súbory

// ------------------ HTML HEAD ------------------
?><!doctype html>
<html lang="sk">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Diagnostics — libs test (admin)</title>
<style>
  body{font-family:Inter,system-ui,Segoe UI,Roboto,Arial;background:linear-gradient(180deg,#fbf9f5,#f2eee5);color:#2b1608;margin:18px}
  .wrap{max-width:1200px;margin:0 auto;padding:22px;background:linear-gradient(180deg,#fff,#f8f6f3);border-radius:12px;box-shadow:0 30px 80px rgba(0,0,0,0.08)}
  h1{margin:0 0 8px}
  .card{background:#fff;padding:14px;border-radius:10px;margin:12px 0;box-shadow:0 8px 30px rgba(0,0,0,0.06)}
  .ok{color:#0b6b3a;font-weight:800}
  .bad{color:#a11;font-weight:800}
  table{width:100%;border-collapse:collapse}
  th,td{padding:8px;border-bottom:1px solid rgba(0,0,0,0.04);text-align:left}
  pre{background:#111;color:#fff;padding:12px;border-radius:6px;overflow:auto}
  .btn{background:#cf9b3a;color:#2b1608;padding:8px 12px;border-radius:8px;text-decoration:none;font-weight:800;margin-right:8px;display:inline-block}
  .muted{color:#6b5a49;font-size:.95rem}
  .small{font-size:.92rem;color:#6b5a49}
</style>
</head>
<body>
<div class="wrap">
  <h1>Komplexná diagnostika knižníc (libs) — admin/debug/lib-test.php</h1>
  <p class="small">Umiestnenie skriptu: <strong><?php echo esc(__DIR__); ?></strong></p>

  <div class="card">
    <h2>1) Autoloader</h2>
    <?php if ($autoloadFound): ?>
      <p>Našiel sa autoloader: <code><?php echo esc($autoloadFound); ?></code></p>
      <p>Načítavam autoloader...</p>
      <?php
        try {
            require_once $autoloadFound;
            $autoloadOK = true;
        } catch (Throwable $e) {
            $autoloadOK = false;
            $autoloadError = $e->getMessage();
        }
      ?>
      <?php if (!empty($autoloadOK)): ?>
        <p class="ok">✅ autoload úspešne načítaný.</p>
      <?php else: ?>
        <p class="bad">❌ autoload zlyhal: <?php echo esc($autoloadError ?? 'neznáma chyba'); ?></p>
      <?php endif; ?>
    <?php else: ?>
      <p class="bad">❌ Autoloader (libs/autoload.php alebo vendor/autoload.php) sa nenašiel. Nahraj ho do /libs alebo použij composer.</p>
    <?php endif; ?>
  </div>

<?php
// ------------------ ENV CHECKS ------------------
function getEnvChecks(): array {
    $checks = [];
    $checks['php_version'] = PHP_VERSION;
    $exts = ['gd','mbstring','xml','zlib','json','curl','openssl','fileinfo','intl'];
    foreach ($exts as $e) $checks['ext_'.$e] = extension_loaded($e);
    return $checks;
}
$env = getEnvChecks();
?>
  <div class="card">
    <h2>2) Prostredie (PHP & rozšírenia)</h2>
    <table>
      <tr><th>Property</th><th>Hodnota</th></tr>
      <tr><td>PHP verzia</td><td><?php echo esc($env['php_version']); ?></td></tr>
      <?php foreach ($env as $k=>$v) if (strpos($k,'ext_')===0): ?>
        <tr><td><?php echo esc(substr($k,4)); ?></td><td><?php echo $v ? '<span class="ok">nainštalované</span>' : '<span class="bad">CHÝBA</span>'; ?></td></tr>
      <?php endif; ?>
    </table>
    <p class="small muted">Pozn.: mPDF vyžaduje <strong>mbstring</strong>, <strong>gd</strong> (alebo imagick), <strong>zlib</strong> a <strong>xml</strong>.</p>
  </div>

<?php
// ------------------ PSR INTERFACES ------------------
function checkInterfaces(array $ifs): array {
    $miss = [];
    foreach ($ifs as $i) {
        if (!interface_exists($i) && !class_exists($i) && !trait_exists($i)) $miss[] = $i;
    }
    return $miss;
}

$psr7 = [
    'Psr\Http\Message\MessageInterface',
    'Psr\Http\Message\RequestInterface',
    'Psr\Http\Message\ResponseInterface',
    'Psr\Http\Message\ServerRequestInterface',
    'Psr\Http\Message\StreamInterface',
    'Psr\Http\Message\UriInterface',
];

$psrLog = [
    'Psr\Log\LoggerInterface',
    'Psr\Log\LoggerAwareInterface',
    'Psr\Log\NullLogger',
];

$psr7Miss = checkInterfaces($psr7);
$psrLogMiss = checkInterfaces($psrLog);
?>
  <div class="card">
    <h2>3) PSR — rozhrania</h2>
    <?php if (!$psr7Miss): ?>
      <p class="ok">✅ PSR-7 interfaces dostupné.</p>
    <?php else: ?>
      <p class="bad">❌ PSR-7 chýbajú: <?php echo esc(implode(', ', $psr7Miss)); ?></p>
    <?php endif; ?>
    <?php if (!$psrLogMiss): ?>
      <p class="ok">✅ PSR-Log dostupné.</p>
    <?php else: ?>
      <p class="bad">❌ PSR-Log chýbajú: <?php echo esc(implode(', ', $psrLogMiss)); ?></p>
    <?php endif; ?>
  </div>

<?php
// ------------------ FPDI (setasign) ------------------
try {
    $fpdi_ok = false;
    if (class_exists('setasign\Fpdi\Fpdi') || class_exists('\setasign\Fpdi\Fpdi')) $fpdi_ok = true;
    if (trait_exists('setasign\Fpdi\FpdiTrait')) $fpdi_ok = true;
    if ($fpdi_ok) {
        echo '<div class="card"><h2>4) FPDI</h2><p class="ok">✅ setasign FPDI: prítomné.</p></div>';
    } else {
        echo '<div class="card"><h2>4) FPDI</h2><p class="bad">❌ setasign FPDI sa nenašiel. Skontroluj /libs/setasign/fpdi alebo kompletnú distribúciu FPDI.</p></div>';
    }
} catch (Throwable $e) {
    echo '<div class="card"><h2>4) FPDI</h2><pre>'.esc($e->getMessage()).'</pre></div>';
}

// ------------------ PHP QR Code ------------------
try {
    if (class_exists('QRcode')) {
        $qfname = $tmpDir . '/qrcode_test.png';
        ob_start();
        QRcode::png('https://knihyodautorov.sk/test', $qfname, 'L', 4, 2);
        ob_end_clean();
        if (file_exists($qfname) && filesize($qfname) > 0) {
            $artifacts[] = $qfname;
            echo '<div class="card"><h2>5) PHP QR Code</h2><p class="ok">✅ phpqrcode OK — vygenerované: '.btn(basename($qfname), fileRel($qfname)).'</p></div>';
        } else {
            echo '<div class="card"><h2>5) PHP QR Code</h2><p class="bad">❌ QR generovanie zlyhalo (skontroluj libs/phpqrcode).</p></div>';
        }
    } else {
        echo '<div class="card"><h2>5) PHP QR Code</h2><p class="bad">❌ phpqrcode (QRcode) nenájdená.</p></div>';
    }
} catch (Throwable $e) {
    echo '<div class="card"><h2>5) PHP QR Code</h2><pre>'.esc($e->getMessage()).'</pre></div>';
}

// ------------------ Intervention Image ------------------
try {
    if (class_exists('Intervention\Image\ImageManagerStatic')) {
        \Intervention\Image\ImageManagerStatic::configure(['driver' => extension_loaded('gd') ? 'gd' : 'imagick']);
        $img = \Intervention\Image\ImageManagerStatic::canvas(240,360,'#efe3c8');
        $img->text('Knihy od Autorov', 16, 20);
        $f = $tmpDir . '/intervention_test.png';
        $img->save($f);
        $artifacts[] = $f;
        echo '<div class="card"><h2>6) Intervention Image</h2><p class="ok">✅ ImageManagerStatic funguje — '.btn(basename($f), fileRel($f)).'</p></div>';
    } elseif (class_exists('Intervention\Image\ImageManager')) {
        $mgr = new \Intervention\Image\ImageManager(['driver' => extension_loaded('gd') ? 'gd' : 'imagick']);
        $img = $mgr->canvas(240,360,'#efe3c8');
        $f = $tmpDir . '/intervention_test2.png';
        $img->save($f);
        $artifacts[] = $f;
        echo '<div class="card"><h2>6) Intervention Image</h2><p class="ok">✅ ImageManager funguje — '.btn(basename($f), fileRel($f)).'</p></div>';
    } else {
        echo '<div class="card"><h2>6) Intervention Image</h2><p class="bad">❌ Intervention Image nenájdený (libs/intervention-image).</p></div>';
    }
} catch (Throwable $e) {
    echo '<div class="card"><h2>6) Intervention Image</h2><pre>'.esc($e->getMessage()).'</pre></div>';
}

// ------------------ PhpSpreadsheet ------------------
try {
    if (class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
        $spread = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spread->getActiveSheet();
        $sheet->setCellValue('A1','Test');
        $sheet->setCellValue('A2','Knihy od Autorov');
        $xlsx = $tmpDir . '/phpspread_test.xlsx';
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spread);
        $writer->save($xlsx);
        $artifacts[] = $xlsx;
        echo '<div class="card"><h2>7) PhpSpreadsheet</h2><p class="ok">✅ PhpSpreadsheet export OK — '.btn(basename($xlsx), fileRel($xlsx)).'</p></div>';
    } else {
        echo '<div class="card"><h2>7) PhpSpreadsheet</h2><p class="bad">❌ PhpSpreadsheet (PhpOffice) chýba.</p></div>';
    }
} catch (Throwable $e) {
    echo '<div class="card"><h2>7) PhpSpreadsheet</h2><pre>'.esc($e->getMessage()).'</pre></div>';
}

// ------------------ myclabs DeepCopy ------------------
try {
    if (class_exists('\DeepCopy\DeepCopy')) {
        $dc = new \DeepCopy\DeepCopy();
        $o = new stdClass(); $o->a = [1,2]; $c = $dc->copy($o);
        echo '<div class="card"><h2>8) myclabs DeepCopy</h2><p class="ok">✅ DeepCopy funguje.</p></div>';
    } else {
        echo '<div class="card"><h2>8) myclabs DeepCopy</h2><p class="bad">❌ myclabs DeepCopy chýba.</p></div>';
    }
} catch (Throwable $e) {
    echo '<div class="card"><h2>8) myclabs DeepCopy</h2><pre>'.esc($e->getMessage()).'</pre></div>';
}

// ------------------ random_bytes / paragonie ------------------
try {
    if (function_exists('random_bytes')) {
        $r = bin2hex(random_bytes(8));
        echo '<div class="card"><h2>9) random_bytes</h2><p class="ok">✅ random_bytes dostupné — ' . esc($r) . '</p></div>';
    } else {
        echo '<div class="card"><h2>9) random_bytes</h2><p class="bad">❌ random_bytes nie je dostupné (paragonie/random_compat chýba alebo PHP starý).</p></div>';
    }
} catch (Throwable $e) {
    echo '<div class="card"><h2>9) random_bytes</h2><pre>'.esc($e->getMessage()).'</pre></div>';
}

// ------------------ HTMLPurifier ------------------
try {
    if (class_exists('\HTMLPurifier')) {
        $config = \HTMLPurifier_Config::createDefault();
        $purifier = new \HTMLPurifier($config);
        $clean = $purifier->purify('<b>Test</b><script>alert(1)</script>');
        echo '<div class="card"><h2>10) HTMLPurifier</h2><p class="ok">✅ HTMLPurifier dostupný — výstup: '.esc($clean).'</p></div>';
    } else {
        echo '<div class="card"><h2>10) HTMLPurifier</h2><p class="bad">❌ HTMLPurifier sa nenašiel.</p></div>';
    }
} catch (Throwable $e) {
    echo '<div class="card"><h2>10) HTMLPurifier</h2><pre>'.esc($e->getMessage()).'</pre></div>';
}

// ------------------ PHPMailer + SMTP test (ak config existuje) ------------------
$smtpCfgPath = __DIR__ . '/../../db/config/configsmtp.php';
try {
    if (file_exists($smtpCfgPath)) {
        $smtpCfg = require $smtpCfgPath; // očakáva pole ['host'=>..., 'port'=>..., 'user'=>..., 'pass'=>..., 'secure'=>...]
        echo '<div class="card"><h2>11) SMTP / PHPMailer</h2>';
        if (!is_array($smtpCfg)) {
            echo '<p class="bad">❌ Konfig smtp nevrátil pole. Oprav ' . esc($smtpCfgPath) . '</p></div>';
        } else {
            $host = $smtpCfg['host'] ?? '';
            $port = (int)($smtpCfg['port'] ?? 25);
            echo '<p>Konfig: host='.esc($host).' port='.esc((string)$port).'</p>';
            // jednoduchý TCP test (nesnažíme sa overiť prihlasovanie) - otvoríme socket
            $conn = @fsockopen($host, $port, $errno, $errstr, 5);
            if ($conn) {
                stream_set_timeout($conn, 5);
                $greeting = fgets($conn, 512);
                @fclose($conn);
                echo '<p class="ok">✅ TCP na ' . esc($host) . ':' . esc((string)$port) . ' otvorený — odpoveď: '.esc(substr($greeting,0,200)).'</p>';
            } else {
                echo '<p class="bad">❌ TCP spojenie na ' . esc($host) . ':' . esc((string)$port) . ' zlyhalo — ' . esc($errstr . ' (' . $errno . ')') . '</p>';
            }
            // PHPMailer test (len ak knižnica prítomná) - len vytvorenie inštancie (neodosiela)
            if (class_exists('\PHPMailer\PHPMailer\PHPMailer') || class_exists('PHPMailer')) {
                echo '<p class="ok">PHPMailer prítomný.</p>';
            } else {
                echo '<p class="bad">PHPMailer nie je prítomný v /libs (nepovinné, odporúčam ho pridať pre smtp mailing).</p>';
            }
            echo '</div>';
        }
    } else {
        echo '<div class="card"><h2>11) SMTP</h2><p class="muted">Nepodarilo sa nájsť ' . esc($smtpCfgPath) . ' — ak chceš testovať SMTP, vytvor config súbor s parametrami host/port/user/pass/secure.</p></div>';
    }
} catch (Throwable $e) {
    echo '<div class="card"><h2>11) SMTP</h2><pre>'.esc($e->getMessage()).'</pre></div>';
}

// ------------------ mPDF (PDF + vložený QR) ------------------
try {
    if (class_exists('\Mpdf\Mpdf')) {
        $tmpPdf = $tmpDir . '/mpdf_test.pdf';
        // priprav QR base64 ak existuje
        $qrFile = $tmpDir . '/qrcode_test.png';
        $qrBase = (file_exists($qrFile) ? base64_encode(file_get_contents($qrFile)) : '');
        // vytvor mpdf (tempDir nastaviteľný)
        $mpdfTmp = $tmpDir . '/mpdf_tmp';
        mkpath($mpdfTmp);
        $mpdf = new \Mpdf\Mpdf(['tempDir' => $mpdfTmp, 'mode'=>'utf-8']);
        $html = '<div style="font-family: sans-serif; padding:20px">';
        $html .= '<h1>Test mPDF — Knihy od Autorov</h1>';
        $html .= '<p>Ak toto vidíš, mPDF funguje.</p>';
        if ($qrBase) {
            $html .= '<p><img src="data:image/png;base64,' . $qrBase . '" width="120" alt="qr"/></p>';
        }
        $html .= '</div>';
        $mpdf->WriteHTML($html);
        $mpdf->Output($tmpPdf, \Mpdf\Output\Destination::FILE);
        if (file_exists($tmpPdf) && filesize($tmpPdf) > 200) {
            $artifacts[] = $tmpPdf;
            echo '<div class="card"><h2>12) mPDF</h2><p class="ok">✅ mPDF v poriadku — '.btn(basename($tmpPdf), fileRel($tmpPdf)).'</p></div>';
        } else {
            echo '<div class="card"><h2>12) mPDF</h2><p class="bad">❌ mPDF neskončil úspešne (skontroluj závislosti psr/http-message, psr/log, setasign/fpdi atď.).</p></div>';
        }
    } else {
        echo '<div class="card"><h2>12) mPDF</h2><p class="bad">❌ mPDF trieda Mpdf nie je k dispozícii.</p></div>';
    }
} catch (Throwable $e) {
    echo '<div class="card"><h2>12) mPDF</h2><pre>'.esc($e->getMessage()).'</pre></div>';
}

// ------------------ Zhrnutie artefaktov ------------------
if (!empty($artifacts)) {
    echo '<div class="card"><h2>Vygenerované artefakty</h2><p class="small">Klikni pre otvorenie / stiahnutie.</p>';
    foreach ($artifacts as $a) {
        echo btn(basename($a), fileRel($a)) . ' ';
    }
    echo '</div>';
}

// ------------------ Dodatkové odporúčania ------------------
?>
  <div class="card">
    <h2>Zhrnutie & odporúčania</h2>
    <ul>
      <li>Ak niektorý test zlyhal, prečítaj chybový výpis a doplň chýbajúce knižnice do <code>/libs</code>.</li>
      <li>Pre mPDF musíš mať kompletnú distribúciu vrátane závislostí (psr/http-message, psr/log, setasign/fpdi, mpdf/psr-http-message-shim, mpdf/psr-log-aware-trait a ďalšie). Odporúčam stiahnuť release z <code>https://github.com/mpdf/mpdf/releases</code> a doplniť závislosti podľa composer.json.</li>
      <li>Intervention Image — použij verziu s ImageManagerStatic (2.x) alebo uprav kód na inštančnú fasádu.</li>
      <li>Pre SMTP test vytvor <code>/db/config/configsmtp.php</code> v tvare <code>&lt;?php return ['host'=>'smtp.example','port'=>587,'user'=>'u','pass'=>'p','secure'=>'tls'];</code></li>
      <li>Keď budeš chcieť, pripravím konkrétny ZIP s potrebnými knižnicami (mpdf+deps, setasign fpdi, psr packages, phpmailer, phpqrcode, phpspreadsheet), aby si ich len uploadol do <code>/libs</code>.</li>
    </ul>
  </div>

</div>
</body>
</html>
<?php
declare(strict_types=1);

/**
 * isbn_ui_download.php
 *
 * - If accessed without ?generate=1: show simple UI to enter ISBN and a "Generuj" button.
 * - If accessed with ?generate=1&isbn=...: validate/convert ISBN -> EAN13, generate PNG barcode
 *   with nicely positioned human-readable digits using a TTF font, and force download.
 *
 * Requirements: PHP with GD (gd2) and FreeType support (imagettftext). If TTF not available,
 * fallback to imagestring (less pretty).
 *
 * Put a TTF (e.g. DejaVuSans.ttf) in same directory or set $ttf to a valid path.
 */

/* -------------------------
   Configuration
   ------------------------- */
$ttf = __DIR__ . '/DejaVuSans.ttf'; // change if needed
$canvasWidth = 340; // default PNG width (bigger -> better for printing)
$canvasHeight = 180; // PNG height

/* -------------------------
   Helpers: isbn normalization + checksum
   ------------------------- */
function clean_isbn(string $s): string {
    return preg_replace('/[^0-9Xx]/', '', $s) ?? '';
}

function ean13_compute_checkdigit(string $digits12): string {
    $digits = str_split($digits12);
    $sum = 0;
    foreach ($digits as $i => $dChar) {
        $d = intval($dChar);
        $pos = $i + 1;
        $sum += ($pos % 2 === 1) ? $d : 3 * $d;
    }
    $mod = $sum % 10;
    $check = (10 - $mod) % 10;
    return (string)$check;
}

function isbn10_to_isbn13(string $isbn10): string {
    $core = substr($isbn10, 0, 9);
    $prefixed = '978' . $core;
    $check = ean13_compute_checkdigit($prefixed);
    return $prefixed . $check;
}

function normalize_isbn_to_ean13(string $raw): string {
    $clean = clean_isbn($raw);
    if ($clean === '') {
        throw new InvalidArgumentException('Prázdné ISBN.');
    }
    if (strlen($clean) === 10) {
        return isbn10_to_isbn13($clean);
    } elseif (strlen($clean) === 13) {
        $first12 = substr($clean, 0, 12);
        $expected = ean13_compute_checkdigit($first12);
        if ($expected !== substr($clean, 12, 1)) {
            throw new InvalidArgumentException('ISBN-13 má špatný kontrolní znak.');
        }
        return $clean;
    } else {
        throw new InvalidArgumentException('ISBN musí mít 10 nebo 13 znaků (po vyčištění).');
    }
}

/* -------------------------
   EAN-13 encoding tables (same jako standard)
   ------------------------- */
$L_codes = [
    '0'=>'0001101','1'=>'0011001','2'=>'0010011','3'=>'0111101','4'=>'0100011',
    '5'=>'0110001','6'=>'0101111','7'=>'0111011','8'=>'0110111','9'=>'0001011'
];
$G_codes = [
    '0'=>'0100111','1'=>'0110011','2'=>'0011011','3'=>'0100001','4'=>'0011101',
    '5'=>'0111001','6'=>'0000101','7'=>'0010001','8'=>'0001001','9'=>'0010111'
];
$R_codes = [
    '0'=>'1110010','1'=>'1100110','2'=>'1101100','3'=>'1000010','4'=>'1011100',
    '5'=>'1001110','6'=>'1010000','7'=>'1000100','8'=>'1001000','9'=>'1110100'
];
$parity = [
    '0'=>['L','L','L','L','L','L'],
    '1'=>['L','L','G','L','G','G'],
    '2'=>['L','L','G','G','L','G'],
    '3'=>['L','L','G','G','G','L'],
    '4'=>['L','G','L','L','G','G'],
    '5'=>['L','G','G','L','L','G'],
    '6'=>['L','G','G','G','L','L'],
    '7'=>['L','G','L','G','L','G'],
    '8'=>['L','G','L','G','G','L'],
    '9'=>['L','G','G','L','G','L']
];

function ean13_bitstring(string $ean13) : string {
    global $L_codes, $G_codes, $R_codes, $parity;
    $digits = str_split($ean13);
    $first = $digits[0];
    $left6 = array_slice($digits, 1, 6);
    $right6 = array_slice($digits, 7, 6);
    $bits = '101';
    $pattern = $parity[$first];
    for ($i = 0; $i < 6; $i++) {
        $d = $left6[$i];
        $bits .= ($pattern[$i] === 'L') ? $L_codes[$d] : $G_codes[$d];
    }
    $bits .= '01010';
    for ($i = 0; $i < 6; $i++) {
        $d = $right6[$i];
        $bits .= $R_codes[$d];
    }
    $bits .= '101';
    return $bits;
}

/* -------------------------
   Render PNG (returns binary)
   - uses TTF if available for consistent numeric layout
   - positions digits under exact module-centres (each digit == 7 modules)
   - ADJUSTED: reserve space for top "ISBN: ..." text so it won't be clipped
   ------------------------- */
function build_ean13_png_binary(string $ean13, int $width, int $height, ?string $ttf = null, ?string $topText = null, float $barCompression = 0.92): string {
    // $barCompression: 1.0 = plná šířka (původní chování), <1.0 = pruhy bližší (kompaktnější)
    $modules = 95;
    $quiet_modules = 10;

    // použij původní usable width, ale aplikuj kompresní faktor
    $usable_width_full = $width - (2 * $quiet_modules);
    $usable_width = max(1, (int) floor($usable_width_full * $barCompression));

    // rozdělení pixelů mezi moduly (rovnoměrné + rozdělení zbytku)
    $base = intdiv($usable_width, $modules);
    if ($base < 1) $base = 1;
    $extra = $usable_width % $modules;
    $moduleWidths = array_fill(0, $modules, $base);
    for ($i = 0; $i < $extra; $i++) {
        $moduleWidths[$i] += 1;
    }
    $total_barcode_width = array_sum($moduleWidths);
    $margin = (int) round(($width - $total_barcode_width) / 2);

    $bits = ean13_bitstring($ean13);

    // obraz
    $img = imagecreatetruecolor($width, $height);
    $white = imagecolorallocate($img, 255,255,255);
    $black = imagecolorallocate($img, 0,0,0);
    imagefilledrectangle($img, 0,0,$width,$height,$white);

    // Font metrics
    $useTTF = ($ttf !== null && file_exists($ttf) && function_exists('imagettftext'));
    $fontSizePx = max(12, (int) round(($total_barcode_width / $modules) * 4)); // upraveno dle skutečného modulu
    $smallFont = max(10, (int) round($fontSizePx * 0.6));

    // top_text fallback
    if ($topText !== null && trim($topText) !== '') {
        $top_text = 'ISBN: ' . trim($topText);
    } else {
        $top_text = 'ISBN: ' . substr($ean13, 0, 1) . '-' . substr($ean13, 1, 6) . '-' . substr($ean13, 7, 6);
    }

    // spočítáme výšku top textu (pro rezervu)
    $topTextHeight = 0;
    if ($useTTF) {
        $bbox2 = imagettfbbox($smallFont, 0, $ttf, $top_text);
        $topTextHeight = abs($bbox2[5] - $bbox2[1]) + 4;
    } else {
        $topTextHeight = 12 + 4;
    }

    $bar_top = max((int) round($height * 0.07), $topTextHeight + 8);
    $bar_height = (int) round($height * 0.66);
    $guard_ext = (int) round($height * 0.08);

    // vypneme antialiasing/interlace pokud dostupné
    if (function_exists('imageantialias')) imageantialias($img, false);
    if (function_exists('imageinterlace')) imageinterlace($img, null);

    // kumulativní šířky pro mapování indexů modulů na pix pozici
    $cum = [0];
    for ($i = 0; $i < $modules; $i++) $cum[$i+1] = $cum[$i] + $moduleWidths[$i];

    // kreslení pruhů
    $x = $margin;
    $len = strlen($bits);
    $guard_ranges = [
        [0,2],
        [45,49],
        [92,94]
    ];

    for ($i = 0; $i < $len; $i++) {
        $bit = $bits[$i];
        $isGuard = false;
        foreach ($guard_ranges as list($s,$e)) { if ($i >= $s && $i <= $e) { $isGuard = true; break; } }
        $wModule = $moduleWidths[$i];
        $curBarH = $bar_height + ($isGuard ? $guard_ext : 0);
        if ($bit === '1') {
            imagefilledrectangle($img, $x, $bar_top, $x + $wModule - 1, $bar_top + $curBarH - 1, $black);
        }
        $x += $wModule;
    }

    // Helper: center X pro (floating) index modulů — použijeme kumulativní šířky
    $getCenterXForModuleIndex = function(float $moduleIndex) use ($margin, $moduleWidths, $cum) {
        $leftIdx = (int) floor($moduleIndex);
        $frac = $moduleIndex - $leftIdx;
        $baseX = $margin + ($leftIdx >= 0 ? $cum[$leftIdx] : 0);
        $nextWidth = ($leftIdx < count($moduleWidths)) ? $moduleWidths[$leftIdx] : 0;
        return $baseX + $frac * $nextWidth;
    };

    // Human-readable digits (TTF)
    if ($useTTF) {
        $color = $black;

        // velikosti a baseline pro hlavní číslice (přepočteno)
        $bboxSample = imagettfbbox($fontSizePx, 0, $ttf, '0');
        $fontHeight = abs($bboxSample[5] - $bboxSample[1]);
        $available = $height - ($bar_top + $bar_height);
        $text_margin = max(6, (int) round($available * 0.45));
        $y_text_baseline = $bar_top + $bar_height + $text_margin + (int) round($fontHeight / 2);

        // First digit (ponechané vlevo)
        $first_center_x = $margin - (7 * ($total_barcode_width / $modules)) / 2;
        $d = $ean13[0];
        $bbox = imagettfbbox($fontSizePx, 0, $ttf, $d);
        $w = abs($bbox[4] - $bbox[0]);
        $x_pos = (int) round($first_center_x - ($w / 2));
        imagettftext($img, $fontSizePx, 0, $x_pos, $y_text_baseline, $color, $ttf, $d);

        // left 6 digits
        $left_start_module = 3;
        for ($i = 0; $i < 6; $i++) {
            $digit = $ean13[1 + $i];
            $moduleCenterIndex = $left_start_module + $i * 7 + 3.5;
            $center = $getCenterXForModuleIndex($moduleCenterIndex);
            $bbox = imagettfbbox($fontSizePx, 0, $ttf, $digit);
            $w = abs($bbox[4] - $bbox[0]);
            $x_pos = (int) round($center - ($w / 2));
            imagettftext($img, $fontSizePx, 0, $x_pos, $y_text_baseline, $color, $ttf, $digit);
        }

        // right 6 digits
        $right_start_module = 3 + 42 + 5;
        for ($i = 0; $i < 6; $i++) {
            $digit = $ean13[7 + $i];
            $moduleCenterIndex = $right_start_module + $i * 7 + 3.5;
            $center = $getCenterXForModuleIndex($moduleCenterIndex);
            $bbox = imagettfbbox($fontSizePx, 0, $ttf, $digit);
            $w = abs($bbox[4] - $bbox[0]);
            $x_pos = (int) round($center - ($w / 2));
            imagettftext($img, $fontSizePx, 0, $x_pos, $y_text_baseline, $color, $ttf, $digit);
        }

        // --- Stretched top text: rovnoměrně rozmístit znaky přes šířku samotného barcode ---
        $chars = mb_strlen($top_text, 'UTF-8');
        $leftEdge = $margin;
        $rightEdge = $margin + $total_barcode_width;
        $span = $rightEdge - $leftEdge;
        // mírné zvětšení smallFont pro lepší čitelnost při roztažení
        $smallFontAdj = max(10, (int) round($smallFont * 1.05));
        $y2 = $bar_top - 6;

        for ($i = 0; $i < $chars; $i++) {
            $ch = mb_substr($top_text, $i, 1, 'UTF-8');
            // center pozice tohoto znaku
            $center = $leftEdge + ($i + 0.5) * ($span / $chars);
            $bboxCh = imagettfbbox($smallFontAdj, 0, $ttf, $ch);
            $wCh = abs($bboxCh[4] - $bboxCh[0]);
            $xCh = (int) round($center - ($wCh / 2));
            imagettftext($img, $smallFontAdj, 0, $xCh, $y2, $color, $ttf, $ch);
        }

    } else {
        // fallback imagestring (mírně zkrácené, bez roztažení)
        $font = 3;
        $text_y = $bar_top + $bar_height + 6;
        imagestring($img, $font, max(2, $margin - 14), $text_y, $ean13[0], $black);
        $left_text = substr($ean13, 1, 6);
        $left_x = $margin + $moduleWidths[0] * 3;
        imagestring($img, $font, $left_x, $text_y, $left_text, $black);
        $right_text = substr($ean13, 7, 6);
        $right_x = $margin + $total_barcode_width - (strlen($right_text) * 6) - 6;
        imagestring($img, $font, $right_x, $text_y, $right_text, $black);

        // top text center (simple)
        $top_x = (int) round(($width - (strlen($top_text) * 6)) / 2);
        $top_y = max(4, $bar_top - 6);
        imagestring($img, $font, $top_x, $top_y - 10, $top_text, $black);
    }

    // output PNG buffer
    ob_start();
    imagepng($img);
    $png = ob_get_clean();
    imagedestroy($img);
    return $png;
}

/* -------------------------
   UI (HTML) when not generating
   ------------------------- */
function render_html_ui(string $currentIsbn = ''): void {
    $self = htmlspecialchars((string)$_SERVER['PHP_SELF'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $currentIsbnEsc = htmlspecialchars($currentIsbn, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $tpl = <<<'HTML'
<!doctype html>
<html lang="cs">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Generátor ISBN čárového kódu</title>
<style>
 body{font-family: system-ui,Segoe UI,Roboto,Arial; padding:24px; background:#f7f8fb}
 .card{max-width:780px;margin:0 auto;background:white;padding:18px;border-radius:8px;box-shadow:0 6px 18px rgba(10,10,20,0.06)}
 label{display:block;margin-bottom:8px;font-weight:600}
 input[type="text"]{width:100%;padding:10px 12px;font-size:16px;border:1px solid #ddd;border-radius:6px}
 .row{display:flex;gap:12px;margin-top:12px}
 button{background:#0b74ff;color:white;border:none;padding:10px 16px;border-radius:8px;font-weight:600;cursor:pointer}
 .hint{color:#666;margin-top:8px}
 .preview{margin-top:18px;text-align:center}
</style>
</head>
<body>
<div class="card">
<h2>Generátor ISBN → EAN-13 čiarový kód</h2>
<p>
  Zadajte ISBN <strong>10 alebo 13 číslic s pomlčkami</strong> (napr. <code>978-80-269-8827-4</code>).
  Po kliknutí na „Generuj“ sa obrázok stiahne.
</p>
<p class="hint">
  Program automaticky odstraňuje zadané medzery a písmeno <strong>O</strong> nahrádza číslom <strong>0</strong>.
</p>
  <form id="frm" action="__ACTION__" method="get" target="_self">
    <input type="hidden" name="generate" value="1">
    <label for="isbn">ISBN</label>
    <input id="isbn" name="isbn" type="text" placeholder="napr. 978-80-269-8827-4" value="__VALUE__">
    <div class="row">
      <button type="submit" id="go">Generuj a stáhni PNG</button>
      <button type="button" id="previewBtn">Náhled v nové záložce</button>
    </div>
  </form>

  <div class="preview">
    <img id="previewImg" alt="náhled bude zde" style="max-width:100%;border:1px solid #eee;padding:8px;background:#fff;margin-top:12px;display:none"/>
  </div>
</div>

<script>
(function(){
  var previewBtn = document.getElementById('previewBtn');
  var previewImg = document.getElementById('previewImg');
  var isbnInput  = document.getElementById('isbn');

  previewBtn.addEventListener('click', function(){
    var isbn = isbnInput.value.trim();
    if (!isbn) { alert('Zadej ISBN.'); return; }
    // použijeme string concatenation místo template literals, aby PHP nemal šancu zasahovať
    var url = location.pathname + '?generate=1&isbn=' + encodeURIComponent(isbn) + '&inline=1';
    window.open(url, '_blank');
  });

  // live preview (inline=1)
  isbnInput.addEventListener('change', updatePreview);
  isbnInput.addEventListener('input', function(){
    if (window.__deb) clearTimeout(window.__deb);
    window.__deb = setTimeout(updatePreview, 450);
  });

// odstraní jen mezery a nahradí písmeno O/o za číslo 0
function sanitizeIsbnInput(s) {
  if (!s) return '';
  return s
    .replace(/ /g, '')      // vyhodit obyčejné mezery
    .replace(/[Oo]/g, '0'); // velké i malé O nahradit nulou
}

function sanitizeAndUpdate() {
  var old = isbnInput.value;
  var cleaned = sanitizeIsbnInput(old);
  if (cleaned !== old) {
    isbnInput.value = cleaned;
  }
  if (typeof updatePreview === 'function') updatePreview();
}

// při paste
isbnInput.addEventListener('paste', function(e){
  try {
    var txt = (e.clipboardData || window.clipboardData).getData('text');
    if (!txt) return;
    e.preventDefault();
    var san = sanitizeIsbnInput(txt);
    var start = isbnInput.selectionStart;
    var end = isbnInput.selectionEnd;
    var val = isbnInput.value;
    isbnInput.value = val.slice(0, start) + san + val.slice(end);
    var pos = start + san.length;
    isbnInput.setSelectionRange(pos, pos);
    setTimeout(function(){ if (typeof updatePreview === 'function') updatePreview(); }, 0);
  } catch (err) {
    setTimeout(sanitizeAndUpdate, 20);
  }
});

isbnInput.addEventListener('blur', sanitizeAndUpdate);

isbnInput.addEventListener('input', function(){
  if (window.__isbnDeb) clearTimeout(window.__isbnDeb);
  window.__isbnDeb = setTimeout(sanitizeAndUpdate, 200);
});

  function updatePreview(){
    var isbn = isbnInput.value.trim();
    if (!isbn) { previewImg.style.display='none'; return; }
    var url = location.pathname + '?generate=1&isbn=' + encodeURIComponent(isbn) + '&inline=1&w=340&h=180';
    previewImg.src = url;
    previewImg.onload = function(){ previewImg.style.display='block'; };
    previewImg.onerror = function(){ previewImg.style.display='none'; };
  }
})();
</script>
</body>
</html>
HTML;

    // bezpečně nahradíme placeholders (bez priamej interpolácie v nowdoc)
    $html = str_replace(['__ACTION__','__VALUE__'], [$self, $currentIsbnEsc], $tpl);
    echo $html;
}

// -------------------------
// Entry point
// -------------------------
try {
    if (!isset($_GET['generate'])) {
        // show UI
        $current = isset($_GET['isbn']) ? htmlspecialchars($_GET['isbn']) : '';
        render_html_ui($current);
        exit;
    }

    if (!isset($_GET['isbn'])) {
        throw new InvalidArgumentException('Chybí parametr isbn.');
    }
    $raw = (string)$_GET['isbn'];
    $ean13 = normalize_isbn_to_ean13($raw);

    // allow optional size override from UI preview
    $w = isset($_GET['w']) ? (int)$_GET['w'] : $canvasWidth;
    $h = isset($_GET['h']) ? (int)$_GET['h'] : $canvasHeight;
    if ($w < 200) $w = 200;
    if ($h < 80) $h = 80;

    $topText = isset($raw) ? trim((string)$raw) : null;
    $png = build_ean13_png_binary($ean13, $w, $h, file_exists($ttf) ? $ttf : null, $topText);

    $filename = 'isbn_' . preg_replace('/[^0-9]/','',$ean13) . '.png';

    // If inline=1 -> display in browser (useful for preview). Otherwise force download.
    $inline = isset($_GET['inline']) && ($_GET['inline'] === '1' || $_GET['inline'] === 'true');

    if ($inline) {
        header('Content-Type: image/png');
        header('Content-Length: ' . strlen($png));
        echo $png;
        exit;
    } else {
        header('Content-Type: image/png');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Transfer-Encoding: binary');
        header('Content-Length: ' . strlen($png));
        if (ob_get_level()) ob_end_clean();
        echo $png;
        exit;
    }
} catch (Throwable $e) {
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Chyba: ' . $e->getMessage();
    exit(1);
}
<?php
declare(strict_types=1);
/**
 * /eshop/downloads.php
 *
 * Bezpečné servírovanie (stream) PDF súborov pre užívateľa identifikovaného download_tokenom.
 * - GET param: token (povinné)
 * - voliteľné GET param: book_id (ak je zadané, stiahne sa konkrétna kniha; inak zobrazí zoznam dostupných)
 *
 * Podporuje HTTP Range requests (resumable download) a streamovanie po častiach.
 */

require_once __DIR__ . '/_init.php';

$BOOKS_PDF_DIR = realpath(ESHOP_ROOT . '/../books-pdf'); // očakávané umiestnenie
if ($BOOKS_PDF_DIR === false) {
    eshop_log('ERROR', 'BOOKS_PDF_DIR nenájdené: ' . ESHOP_ROOT . '/../books-pdf');
    http_response_code(500);
    echo "Server error";
    exit;
}

// pomocné: bezpečné zavretie skriptu
function _deny(string $msg = 'Prístup odmietnutý', int $code = 403): void {
    http_response_code($code);
    echo htmlspecialchars($msg, ENT_QUOTES | ENT_HTML5);
    exit;
}

$token = trim((string)($_GET['token'] ?? ''));

if ($token === '') {
    _deny('Chýba token pre stiahnutie.', 400);
}

// nájdem užívateľa podľa tokenu
$pdoLocal = $GLOBALS['pdo'] ?? null;
if (!($pdoLocal instanceof PDO)) {
    eshop_log('ERROR', 'PDO nie je dostupné v downloads.php');
    _deny('Interná chyba', 500);
}

try {
    $stmt = $pdoLocal->prepare("SELECT id, meno, email FROM users WHERE download_token = ? LIMIT 1");
    $stmt->execute([$token]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        eshop_log('WARN', "Neplatný download token: {$token}");
        _deny('Neplatný alebo expirovaný token.', 403);
    }

    $userId = (int)$user['id'];

    // Vyzdvihneme všetky book_id z order_items pre orders patriace tomuto user_id
    $stmt = $pdoLocal->prepare("
        SELECT DISTINCT oi.book_id, b.pdf_file, b.nazov
        FROM order_items oi
        INNER JOIN orders o ON oi.order_id = o.id
        LEFT JOIN books b ON oi.book_id = b.id
        WHERE o.user_id = ?
    ");
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $available = [];
    foreach ($rows as $r) {
        if (empty($r['pdf_file'])) continue;
        $available[(int)$r['book_id']] = [
            'pdf_file' => $r['pdf_file'],
            'nazov' => $r['nazov'] ?? ('Kniha #' . (int)$r['book_id'])
        ];
    }

    if (empty($available)) {
        eshop_log('INFO', "Token má užívateľ {$userId}, ale nemá žiadne priradené PDF súbory.");
        _deny('Pre tento účet nie sú k dispozícii žiadne stiahnuteľné súbory.', 403);
    }

    // Ak nie je zadané book_id — zobrazíme zoznam
    $bookId = isset($_GET['book_id']) ? (int)$_GET['book_id'] : 0;
    if ($bookId === 0) {
        // Zobrazíme jednoduchý list s bezpečnými odkazmi (obsahujú token a book_id)
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $base = $scheme . '://' . rtrim($host, '/');
        ?>
        <!doctype html>
        <html lang="sk">
        <head>
          <meta charset="utf-8">
          <meta name="viewport" content="width=device-width,initial-scale=1">
          <title>Stahovanie — Knihy od Autorov</title>
          <link rel="stylesheet" href="/eshop/css/eshop.css">
          <style>
            .wrap { max-width:980px; margin:36px auto; padding:24px; background:var(--paper,#fff); border-radius:12px; }
            ul { list-style:none; padding:0; }
            li { padding:12px 0; border-bottom:1px solid #eee; }
            a.btn { display:inline-block; padding:8px 12px; border-radius:8px; background:var(--accent,#c08a2e); color:#fff; text-decoration:none; }
          </style>
        </head>
        <body>
          <div class="wrap paper-wrap">
            <h1>Stiahnuteľné súbory</h1>
            <p>Nižšie sú súbory dostupné pre tento token. Kliknutím sa spustí bezpečné stiahnutie.</p>
            <ul>
            <?php foreach ($available as $id => $meta): 
                $link = $base . '/eshop/downloads.php?token=' . rawurlencode($token) . '&book_id=' . $id;
            ?>
              <li>
                <strong><?php echo htmlspecialchars($meta['nazov'], ENT_QUOTES | ENT_HTML5); ?></strong>
                &nbsp; — &nbsp;
                <a class="btn" href="<?php echo htmlspecialchars($link, ENT_QUOTES | ENT_HTML5); ?>">Stiahnuť</a>
              </li>
            <?php endforeach; ?>
            </ul>
            <p style="margin-top:18px;"><a href="/eshop/index.php">Späť na stránky</a></p>
          </div>
        </body>
        </html>
        <?php
        exit;
    }

    // Overíme, či požadované book_id je v zozname povolených
    if (!isset($available[$bookId])) {
        eshop_log('WARN', "Užívateľ {$userId} sa pokúsil stiahnuť neautorizovanú knihu: {$bookId}");
        _deny('Nie ste oprávnený sťahovať tento súbor.', 403);
    }

    // Získame pdf_file hodnotu (uložená v DB)
    $pdfFile = $available[$bookId]['pdf_file'];

    // Bezpečné vyriešenie cesty:
    // Ak je uložený relatívny názov (napr. 'book_123.pdf' alebo 'sub/xx.pdf'), povolíme, ale overíme realpath v rámci BOOKS_PDF_DIR
    $requestedPath = $BOOKS_PDF_DIR . DIRECTORY_SEPARATOR . $pdfFile;
    $real = realpath($requestedPath);
    if ($real === false) {
        eshop_log('ERROR', "Súbor neexistuje: {$requestedPath}");
        _deny('Súbor nenájdený.', 404);
    }
    
    // bezpečnostná kontrola: realpath musí začínať BOOKS_PDF_DIR
    $len = strlen($BOOKS_PDF_DIR);
    if (strncmp($real, $BOOKS_PDF_DIR, $len) !== 0) {
        eshop_log('ERROR', "Path traversal pokus: {$requestedPath} -> {$real}");
        _deny('Neplatná cesta súboru.', 400);
    }

    // Overíme, že sa jedná o súbor a je čitateľný
    if (!is_file($real) || !is_readable($real)) {
        eshop_log('ERROR', "Súbor nie je prístupný: {$real}");
        _deny('Súbor nie je dostupný.', 404);
    }

    // Pripravíme hlavičky a streamovanie s podporou Range
    $filesize = filesize($real);
    $filename = basename($real);
    $finfoType = 'application/pdf';
    if (function_exists('mime_content_type')) {
        $mt = mime_content_type($real);
        if ($mt) $finfoType = $mt;
    }

    // Logovanie začiatku stahovania
    eshop_log('INFO', "Spúšťam stiahnutie file={$filename} user_id={$userId} book_id={$bookId}");

    // Podpora range
    $start = 0;
    $end = $filesize - 1;
    $length = $filesize;
    $httpStatus = 200;

    if (isset($_SERVER['HTTP_RANGE'])) {
        // parse range header
        // formát: bytes=start-end
        if (preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $matches)) {
            $rangeStart = $matches[1] !== '' ? (int)$matches[1] : null;
            $rangeEnd = $matches[2] !== '' ? (int)$matches[2] : null;
            if ($rangeStart !== null && $rangeEnd !== null) {
                $start = $rangeStart;
                $end = $rangeEnd;
            } elseif ($rangeStart !== null && $rangeEnd === null) {
                $start = $rangeStart;
                $end = $filesize - 1;
            } elseif ($rangeStart === null && $rangeEnd !== null) {
                // suffix-range: last N bytes
                $start = max(0, $filesize - $rangeEnd);
                $end = $filesize - 1;
            }
            if ($start > $end || $start < 0 || $end >= $filesize) {
                header('HTTP/1.1 416 Requested Range Not Satisfiable');
                header("Content-Range: bytes */{$filesize}");
                exit;
            }
            $length = $end - $start + 1;
            $httpStatus = 206;
        }
    }

    // Odošleme hlavičky
    if ($httpStatus === 206) {
        header('HTTP/1.1 206 Partial Content');
    } else {
        header('HTTP/1.1 200 OK');
    }
    header('Content-Type: ' . $finfoType);
    header('Content-Length: ' . $length);
    header('Accept-Ranges: bytes');
    if ($httpStatus === 206) {
        header("Content-Range: bytes {$start}-{$end}/{$filesize}");
    }
    // Force download / attachment
    $dispositionName = str_replace('"', '', $available[$bookId]['nazov']);
    // try to create safe filename for download
    $downloadFilename = preg_replace('/[^\w\-\.\s]/u', '_', $dispositionName) . '.pdf';
    header('Content-Disposition: attachment; filename="' . $downloadFilename . '"');

    // vypneme output buffering
    if (ob_get_level()) {
        ob_end_clean();
    }
    // zvýšime časový limit pre veľké súbory
    @set_time_limit(0);
    @ignore_user_abort(true);

    $chunkSize = 1024 * 1024; // 1MB na čítanie
    $bytesSent = 0;

    $fp = fopen($real, 'rb');
    if ($fp === false) {
        eshop_log('ERROR', "Nepodarilo sa otvoriť súbor pre čítanie: {$real}");
        _deny('Chyba pri čítaní súboru.', 500);
    }

    // posunieme sa na začiatok rozsahu
    fseek($fp, $start);

    while (!feof($fp) && $bytesSent < $length) {
        $read = min($chunkSize, $length - $bytesSent);
        $data = fread($fp, $read);
        if ($data === false) break;
        echo $data;
        flush();
        $bytesSent += strlen($data);
        // bezpečnostná ochrana: ak klient odpojil, ukončíme
        if (connection_status() !== CONNECTION_NORMAL) {
            break;
        }
    }

    fclose($fp);
    // Logovanie úspešného/ukončeného stiahnutia
    eshop_log('INFO', "Dokončené stiahnutie file={$filename} user_id={$userId} book_id={$bookId} bytes={$bytesSent}");

    exit;

} catch (Throwable $e) {
    eshop_log('ERROR', 'Chyba v downloads.php: ' . $e->getMessage());
    _deny('Interná chyba pri spracovaní stiahnutia.', 500);
}
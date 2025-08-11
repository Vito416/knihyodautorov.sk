<?php
/**
 * initdb.php - rozšířená a bezpečnější inicializace DB + základné nastavenia pre e-shop
 *
 * Uložiť do rootu vedľa index.php. Konfig: db/config/config.php (musí vracať PDO alebo nastaviť $pdo).
 * Po úspešnej inicializácii odporúčam súbor odstrániť alebo presunúť mimo webroot.
 *
 * Vytvára (ak neexistujú): nové stĺpce v users, tabuľku invoices, nastavenia v settings,
 * priečinky books-img, books-pdf, eshop/invoices a vzorový db/config/configsmtp.php.
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

function esc($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function slugify($text) {
    $text = (string)$text;
    if (function_exists('iconv')) {
        $trans = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if ($trans !== false) $text = $trans;
    }
    $text = mb_strtolower($text, 'UTF-8');
    $text = preg_replace('/[^a-z0-9\s-]/', '', $text);
    $text = preg_replace('/[\s-]+/', ' ', $text);
    $text = trim($text);
    $text = str_replace(' ', '-', $text);
    return $text;
}

/* ---------- načtení PDO z db/config/config.php ---------- */
$configPath = __DIR__ . '/db/config/config.php';
if (!file_exists($configPath)) {
    die("<h2>Chyba</h2><p>Konfigurační soubor <code>db/config/config.php</code> nebyl nalezen. Vytvořte ho a nechte ho vracet PDO.</p>");
}

$maybePdo = require $configPath;
if ($maybePdo instanceof PDO) {
    $pdo = $maybePdo;
} elseif (isset($pdo) && $pdo instanceof PDO) {
    // ok
} else {
    die("<h2>Chyba</h2><p>Soubor <code>db/config/config.php</code> nevrátil PDO. Upravte ho tak, aby vracel PDO nebo nastavoval \$pdo.</p>");
}

/* ---------- UI před inicializací ---------- */
if (!isset($_GET['init'])) {
    echo "<!doctype html><html lang='sk'><head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'>";
    echo "<title>Inicializácia DB — e-shop</title>";
    echo "<style>body{font-family:system-ui,Segoe UI,Roboto,Arial;background:#f7f6f4;color:#111;margin:30px} .card{max-width:980px;margin:0 auto;background:#fff;padding:22px;border-radius:10px;box-shadow:0 10px 30px rgba(0,0,0,.06)} .btn{display:inline-block;background:#6a4518;color:#fff;padding:12px 18px;border-radius:10px;text-decoration:none;font-weight:700}</style></head><body>";
    echo "<div class='card'><h1>Inicializácia databázy — e-shop (PDF knihy)</h1>";
    echo "<p>Pripojenie k databáze prebehlo <strong>úspešne</strong>. Skript vytvorí potrebné tabuľky/stĺpce a vloží základné nastavenia.</p>";
    echo "<p><strong>Bezpečnosť:</strong> po dokončení okamžite smažte alebo presuňte tento súbor mimo webroot.</p>";
    echo "<p>Konfig: <code>" . esc($configPath) . "</code></p>";
    echo "<p><a class='btn' href='?init=1'>Inicializovať databázu teraz</a></p></div></body></html>";
    exit;
}

/* ---------- vykonanie inicializácie (idempotentné) ---------- */
$actions = [];
try {
    // 0) Základ: nastavíme správne charset a engine pro správy
    $pdo->exec("SET NAMES utf8mb4");

    // 1) CREATE základné tabuľky (ak neexistujú) - použijeme IF NOT EXISTS pre idempotenciu
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS users (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      meno VARCHAR(150) NOT NULL,
      email VARCHAR(255) NOT NULL UNIQUE,
      heslo VARCHAR(255) NOT NULL,
      telefon VARCHAR(50) DEFAULT NULL,
      adresa TEXT DEFAULT NULL,
      datum_registracie DATETIME DEFAULT CURRENT_TIMESTAMP,
      -- nové stĺpce pre eshop / účty
      newsletter TINYINT(1) DEFAULT 0,
      email_verified TINYINT(1) DEFAULT 0,
      verify_token VARCHAR(255) DEFAULT NULL,
      reset_token VARCHAR(255) DEFAULT NULL,
      download_token VARCHAR(255) DEFAULT NULL,
      last_login DATETIME DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    $actions[] = "Tabuľka users: OK (vytvorená / existuje)";

    $pdo->exec("
    CREATE TABLE IF NOT EXISTS authors (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      meno VARCHAR(255) NOT NULL,
      slug VARCHAR(255) NOT NULL,
      bio TEXT DEFAULT NULL,
      foto VARCHAR(255) DEFAULT NULL,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY (slug)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    $actions[] = "Tabuľka authors: OK";

    $pdo->exec("
    CREATE TABLE IF NOT EXISTS categories (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      nazov VARCHAR(150) NOT NULL,
      slug VARCHAR(150) NOT NULL,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY (slug)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    $actions[] = "Tabuľka categories: OK";

    $pdo->exec("
    CREATE TABLE IF NOT EXISTS books (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      nazov VARCHAR(255) NOT NULL,
      slug VARCHAR(255) NOT NULL,
      popis TEXT DEFAULT NULL,
      cena DECIMAL(10,2) NOT NULL DEFAULT 0.00,
      mena CHAR(3) NOT NULL DEFAULT 'EUR',
      pdf_file VARCHAR(255) DEFAULT NULL,
      obrazok VARCHAR(255) DEFAULT NULL,
      author_id INT UNSIGNED DEFAULT NULL,
      category_id INT UNSIGNED DEFAULT NULL,
      is_active TINYINT(1) DEFAULT 1,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
      FULLTEXT KEY ft_title_popis (nazov, popis),
      UNIQUE KEY (slug),
      INDEX idx_author (author_id),
      INDEX idx_category (category_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    $actions[] = "Tabuľka books: OK";

    $pdo->exec("
    CREATE TABLE IF NOT EXISTS orders (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      user_id INT UNSIGNED NOT NULL,
      total_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
      currency CHAR(3) DEFAULT 'EUR',
      status ENUM('pending','paid','cancelled','refunded','fulfilled') DEFAULT 'pending',
      payment_method VARCHAR(100) DEFAULT NULL,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_user (user_id),
      INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    $actions[] = "Tabuľka orders: OK";

    $pdo->exec("
    CREATE TABLE IF NOT EXISTS order_items (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      order_id INT UNSIGNED NOT NULL,
      book_id INT UNSIGNED NOT NULL,
      quantity INT NOT NULL DEFAULT 1,
      unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_order (order_id),
      INDEX idx_book (book_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    $actions[] = "Tabuľka order_items: OK";

    $pdo->exec("
    CREATE TABLE IF NOT EXISTS reviews (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      book_id INT UNSIGNED NOT NULL,
      user_id INT UNSIGNED NOT NULL,
      rating TINYINT NOT NULL,
      comment TEXT,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_book (book_id),
      INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    $actions[] = "Tabuľka reviews: OK";

    $pdo->exec("
    CREATE TABLE IF NOT EXISTS admin_users (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      username VARCHAR(100) NOT NULL UNIQUE,
      email VARCHAR(255) DEFAULT NULL,
      password VARCHAR(255) NOT NULL,
      role VARCHAR(50) DEFAULT 'manager',
      is_active TINYINT(1) DEFAULT 1,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    $actions[] = "Tabuľka admin_users: OK";

    $pdo->exec("
    CREATE TABLE IF NOT EXISTS settings (
      k VARCHAR(100) NOT NULL PRIMARY KEY,
      v TEXT DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    $actions[] = "Tabuľka settings: OK";

    // invoices table
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS invoices (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      order_id INT UNSIGNED NOT NULL,
      invoice_number VARCHAR(120) NOT NULL,
      html_path VARCHAR(255) NOT NULL,
      amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_order (order_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    $actions[] = "Tabuľka invoices: OK";

    // 2) doplnkové stĺpce (idempotentne) - ak už existujú, ignorujeme
    $columnsToAdd = [
      "users" => [
        "newsletter TINYINT(1) DEFAULT 0",
        "email_verified TINYINT(1) DEFAULT 0",
        "verify_token VARCHAR(255) DEFAULT NULL",
        "reset_token VARCHAR(255) DEFAULT NULL",
        "download_token VARCHAR(255) DEFAULT NULL",
        "last_login DATETIME DEFAULT NULL"
      ]
    ];
    foreach ($columnsToAdd as $table => $cols) {
        $existing = [];
        try {
            $res = $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($res as $r) $existing[] = $r['Field'];
        } catch (Throwable $t) {
            // tabulka chýba
            continue;
        }
        foreach ($cols as $cdef) {
            $cname = preg_replace('/\s.*$/','',$cdef);
            if (!in_array($cname, $existing)) {
                $pdo->exec("ALTER TABLE `{$table}` ADD {$cdef};");
                $actions[] = "Pridaný stĺpec {$cname} do {$table}";
            } else {
                $actions[] = "Stĺpec {$cname} už existuje v {$table}";
            }
        }
    }

    // 3) vloženie základných nastavení do settings (INSERT/UPDATE)
    $basicSettings = [
      'site_name' => 'Knihy od Autorov',
      'currency' => 'EUR',
      'support_babybox' => '1',
      // company defaults (doplníš reálne údaje v admin alebo v config)
      'company_name' => 'Knihy od Autorov s.r.o.',
      'company_address' => "Ulica 1\n010 01 Mesto",
      'company_iban' => 'SK0000000000000000000000',
      'company_bic' => '',
      'company_email' => 'info@knihyodautorov.sk',
      // smtp defaults (upraviť v db/config/configsmtp.php)
      'smtp_host' => '',
      'smtp_port' => '587',
      'smtp_user' => '',
      'smtp_pass' => '',
      'smtp_secure' => 'tls',
      'smtp_from' => 'info@knihyodautorov.sk',
      // eshop rendering / options
      'eshop_download_token_ttl' => '7' // days token valid
    ];
    $upsert = $pdo->prepare("INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v = VALUES(v)");
    foreach ($basicSettings as $k => $v) {
        $upsert->execute([$k, (string)$v]);
    }
    $actions[] = "Základné nastavenia vložené / aktualizované (settings)";

    // 4) Vložiť ukážkové kategorie / autori / knihy / users / orders / reviews ak sú tabuľky prázdne
    $cntCat = (int)$pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn();
    if ($cntCat === 0) {
        $categories = [
          'Historický román','Poézia','Sci-Fi','Fantasy','Detektívka','Krimi',
          'Romantika','Biografia','Cestopisy','Kuchárske knihy','Ekonomika a biznis',
          'Psychológia','Filozofia','Detské knihy','Učebnice / vzdelávanie'
        ];
        $stmtCat = $pdo->prepare("INSERT INTO categories (nazov, slug) VALUES (?, ?)");
        foreach ($categories as $cat) $stmtCat->execute([$cat, slugify($cat)]);
        $actions[] = "Vložené vzorové kategórie (" . count($categories) . ")";
    } else $actions[] = "Kategórie: existuje {$cntCat} záznamov";

    $cntAuth = (int)$pdo->query("SELECT COUNT(*) FROM authors")->fetchColumn();
    if ($cntAuth === 0) {
        $authors = [
          ['Marek Horváth','Autor historických románov a poviedok.','author-marek.jpg'],
          ['Lucia Bieliková','Autorka poézie a krátkych próz.','author-lucia.jpg'],
          ['Peter Krajňák','Sci-fi a fantasy autor, zameraný na epické svety.','author-peter.jpg'],
          ['Adriana Novotná','Spisovateľka romantických a dobových príbehov.','author-adriana.jpg'],
          ['Tomáš Urban','Autor náučnej literatúry a cestopisov.','author-tomas.jpg']
        ];
        $stmtAuthor = $pdo->prepare("INSERT INTO authors (meno, slug, bio, foto) VALUES (?, ?, ?, ?)");
        foreach ($authors as $a) $stmtAuthor->execute([$a[0], slugify($a[0]), $a[1], $a[2]]);
        $actions[] = "Vložení vzoroví autori (" . count($authors) . ")";
    } else $actions[] = "Autori: existuje {$cntAuth} záznamov";

    $cntBooks = (int)$pdo->query("SELECT COUNT(*) FROM books")->fetchColumn();
    if ($cntBooks === 0) {
        // získat slug->id map pre categories a autorov (ak boli práve vložené)
        $cats = $pdo->query("SELECT id, slug FROM categories")->fetchAll(PDO::FETCH_ASSOC);
        $slugToId = []; foreach ($cats as $r) $slugToId[$r['slug']] = (int)$r['id'];
        $authorsList = $pdo->query("SELECT id FROM authors ORDER BY id ASC LIMIT 5")->fetchAll(PDO::FETCH_COLUMN);
        $books = [
          ['Cesta hrdinu','Epický príbeh o odvaze, priateľstve a putovaní cez staré kráľovstvá.','4.99','cesta_hrdinu.pdf','book1.png', $authorsList[0] ?? null, $slugToId['historicky-roman'] ?? null],
          ['Hviezdne more','Sci-fi dobrodružstvo medzi hviezdami.','5.49','hviezdne_more.pdf','book2.png', $authorsList[2] ?? null, $slugToId['sci-fi'] ?? null],
          ['Kvapky dažďa','Zbierka básní o láske, strate a nádeji.','2.99','kvapky_dazda.pdf','book3.png', $authorsList[1] ?? null, $slugToId['poezia'] ?? null],
          ['Na krídlach vetra','Romantický príbeh odohrávajúci sa na pobreží.','3.49','na_kridlach_vetra.pdf','book4.png', $authorsList[3] ?? null, $slugToId['romantika'] ?? null],
          ['Zabudnuté mestá','Cestopis o starých mestách.','6.99','zabudnute_mesta.pdf','book5.png', $authorsList[4] ?? null, $slugToId['cestopisy'] ?? null],
        ];
        $stmtBook = $pdo->prepare("INSERT INTO books (nazov, slug, popis, cena, pdf_file, obrazok, author_id, category_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($books as $b) {
            $stmtBook->execute([$b[0], slugify($b[0]), $b[1], $b[2], $b[3], $b[4], $b[5], $b[6]]);
        }
        $actions[] = "Vložených vzorových kníh (" . count($books) . ")";
    } else $actions[] = "Knihy: existuje {$cntBooks} záznamov";

    // users sample (ak prázdne)
    $cntUsers = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    if ($cntUsers === 0) {
        $users = [
          ['Ján Novák','jan.novak@example.com','heslo123'],
          ['Mária Kováčová','maria.kovacova@example.com','heslo123'],
          ['Peter Biely','peter.biely@example.com','heslo123'],
          ['Eva Malá','eva.mala@example.com','heslo123'],
          ['Juraj Kováč','juraj.kovac@example.com','heslo123']
        ];
        $stmtUser = $pdo->prepare("INSERT INTO users (meno, email, heslo) VALUES (?, ?, ?)");
        foreach ($users as $u) $stmtUser->execute([$u[0], $u[1], password_hash($u[2], PASSWORD_DEFAULT)]);
        $actions[] = "Vložení vzorových užívateľov (" . count($users) . ")";
    } else $actions[] = "Užívatelia: existuje {$cntUsers} záznamov";

    // orders, order_items & reviews sample (keď prázdne)
    $cntOrders = (int)$pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
    if ($cntOrders === 0) {
        // rozumné sample objednávky (1-3 položky)
        $bookIds = $pdo->query("SELECT id, cena FROM books")->fetchAll(PDO::FETCH_ASSOC);
        $userIds = $pdo->query("SELECT id FROM users")->fetchAll(PDO::FETCH_COLUMN);
        if ($bookIds && $userIds) {
            $stmtOrder = $pdo->prepare("INSERT INTO orders (user_id, total_price, currency, status, payment_method) VALUES (?, ?, ?, ?, ?)");
            $stmtItem = $pdo->prepare("INSERT INTO order_items (order_id, book_id, quantity, unit_price) VALUES (?, ?, ?, ?)");
            for ($i=0;$i<5;$i++) {
                $uid = $userIds[$i % count($userIds)];
                $stmtOrder->execute([$uid, 0.00, 'EUR', ($i%2==0 ? 'paid' : 'pending'), ($i%2==0 ? 'card' : 'bank_transfer')]);
                $orderId = (int)$pdo->lastInsertId();
                // 1-2 náhodné knihy bez duplicit
                $pick = array_rand($bookIds, min(1 + rand(0,1), count($bookIds)));
                if (!is_array($pick)) $pick = [$pick];
                $total = 0.0;
                foreach ($pick as $p) {
                    $b = $bookIds[$p];
                    $qty = rand(1,2);
                    $price = (float)$b['cena'];
                    $stmtItem->execute([$orderId, (int)$b['id'], $qty, number_format($price,2,'.','')]);
                    $total += $price * $qty;
                }
                $pdo->prepare("UPDATE orders SET total_price = ? WHERE id = ?")->execute([number_format($total,2,'.',''), $orderId]);
            }
            $actions[] = "Vložené vzorové objednávky a položky";
        } else $actions[] = "Nenájdené knihy/užívatelia pre vytvorenie vzorových objednávok";
    } else $actions[] = "Objednávky: existuje {$cntOrders} záznamov";

    // reviews sample
    $cntRev = (int)$pdo->query("SELECT COUNT(*) FROM reviews")->fetchColumn();
    if ($cntRev === 0) {
        $bookIdsArr = $pdo->query("SELECT id FROM books LIMIT 5")->fetchAll(PDO::FETCH_COLUMN);
        $userIdsArr = $pdo->query("SELECT id FROM users LIMIT 5")->fetchAll(PDO::FETCH_COLUMN);
        if (count($bookIdsArr) >= 1 && count($userIdsArr) >= 1) {
            $stmtRev = $pdo->prepare("INSERT INTO reviews (book_id, user_id, rating, comment) VALUES (?, ?, ?, ?)");
            $sampleReviews = [
              [$bookIdsArr[0], $userIdsArr[0], 5, 'Výborná kniha, čítal som ju jedným dychom.'],
              [$bookIdsArr[1] ?? $bookIdsArr[0], $userIdsArr[1] ?? $userIdsArr[0], 4, 'Zaujímavý dej, trošku pomalší štart.'],
              [$bookIdsArr[2] ?? $bookIdsArr[0], $userIdsArr[2] ?? $userIdsArr[0], 5, 'Básne, ktoré sa dotknú srdca.'],
            ];
            foreach ($sampleReviews as $r) $stmtRev->execute($r);
            $actions[] = "Vložené vzorové recenzie";
        }
    } else $actions[] = "Recenzie: existuje {$cntRev} záznamov";

    // 5) admin user (ak nie je, vytvoríme owner)
    $cntAdmin = (int)$pdo->query("SELECT COUNT(*) FROM admin_users")->fetchColumn();
    if ($cntAdmin === 0) {
        $adminUser = 'admin';
        $adminEmail = 'admin@knihyodautorov.sk';
        $adminPassPlain = bin2hex(random_bytes(5)) . 'A!'; // dočasné
        $pdo->prepare("INSERT INTO admin_users (username, email, password, role) VALUES (?, ?, ?, ?)")->execute([$adminUser, $adminEmail, password_hash($adminPassPlain, PASSWORD_DEFAULT), 'owner']);
        $actions[] = "Vytvorený admin účet: " . $adminUser . " (dočasné heslo: " . $adminPassPlain . ")";
    } else $actions[] = "Admin užívatelia: existuje {$cntAdmin} záznamov";

    // 6) súbory / priečinky a .htaccess pre books-pdf
    $root = __DIR__;
    $booksImg = $root . '/books-img';
    $booksPdf = $root . '/books-pdf';
    $eshopInvoices = $root . '/eshop/invoices';
    if (!is_dir($booksImg)) { @mkdir($booksImg, 0755, true); $actions[] = "Vytvorený priečinok: /books-img"; } else $actions[] = "Priečinok existuje: /books-img";
    if (!is_dir($booksPdf)) { @mkdir($booksPdf, 0755, true); $actions[] = "Vytvorený priečinok: /books-pdf"; } else $actions[] = "Priečinok existuje: /books-pdf";
    if (!is_dir($eshopInvoices)) { @mkdir($eshopInvoices, 0755, true); $actions[] = "Vytvorený priečinok: /eshop/invoices"; } else $actions[] = "Priečinok existuje: /eshop/invoices";

    // .htaccess pre books-pdf (zabránit priamemu přístupu)
    $ht = $booksPdf . '/.htaccess';
    $htcontent = "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\n";
    @file_put_contents($ht, $htcontent);
    $actions[] = ".htaccess napísaný do /books-pdf/.htaccess";

    $ht2 = $eshopInvoices . '/.htaccess';
    @file_put_contents($ht2, $htcontent);
    $actions[] = ".htaccess napísaný do /eshop/invoices/.htaccess";

    // 7) vytvorenie vzorového configsmtp.php (len ak neexistuje) v /db/config/
    $smtpTemplatePath = __DIR__ . '/db/config/configsmtp.php';
    if (!file_exists($smtpTemplatePath)) {
        $sample = <<<'PHP'
<?php
// db/config/configsmtp.php
// Vložte sem svoje SMTP údaje. Tento súbor by mal byť mimo VCS a prístupný len webserveru.
// NEVKLADAJTE citlivé údaje do verejného repozitára.

return [
  'host' => 'smtp.example.com',
  'port' => 587,
  'username' => 'user@example.com',
  'password' => 'your_smtp_password',
  'secure' => 'tls', // 'ssl' alebo 'tls' alebo ''
  'from_email' => 'info@knihyodautorov.sk',
  'from_name' => 'Knihy od Autorov'
];
PHP;
        @file_put_contents($smtpTemplatePath, $sample);
        $actions[] = "Vytvorený vzorový súbor db/config/configsmtp.php (doplnite svoje údaje)";
    } else {
        $actions[] = "Súbor db/config/configsmtp.php už existuje (neupravujem)";
    }

    // 8) (voliteľné) napísať poznámku pre qrcode.min.js - len info (skutočný súbor nechám stiahnuť)
    $actions[] = "QR knihovna: odporúčam umiestniť /eshop/js/qrcode.min.js (môžem ju pridať pri ďalšom kroku)";

    // 9) Shrnutie počtov
    $cntCat = (int)$pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn();
    $cntAuth = (int)$pdo->query("SELECT COUNT(*) FROM authors")->fetchColumn();
    $cntBooks = (int)$pdo->query("SELECT COUNT(*) FROM books")->fetchColumn();
    $cntUsers = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $cntOrders = (int)$pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
    $cntReviews = (int)$pdo->query("SELECT COUNT(*) FROM reviews")->fetchColumn();
    $cntInvoices = (int)$pdo->query("SELECT COUNT(*) FROM invoices")->fetchColumn();

    // 10) final output HTML summary
    echo "<!doctype html><html lang='sk'><head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'><title>Inicializácia dokončená</title>";
    echo "<style>body{font-family:system-ui,Segoe UI,Roboto,Arial;background:#f7f6f5;margin:30px;color:#111} .card{max-width:980px;margin:0 auto;padding:20px;background:#fff;border-radius:10px;box-shadow:0 10px 30px rgba(0,0,0,.06)}code{background:#f2f0ed;padding:3px 6px;border-radius:4px}</style></head><body><div class='card'>";
    echo "<h1>✅ Inicializácia dokončená</h1>";
    echo "<h3>Akcie vykonané</h3><ul>";
    foreach ($actions as $a) echo "<li>" . esc($a) . "</li>";
    echo "</ul>";
    echo "<h3>Počty záznamov (aktuálne)</h3><ul>";
    echo "<li>categories: <strong>" . esc($cntCat) . "</strong></li>";
    echo "<li>authors: <strong>" . esc($cntAuth) . "</strong></li>";
    echo "<li>books: <strong>" . esc($cntBooks) . "</strong></li>";
    echo "<li>users: <strong>" . esc($cntUsers) . "</strong></li>";
    echo "<li>orders: <strong>" . esc($cntOrders) . "</strong></li>";
    echo "<li>reviews: <strong>" . esc($cntReviews) . "</strong></li>";
    echo "<li>invoices: <strong>" . esc($cntInvoices) . "</strong></li>";
    echo "</ul>";
    echo "<p><strong>Dôležité:</strong> upravte <code>db/config/configsmtp.php</code> s platným SMTP a tiež skontrolujte <code>settings</code> (company_name, company_iban, atď.) v DB.</p>";
    echo "<p>Odporúčania ďalších krokov: pridať lokálny <code>/eshop/js/qrcode.min.js</code> (alebo použiť externé API), pripraviť admin rozhranie, zabezpečiť priečinok <code>/books-pdf</code>.</p>";
    echo "<p><strong>Bezpečnosť:</strong> súbor <code>initdb.php</code> ihneď odstráňte alebo presuňte mimo webroot.</p>";
    echo "</div></body></html>";
    exit;

} catch (Throwable $e) {
    error_log("initdb.php ERROR: " . $e->getMessage());
    echo "<h2>Chyba pri inicializácii</h2>";
    echo "<pre>" . esc($e->getMessage()) . "</pre>";
    exit;
}

<?php
/**
 * initdb.php
 * OPRAVENÁ verze (bez jedné transakce kolem DDL). 
 * Uložte do rootu vedle index.php. Konfig: db/config/config.php (musí vracet PDO).
 *
 * Po úspěšné inicializaci: ODSTRAŇTE tento soubor!
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

function esc($s){ return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/** jednoduchá slug funkce (diakritiku odstraní, nahradí mezery pomlčkami) */
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
    // ok, $pdo nastaveno v include
} else {
    die("<h2>Chyba</h2><p>Soubor <code>db/config/config.php</code> nevrátil PDO. Upravte ho tak, aby vracel PDO nebo nastavoval \$pdo.</p>");
}

/* ---------- UI: potvrzení před inicializací ---------- */
if (!isset($_GET['init'])) {
    echo "<!doctype html><html lang='cs'><head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'>";
    echo "<title>Inicializace DB — e-shop</title>";
    echo "<style>body{font-family:system-ui,Segoe UI,Roboto,Arial;background:#f7f6f4;color:#111;margin:30px} .card{max-width:900px;margin:0 auto;background:#fff;padding:22px;border-radius:10px;box-shadow:0 10px 30px rgba(0,0,0,.06)} .btn{display:inline-block;background:#0b6b3a;color:#fff;padding:10px 16px;border-radius:8px;text-decoration:none;font-weight:700}</style></head><body>";
    echo "<div class='card'><h1>Inicializace databáze — e-shop (PDF knihy)</h1>";
    echo "<p>Připojení k databázi proběhlo <strong>úspěšně</strong>. Skript vytvoří tabulky a vloží ukázková data (kategorie, autoři, knihy, uživatelé, objednávky, recenze, admin účet).</p>";
    echo "<p><strong>Bezpečnost:</strong> po dokončení okamžitě smažte nebo přesuňte tento soubor.</p>";
    echo "<p>Konfig: <code>" . esc($configPath) . "</code></p>";
    echo "<p><a class='btn' href='?init=1'>Inicializovat databázi</a></p></div></body></html>";
    exit;
}

/* ---------- začínáme inicializaci (bez jediné globální transakce) ---------- */
try {
    // 1) drop tables (správné pořadí, aby FK nezpůsobily chybu)
    $dropOrder = ['reviews','order_items','orders','books','categories','authors','users','admin_users','settings'];
    foreach ($dropOrder as $t) {
        $pdo->exec("DROP TABLE IF EXISTS `{$t}`;");
    }

    // 2) create tables
    $pdo->exec("
    CREATE TABLE users (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      meno VARCHAR(150) NOT NULL,
      email VARCHAR(255) NOT NULL UNIQUE,
      heslo VARCHAR(255) NOT NULL,
      telefon VARCHAR(50) DEFAULT NULL,
      adresa TEXT DEFAULT NULL,
      datum_registracie DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ;
    ");

    $pdo->exec("
    CREATE TABLE authors (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      meno VARCHAR(255) NOT NULL,
      slug VARCHAR(255) NOT NULL,
      bio TEXT DEFAULT NULL,
      foto VARCHAR(255) DEFAULT NULL,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY (slug)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ;
    ");

    $pdo->exec("
    CREATE TABLE categories (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      nazov VARCHAR(150) NOT NULL,
      slug VARCHAR(150) NOT NULL,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY (slug)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ;
    ");

    $pdo->exec("
    CREATE TABLE books (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ;
    ");

    $pdo->exec("
    CREATE TABLE orders (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      user_id INT UNSIGNED NOT NULL,
      total_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
      currency CHAR(3) DEFAULT 'EUR',
      status ENUM('pending','paid','cancelled','refunded','fulfilled') DEFAULT 'pending',
      payment_method VARCHAR(100) DEFAULT NULL,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_user (user_id),
      INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ;
    ");

    $pdo->exec("
    CREATE TABLE order_items (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      order_id INT UNSIGNED NOT NULL,
      book_id INT UNSIGNED NOT NULL,
      quantity INT NOT NULL DEFAULT 1,
      unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_order (order_id),
      INDEX idx_book (book_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ;
    ");

    $pdo->exec("
    CREATE TABLE reviews (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      book_id INT UNSIGNED NOT NULL,
      user_id INT UNSIGNED NOT NULL,
      rating TINYINT NOT NULL,
      comment TEXT,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_book (book_id),
      INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ;
    ");

    $pdo->exec("
    CREATE TABLE admin_users (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      username VARCHAR(100) NOT NULL UNIQUE,
      email VARCHAR(255) DEFAULT NULL,
      password VARCHAR(255) NOT NULL,
      role VARCHAR(50) DEFAULT 'manager',
      is_active TINYINT(1) DEFAULT 1,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ;
    ");

    $pdo->exec("
    CREATE TABLE settings (
      k VARCHAR(100) NOT NULL PRIMARY KEY,
      v TEXT DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ;
    ");

    // 3) vložení kategorií (obsáhlý seznam)
    $categories = [
      'Historický román','Poézia','Sci-Fi','Fantasy','Detektívka','Krimi',
      'Romantika','Biografia','Cestopisy','Kuchárske knihy','Ekonomika a biznis',
      'Psychológia','Filozofia','Detské knihy','Učebnice / vzdelávanie'
    ];
    $stmtCat = $pdo->prepare("INSERT INTO categories (nazov, slug) VALUES (?, ?)");
    foreach ($categories as $cat) {
        $stmtCat->execute([$cat, slugify($cat)]);
    }

    // 4) autoři (5)
    $authors = [
      ['Marek Horváth','Autor historických románov a poviedok.','author-marek.jpg'],
      ['Lucia Bieliková','Autorka poézie a krátkych próz.','author-lucia.jpg'],
      ['Peter Krajňák','Sci-fi a fantasy autor, zameraný na epické svety.','author-peter.jpg'],
      ['Adriana Novotná','Spisovateľka romantických a dobových príbehov.','author-adriana.jpg'],
      ['Tomáš Urban','Autor náučnej literatúry a cestopisov.','author-tomas.jpg']
    ];
    $stmtAuthor = $pdo->prepare("INSERT INTO authors (meno, slug, bio, foto) VALUES (?, ?, ?, ?)");
    $authorIds = [];
    foreach ($authors as $a) {
        $stmtAuthor->execute([$a[0], slugify($a[0]), $a[1], $a[2]]);
        $authorIds[] = (int)$pdo->lastInsertId();
    }

    // 5) uživatelé (5) - hesla jako hash
    $users = [
      ['Ján Novák','jan.novak@example.com','heslo123'],
      ['Mária Kováčová','maria.kovacova@example.com','heslo123'],
      ['Peter Biely','peter.biely@example.com','heslo123'],
      ['Eva Malá','eva.mala@example.com','heslo123'],
      ['Juraj Kováč','juraj.kovac@example.com','heslo123']
    ];
    $stmtUser = $pdo->prepare("INSERT INTO users (meno, email, heslo) VALUES (?, ?, ?)");
    $userIds = [];
    foreach ($users as $u) {
        $stmtUser->execute([$u[0], $u[1], password_hash($u[2], PASSWORD_DEFAULT)]);
        $userIds[] = (int)$pdo->lastInsertId();
    }

    // 6) knihy (5)
    // map category slug -> id
    $rows = $pdo->query("SELECT id, slug FROM categories")->fetchAll(PDO::FETCH_ASSOC);
    $slugToId = [];
    foreach ($rows as $r) $slugToId[$r['slug']] = (int)$r['id'];

    $books = [
      [
        'nazov'=>'Cesta hrdinu','popis'=>'Epický príbeh o odvaze, priateľstve a putovaní cez staré kráľovstvá.',
        'cena'=>'4.99','pdf'=>'cesta_hrdinu.pdf','img'=>'book1.jpg','author_idx'=>0,'cat'=>'historicky-roman'
      ],
      [
        'nazov'=>'Hviezdne more','popis'=>'Sci-fi dobrodružstvo medzi hviezdami a politické intriky galaktickej federácie.',
        'cena'=>'5.49','pdf'=>'hviezdne_more.pdf','img'=>'book2.jpg','author_idx'=>2,'cat'=>'sci-fi'
      ],
      [
        'nazov'=>'Kvapky dažďa','popis'=>'Zbierka básní o láske, strate a nádeji.',
        'cena'=>'2.99','pdf'=>'kvapky_dazda.pdf','img'=>'book3.jpg','author_idx'=>1,'cat'=>'poezia'
      ],
      [
        'nazov'=>'Na krídlach vetra','popis'=>'Romantický príbeh odohrávajúci sa na pobreží.',
        'cena'=>'3.49','pdf'=>'na_kridlach_vetra.pdf','img'=>'book4.jpg','author_idx'=>3,'cat'=>'romantika'
      ],
      [
        'nazov'=>'Zabudnuté mestá','popis'=>'Cestopis a historický výskum starovekých miest.',
        'cena'=>'6.99','pdf'=>'zabudnute_mesta.pdf','img'=>'book5.jpg','author_idx'=>4,'cat'=>'cestopisy'
      ]
    ];

    $stmtBook = $pdo->prepare("INSERT INTO books (nazov, slug, popis, cena, pdf_file, obrazok, author_id, category_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $bookIds = [];
    foreach ($books as $b) {
        $slug = slugify($b['nazov']);
        $authorId = $authorIds[$b['author_idx']] ?? null;
        $categoryId = $slugToId[$b['cat']] ?? null;
        $stmtBook->execute([$b['nazov'], $slug, $b['popis'], $b['cena'], $b['pdf'], $b['img'], $authorId, $categoryId]);
        $bookIds[] = (int)$pdo->lastInsertId();
    }

    // 7) objednávky (5) a položky
    $stmtOrder = $pdo->prepare("INSERT INTO orders (user_id, total_price, currency, status, payment_method) VALUES (?, ?, ?, ?, ?)");
    $stmtItem = $pdo->prepare("INSERT INTO order_items (order_id, book_id, quantity, unit_price) VALUES (?, ?, ?, ?)");
    for ($i=0;$i<5;$i++) {
        $uid = $userIds[$i % count($userIds)];
        $stmtOrder->execute([$uid, 0.00, 'EUR', ($i%2==0 ? 'paid' : 'pending'), ($i%2==0 ? 'card' : 'paypal')]);
        $orderId = (int)$pdo->lastInsertId();

        // vezmeme 1-3 náhodné knihy (bez duplicit v jedné objednávce)
        $take = array_rand($bookIds, min(1+rand(0,2), count($bookIds)));
        if (!is_array($take)) $take = [$take];
        $total = 0.0;
        foreach ($take as $idx) {
            $bk = $bookIds[$idx];
            $price = (float)$pdo->query("SELECT cena FROM books WHERE id=".(int)$bk)->fetchColumn();
            $qty = rand(1,2);
            $stmtItem->execute([$orderId, $bk, $qty, number_format($price,2,'.','')]);
            $total += $price * $qty;
        }
        $pdo->prepare("UPDATE orders SET total_price = ? WHERE id = ?")->execute([number_format($total,2,'.',''), $orderId]);
    }

    // 8) recenze (5)
    $stmtReview = $pdo->prepare("INSERT INTO reviews (book_id, user_id, rating, comment) VALUES (?, ?, ?, ?)");
    $sampleReviews = [
      [$bookIds[0], $userIds[0], 5, 'Výborná kniha, čítal som ju jedným dychom.'],
      [$bookIds[1], $userIds[1], 4, 'Zaujímavý dej, trošku pomalší štart.'],
      [$bookIds[2], $userIds[2], 5, 'Básne, ktoré sa dotknú srdca.'],
      [$bookIds[3], $userIds[3], 3, 'Romantika pekná, ale očakával som viac.'],
      [$bookIds[4], $userIds[4], 4, 'Dobrý cestopis, pekné popisy miest.']
    ];
    foreach ($sampleReviews as $r) $stmtReview->execute($r);

    // 9) admin user (změň heslo po prvním přihlášení)
    $adminUser = 'admin';
    $adminEmail = 'admin@knihyodautorov.sk';
    $adminPassPlain = 'AdminPass123!';
    $pdo->prepare("INSERT INTO admin_users (username, email, password, role) VALUES (?, ?, ?, ?)")->execute([$adminUser, $adminEmail, password_hash($adminPassPlain, PASSWORD_DEFAULT), 'owner']);

    // 10) settings
    $pdo->prepare("INSERT INTO settings (k, v) VALUES (?, ?)")->execute(['site_name','Knihy od Autorov']);
    $pdo->prepare("INSERT INTO settings (k, v) VALUES (?, ?)")->execute(['currency','EUR']);
    $pdo->prepare("INSERT INTO settings (k, v) VALUES (?, ?)")->execute(['support_babybox','1']);

    // 11) vytvoření složek a .htaccess pro books-pdf
    $root = __DIR__;
    $booksImg = $root . '/books-img';
    $booksPdf = $root . '/books-pdf';
    if (!is_dir($booksImg)) @mkdir($booksImg, 0755, true);
    if (!is_dir($booksPdf)) @mkdir($booksPdf, 0755, true);
    $ht = $booksPdf . '/.htaccess';
    $htcontent = "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\n";
    @file_put_contents($ht, $htcontent);

    // 12) shrnutí
    $cntCat = (int)$pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn();
    $cntAuth = (int)$pdo->query("SELECT COUNT(*) FROM authors")->fetchColumn();
    $cntBooks = (int)$pdo->query("SELECT COUNT(*) FROM books")->fetchColumn();
    $cntUsers = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $cntOrders = (int)$pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
    $cntReviews = (int)$pdo->query("SELECT COUNT(*) FROM reviews")->fetchColumn();

    echo "<!doctype html><html lang='cs'><head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'><title>Inicializace dokončena</title>";
    echo "<style>body{font-family:system-ui,Segoe UI,Roboto,Arial;background:#f7f6f5;margin:30px;color:#111}.card{max-width:900px;margin:0 auto;padding:20px;background:#fff;border-radius:10px;box-shadow:0 8px 30px rgba(0,0,0,.06)}code{background:#f2f0ed;padding:3px 6px;border-radius:4px}</style></head><body><div class='card'>";
    echo "<h1>✅ Inicializace dokončena</h1>";
    echo "<ul><li>Kategorie: <strong>".esc($cntCat)."</strong></li><li>Autoři: <strong>".esc($cntAuth)."</strong></li><li>Knihy: <strong>".esc($cntBooks)."</strong></li><li>Uživatelé: <strong>".esc($cntUsers)."</strong></li><li>Objednávky: <strong>".esc($cntOrders)."</strong></li><li>Recenze: <strong>".esc($cntReviews)."</strong></li></ul>";
    echo "<p>Vytvořeny složky: <code>books-img/</code> a <code>books-pdf/</code>. Admin: <code>".esc($adminUser)."</code> (heslo: <code>".esc($adminPassPlain)."</code>) — ihned změňte.</p>";
    echo "<p><strong>Bezpečnost:</strong> okamžitě smažte nebo přesuňte <code>initdb.php</code> mimo webroot.</p></div></body></html>";

} catch (Exception $e) {
    // log a čitelná chyba pro debug
    error_log("initdb.php ERROR: " . $e->getMessage());
    echo "<h2>Chyba při inicializaci</h2>";
    echo "<pre>" . esc($e->getMessage()) . "</pre>";
    exit;
}
// konec skriptu, vše proběhlo v pořádku
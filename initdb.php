<?php
/**
 * initdb.php
 * Inicializácia databázy pre e-shop s PDF knihami (MariaDB/MySQL)
 *
 * Umiestniť do rootu projektu (vedľa index.php).
 * Konfigurácia pripojenia musí byť v db/config/config.php
 * Tento súbor NEobsahuje prihlasovacie údaje — importuje ich z db/config/config.php
 *
 * POZOR: Po úspešnej inicializácii odporúčam súbor odstrániť alebo presunúť.
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// --- načítanie PDO z db/config/config.php ---
// Očakáva sa, že súbor vráti PDO alebo nastaví $pdo
$configPath = __DIR__ . '/db/config/config.php';
if (!file_exists($configPath)) {
    die("<h2>Chyba:</h2> Konfiguračný súbor <code>db/config/config.php</code> neexistuje. Vytvor ho a vráť PDO (\$pdo) alebo PDO ako return.");
}

$maybePdo = require $configPath;
if ($maybePdo instanceof PDO) {
    $pdo = $maybePdo;
} elseif (isset($pdo) && $pdo instanceof PDO) {
    // $pdo nastavené priamo v súbore
} else {
    die("<h2>Chyba:</h2> Konfiguračný súbor nevrátil PDO objekt ani nenastavil \$pdo. Skontroluj db/config/config.php.");
}

// Bezpečnostné upozornenie: tento skript je určený na jednorazové použitie.
function esc($s){ return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

if (!isset($_GET['init'])) {
    echo "<!doctype html><html lang='sk'><head><meta charset='utf-8'><title>Inštalácia DB</title>
    <meta name='viewport' content='width=device-width,initial-scale=1'>
    <style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;margin:40px;background:#fbfaf8;color:#222}
    .card{max-width:920px;margin:0 auto;padding:24px;border-radius:10px;background:#fff;box-shadow:0 10px 30px rgba(0,0,0,0.08)}
    button{background:#2f7a3e;color:#fff;padding:10px 16px;border-radius:8px;border:0;cursor:pointer}
    pre{background:#f4f2ef;padding:12px;border-radius:6px;overflow:auto}</style></head><body>";
    echo "<div class='card'>";
    echo "<h1>Inicializácia databázy — e-shop (knihy PDF)</h1>";
    echo "<p>Pripojenie k databáze <strong>prebehlo úspešne</strong>. Skript vytvorí tabuľky a vloží testovacie dáta (kategórie, autori, knihy, užívatelia, objednávky, recenzie, admin účet).</p>";
    echo "<p><strong>Dôležité:</strong> po úspešnej inicializácii odporúčam <em>okamžite zmazať</em> alebo presunúť tento súbor (<code>initdb.php</code>), aby nebol zneužiteľný.</p>";
    echo "<p>Konfigurácia DB: <code>" . esc($configPath) . "</code></p>";
    echo "<p style='margin-top:18px'><a href='?init=1'><button>Inicializovať databázu</button></a></p>";
    echo "</div></body></html>";
    exit;
}

// --- vykonávame inicializáciu ---
$out = [];
try {
    $pdo->beginTransaction();

    // Zmažeme staré tabuľky (ak existujú) v bezpečnom poradí
    $dropOrder = [
        'reviews','order_items','orders','books','categories','authors','users','admin_users','settings'
    ];
    foreach ($dropOrder as $t) {
        $pdo->exec("DROP TABLE IF EXISTS `{$t}`;");
    }

    // Vytvorenie tabuliek
    $pdo->exec("
    CREATE TABLE users (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      meno VARCHAR(150) NOT NULL,
      email VARCHAR(255) NOT NULL UNIQUE,
      heslo VARCHAR(255) NOT NULL,
      telefon VARCHAR(50) DEFAULT NULL,
      adresa TEXT DEFAULT NULL,
      datum_registracie DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    $pdo->exec("
    CREATE TABLE categories (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      nazov VARCHAR(150) NOT NULL,
      slug VARCHAR(150) NOT NULL,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY (slug)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    $pdo->exec("
    CREATE TABLE settings (
      k VARCHAR(100) NOT NULL PRIMARY KEY,
      v TEXT DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // --- vloženie kategórií (obsiahly zoznam) ---
    $categories = [
      'Historický román','Poézia','Sci-Fi','Fantasy','Detektívka','Krimi',
      'Romantika','Biografia','Cestopisy','Kuchárske knihy','Ekonomika a biznis',
      'Psychológia','Filozofia','Detské knihy','Učebnice / vzdelávanie'
    ];
    $stmtCat = $pdo->prepare("INSERT INTO categories (nazov, slug) VALUES (?, ?)");
    foreach ($categories as $cat) {
        $slug = mb_strtolower(trim(preg_replace('/[^A-Za-z0-9ÁČĎÉÍĽĽŇÓŘŠŤÚÝŽáčďéíľňóřšťúýž ]+/u','',$cat)));
        $slug = preg_replace('/\s+/', '-', $slug);
        $stmtCat->execute([$cat, $slug]);
    }

    // --- vloženie autorov (5) ---
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
        $slug = mb_strtolower(trim(preg_replace('/[^A-Za-z0-9áčďéíľňóřšťúýžÁČĎÉÍĽŇÓŘŠŤÚÝŽ ]+/u','',$a[0])));
        $slug = preg_replace('/\s+/', '-', $slug);
        $stmtAuthor->execute([$a[0], $slug, $a[1], $a[2]]);
        $authorIds[] = $pdo->lastInsertId();
    }

    // --- vloženie 5 testovacích užívateľov ---
    $users = [
      ['Ján Novák','jan.novak@example.com','password1'],
      ['Mária Kováčová','maria.kovacova@example.com','password2'],
      ['Peter Biely','peter.biely@example.com','password3'],
      ['Eva Malá','eva.mala@example.com','password4'],
      ['Juraj Kováč','juraj.kovac@example.com','password5']
    ];
    $stmtUser = $pdo->prepare("INSERT INTO users (meno, email, heslo) VALUES (?, ?, ?)");
    $userIds = [];
    foreach ($users as $u) {
        $hash = password_hash($u[2], PASSWORD_DEFAULT);
        $stmtUser->execute([$u[0], $u[1], $hash]);
        $userIds[] = $pdo->lastInsertId();
    }

    // --- vloženie 5 ukážkových kníh ---
    // Pridáme rozmanité párovanie author_id a category_id (berieme prvých autorov / kategórií)
    $books = [
      [
        'nazov' => 'Cesta hrdinu',
        'popis' => 'Epický príbeh o odvaze, priateľstve a putovaní cez staré kráľovstvá.',
        'cena' => '4.99',
        'pdf' => 'cesta_hrdinu.pdf',
        'img' => 'book1.jpg',
        'author_idx' => 0,
        'category_slug' => 'historický-román'
      ],
      [
        'nazov' => 'Hviezdne more',
        'popis' => 'Sci-fi dobrodružstvo medzi hviezdami a politické intriky galaktickej federácie.',
        'cena' => '5.49',
        'pdf' => 'hviezdne_more.pdf',
        'img' => 'book2.jpg',
        'author_idx' => 2,
        'category_slug' => 'sci-fi'
      ],
      [
        'nazov' => 'Kvapky dažďa',
        'popis' => 'Zbierka básní o láske, strate a nádeji.',
        'cena' => '2.99',
        'pdf' => 'kvapky_dazda.pdf',
        'img' => 'book3.jpg',
        'author_idx' => 1,
        'category_slug' => 'poézia'
      ],
      [
        'nazov' => 'Na krídlach vetra',
        'popis' => 'Romantický príbeh odohrávajúci sa na pobreží a medzi generáciami rodinných tajomstiev.',
        'cena' => '3.49',
        'pdf' => 'na_kridlach_vetra.pdf',
        'img' => 'book4.jpg',
        'author_idx' => 3,
        'category_slug' => 'romantika'
      ],
      [
        'nazov' => 'Zabudnuté mestá',
        'popis' => 'Cestopis a historický výskum starovekých miest, ktoré zmenili dejiny.',
        'cena' => '6.99',
        'pdf' => 'zabudnute_mesta.pdf',
        'img' => 'book5.jpg',
        'author_idx' => 4,
        'category_slug' => 'cestopisy'
      ]
    ];

    // map category slugs -> ids
    $catRows = $pdo->query("SELECT id, nazov, slug FROM categories")->fetchAll(PDO::FETCH_ASSOC);
    $slugToId = [];
    foreach ($catRows as $r) $slugToId[$r['slug']] = $r['id'];

    $stmtBook = $pdo->prepare("INSERT INTO books (nazov, slug, popis, cena, pdf_file, obrazok, author_id, category_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $bookIds = [];
    foreach ($books as $b) {
        $slug = mb_strtolower(trim(preg_replace('/[^A-Za-z0-9áčďéíľňóřšťúýžÁČĎÉÍĽŇÓŘŠŤÚÝŽ ]+/u','',$b['nazov'])));
        $slug = preg_replace('/\s+/', '-', $slug);
        // author id by index in $authorIds
        $authorId = $authorIds[$b['author_idx']] ?? null;
        $categoryId = $slugToId[$b['category_slug']] ?? null;
        $stmtBook->execute([$b['nazov'], $slug, $b['popis'], $b['cena'], $b['pdf'], $b['img'], $authorId, $categoryId]);
        $bookIds[] = $pdo->lastInsertId();
    }

    // --- vloženie 5 testovacích objednávok a položiek ---
    $stmtOrder = $pdo->prepare("INSERT INTO orders (user_id, total_price, currency, status, payment_method) VALUES (?, ?, ?, ?, ?)");
    $stmtItem = $pdo->prepare("INSERT INTO order_items (order_id, book_id, quantity, unit_price) VALUES (?, ?, ?, ?)");
    // vytvoríme 5 objednávok pre rôznych užívateľov
    for ($i=0;$i<5;$i++) {
        $uid = $userIds[$i % count($userIds)];
        // vyberieme náhodne 1-3 knihy
        $numItems = rand(1,3);
        $selected = [];
        $total = 0;
        for ($j=0;$j<$numItems;$j++){
            $bk = $bookIds[array_rand($bookIds)];
            if (!isset($selected[$bk])) $selected[$bk] = 0;
            $selected[$bk] += 1;
        }
        // vložíme order s total 0 (upravený neskôr)
        $stmtOrder->execute([$uid, 0.00, 'EUR', ($i%2==0 ? 'paid' : 'pending'), ($i%2==0 ? 'card' : 'paypal')]);
        $orderId = $pdo->lastInsertId();
        foreach ($selected as $bk => $qty) {
            // nájdeme cenu knihy
            $priceRow = $pdo->prepare("SELECT cena FROM books WHERE id = ?");
            $priceRow->execute([$bk]);
            $price = $priceRow->fetchColumn() ?: 0.00;
            $stmtItem->execute([$orderId, $bk, $qty, $price]);
            $total += $price * $qty;
        }
        // aktualizujeme total
        $upd = $pdo->prepare("UPDATE orders SET total_price = ? WHERE id = ?");
        $upd->execute([number_format($total,2,'.',''), $orderId]);
    }

    // --- vloženie 5 recenzií ---
    $stmtReview = $pdo->prepare("INSERT INTO reviews (book_id, user_id, rating, comment) VALUES (?, ?, ?, ?)");
    $sampleReviews = [
      [$bookIds[0], $userIds[0], 5, 'Výborná kniha, čítal som ju jedným dychom.'],
      [$bookIds[1], $userIds[1], 4, 'Zaujímavý dej, trošku pomalší štart.'],
      [$bookIds[2], $userIds[2], 5, 'Básne, ktoré sa dotknú srdca.'],
      [$bookIds[3], $userIds[3], 3, 'Romantika pekná, ale očakával som viac.'],
      [$bookIds[4], $userIds[4], 4, 'Dobrý cestopis, pekné popisy miest.']
    ];
    foreach ($sampleReviews as $r) {
        $stmtReview->execute($r);
    }

    // --- vytvorenie admin účtu (výstraha: zmeň heslo) ---
    $adminUser = 'admin';
    $adminEmail = 'admin@knihyodautorov.sk';
    $adminPass = 'AdminPass123!'; // odporúčam ihneď zmeniť
    $adminHash = password_hash($adminPass, PASSWORD_DEFAULT);
    $stmtAdmin = $pdo->prepare("INSERT INTO admin_users (username, email, password, role) VALUES (?, ?, ?, ?)");
    $stmtAdmin->execute([$adminUser, $adminEmail, $adminHash, 'owner']);
    $adminId = $pdo->lastInsertId();

    // --- nastavenia (príklad) ---
    $stmtSet = $pdo->prepare("INSERT INTO settings (k, v) VALUES (?, ?)");
    $stmtSet->execute(['site_name', 'Knihy od Autorov']);
    $stmtSet->execute(['currency', 'EUR']);
    $stmtSet->execute(['support_babybox', '1']);

    // commit
    $pdo->commit();

    // --- súbory / priečinky: books-img a books-pdf + .htaccess pre pdf ---
    $root = __DIR__;
    $booksImg = $root . '/books-img';
    $booksPdf = $root . '/books-pdf';
    if (!is_dir($booksImg)) {
        mkdir($booksImg, 0755, true);
    }
    if (!is_dir($booksPdf)) {
        mkdir($booksPdf, 0755, true);
    }
    // .htaccess v books-pdf: zamedzenie priameho prístupu (Apache)
    $ht = $booksPdf . '/.htaccess';
    $htcontent = "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\n";
    file_put_contents($ht, $htcontent);

    // --- výsledok ---
    echo "<!doctype html><html lang='sk'><head><meta charset='utf-8'><title>Inicializácia hotová</title>
    <meta name='viewport' content='width=device-width,initial-scale=1'><style>body{font-family:system-ui;margin:30px;background:#f7f6f5;color:#111} .card{max-width:900px;margin:0 auto;padding:20px;background:#fff;border-radius:10px;box-shadow:0 8px 30px rgba(0,0,0,0.06)} pre{background:#f2f0ed;padding:12px;border-radius:6px;overflow:auto}</style></head><body><div class='card'>";
    echo "<h1>✅ Databáza úspešne inicializovaná</h1>";
    echo "<p>Vytvorené tabuľky a vložené vzorové dáta:</p>";
    echo "<ul>
      <li>Kategórie: " . count($categories) . "</li>
      <li>Autori: " . count($authors) . "</li>
      <li>Knihy: " . count($books) . "</li>
      <li>Užívatelia: " . count($users) . "</li>
      <li>Objednávky: 5 (test)</li>
      <li>Recenzie: " . count($sampleReviews) . "</li>
      <li>Admin účet: <strong>{$adminUser}</strong> (heslo: <strong>{$adminPass}</strong>) — <em>ihneď zmeniť</em></li>
    </ul>";
    echo "<p>Vytvorené priečinky: <code>books-img/</code> a <code>books-pdf/</code>. Súbor <code>books-pdf/.htaccess</code> bol vytvorený (blokovanie priameho prístupu).</p>";
    echo "<p><strong>Bezpečnosť:</strong> odstráň tento súbor <code>initdb.php</code> alebo ho premiestni mimo webroot.</p>";
    echo "</div></body></html>";

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    // logovanie chyby na server (neukazovať v produkcii priveľa detailov)
    error_log("InitDB error: " . $e->getMessage());
    echo "<h2>Chyba pri inicializácii databázy</h2>";
    echo "<pre>" . esc($e->getMessage()) . "</pre>";
    exit;
}

// --- koniec skriptu ---
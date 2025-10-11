<?php

declare(strict_types=1);

use BlackCat\Core\Database;

$PROJECT_ROOT = realpath(dirname(__DIR__, 3));
if ($PROJECT_ROOT === false) {
    error_log('[bootstrap] Cannot resolve PROJECT_ROOT');
    http_response_code(500);
    exit;
}
$configFile = $PROJECT_ROOT . '/secure/config.php';
if (!file_exists($configFile)) {
    error_log('[bootstrap] Missing secure/config.php');
    http_response_code(500);
    exit;
}
require_once $configFile;
if (!isset($config) || !is_array($config)) {
    error_log('[bootstrap] secure/config.php must define $config array');
    http_response_code(500);
    exit;
}
require_once $PROJECT_ROOT . '/libs/autoload.php';
if (!class_exists(BlackCat\Core\Database::class, true)) {
        error_log('[bootstrap_minimal] Class BlackCat\\Core\\Database not found by autoloader');
        http_response_code(500);
        exit;
    }
try {
    // Použijte konstantu třídy místo prostého stringu
    if (!class_exists(BlackCat\Core\Database::class, true)) {
        throw new RuntimeException('Database class not available (autoload error)');
    }

    if (empty($config['adb']) || !is_array($config['adb'])) {
        throw new RuntimeException('Missing $config[\'adb\']');
    }

    Database::init($config['adb']);
    $database = Database::getInstance();
    $pdo = $database->getPdo();
} catch (Throwable $e) {
    // logujeme místo echo => žádné "headers already sent"
    error_log('Database initialization failed: ' . $e->getMessage());
    http_response_code(500);
    exit;
}

if (!($pdo instanceof PDO)) {
    error_log('DB variable is not a PDO instance after init');
    http_response_code(500);
    exit;
}

// Funkce pro smazání všech tabulek
function dropAllTables($pdo) {
    // Vypnout kontrolu cizích klíčů
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        $pdo->exec("DROP TABLE IF EXISTS `$table`");
    }

    // Znovu zapnout kontrolu
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
}


// Pokud přišel POST požadavek na smazání
if (isset($_POST['drop_db'])) {
    dropAllTables($pdo);
    echo "<h2>Celá databáze byla smazána.</h2>";
    exit;
}

// Po stlačení tlačidla "presun"
if (isset($_POST['move_script'])) {
    $current = __FILE__;
    $destination = $PROJECT_ROOT . '/secure/exportdb.php';
    if (@rename($current, $destination)) {
        echo "<p>Skript bol presunutý do adresára /secure/.</p>";
        exit;
    } else {
        echo "<p>Presun sa nepodaril – skontrolujte práva na adresár /secure/.</p>";
    }
}
// Zjisti seznam tabulek
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

echo "<!DOCTYPE html><html lang='cs'><head>
<meta charset='UTF-8'>
<title>Výpis celé databáze</title>
<style>
    body { font-family: Arial, sans-serif; margin: 20px; }
    h2 { margin-top: 40px; }
    table { border-collapse: collapse; margin-bottom: 30px; }
    th, td { border: 1px solid #666; padding: 6px 10px; }
    th { background: #eee; }
    form { margin: 20px 0; }
</style>
</head><body>";

echo "<h1>Obsah databáze</h1>";

// Tlačítko pro smazání celé databáze
echo "<form method='post' onsubmit=\"return confirm('Opravdu chcete smazat celou databázi? Tato akce je nevratná.');\">
        <button type='submit' name='drop_db' style='background:#c00;color:#fff;padding:10px 15px;border:none;cursor:pointer;'>
            Smazat celou databázi
        </button>
      </form>";
echo "<form method='post' onsubmit=\"return confirm('Naozaj chcete presunúť initdb.php do /secure/?');\">
        <button type='submit' name='move_script' style='background:#00CC00;color:#fff;padding:10px 15px;border:none;cursor:pointer;'>
            Presunúť do /secure/
        </button>
    </form>";

if (!$tables) {
    echo "<h2>Databáze je prázdná (žádné tabulky)</h2>";
    echo "</body></html>";
    exit;
}

// helper: rozpozná, jestli typ sloupce je binární/blob
function isBinaryColumnType(string $type): bool {
    $type = strtolower($type);
    return str_contains($type, 'blob')
        || str_contains($type, 'binary')
        || str_contains($type, 'varbinary')
        || str_contains($type, 'bit'); // bit může být binární taky
}

foreach ($tables as $table) {
    echo "<h2>Tabulka: <code>$table</code></h2>";

    // Zjistíme typy sloupců (SHOW COLUMNS)
    $colsStmt = $pdo->query("SHOW COLUMNS FROM `$table`");
    $cols = $colsStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$cols) {
        echo "<p><em>Nelze načíst schéma tabulky.</em></p>";
        continue;
    }
    $colTypes = [];
    foreach ($cols as $c) {
        // 'Type' obsahuje něco jako "varbinary(32)" nebo "longblob"
        $colTypes[$c['Field']] = $c['Type'];
    }

    // Dotaz na data (bez změn)
    $stmt = $pdo->query("SELECT * FROM `$table`");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) {
        echo "<p><em>Tabulka je prázdná.</em></p>";
        continue;
    }

    // Hlavička tabulky
    echo "<table><tr>";
    foreach (array_keys($rows[0]) as $col) {
        echo "<th>" . htmlspecialchars($col) . "</th>";
    }
    echo "</tr>";

    // Data
    foreach ($rows as $row) {
        echo "<tr>";
        foreach ($row as $colName => $val) {
            $type = $colTypes[$colName] ?? '';
            if ($val === null) {
                echo "<td><em>null</em></td>";
                continue;
            }

            if (isBinaryColumnType($type)) {
                // binární data: zobrazíme délku a base64 (bez raw bin)
                $len = is_string($val) ? strlen($val) : 0;
                $b64 = base64_encode((string)$val);
                // zkrátit zobrazení base64 pokud je velmi dlouhé
                $show = $b64;
                if (strlen($b64) > 200) {
                    $show = substr($b64, 0, 200) . '... (base64 zkráceno, celkem ' . $len . ' B)';
                }
                echo "<td><code>BIN(len={$len})</code><br><small>" . htmlspecialchars($show) . "</small></td>";
            } else {
                // neterminální textová/nebinární data - bezpečné HTML escaping
                echo "<td>" . nl2br(htmlspecialchars((string)$val)) . "</td>";
            }
        }
        echo "</tr>";
    }

    echo "</table>";
}

echo "</body></html>";
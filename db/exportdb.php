<?php
// showdb.php

// Načti PDO připojení
$pdo = require __DIR__ . '/db/config/config.php';

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

if (!$tables) {
    echo "<h2>Databáze je prázdná (žádné tabulky)</h2>";
    echo "</body></html>";
    exit;
}

foreach ($tables as $table) {
    echo "<h2>Tabulka: <code>$table</code></h2>";

    // Získej všechna data
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
        foreach ($row as $val) {
            echo "<td>" . htmlspecialchars((string)$val) . "</td>";
        }
        echo "</tr>";
    }

    echo "</table>";
}

echo "</body></html>";
<?php

declare(strict_types=1);

/**
 * Bezpečný načítač .env (mimo webroot).
 * - Naplní pouze $_ENV a $_SERVER (nevolá putenv/getenv).
 * - Nepřepisuje již existující proměnné v $_ENV/$_SERVER.
 * - Podporuje: KEY=VAL, export KEY=VAL, KEY="quoted val", KEY='quoted val'
 * - Odstraní inline komentáře (#) jen pro ne-quoted hodnoty.
 */

$envFile = __DIR__ . '/../.env'; // adjust path if needed
if (!is_readable($envFile)) {
    // bezpečně nic nedělat pokud soubor chybí / není čitelný
    return;
}

$lines = @file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if ($lines === false) return;

foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#')) {
        continue;
    }

    // match "export KEY=VAL" or "KEY=VAL"
    if (!preg_match('/^\s*(?:export\s+)?([A-Z][A-Z0-9_]*)\s*=\s*(.*)$/i', $line, $m)) {
        // neodpovídá základnímu tvaru KEY=VAL → přeskočit
        continue;
    }

    $key = $m[1];
    $raw = $m[2];

    // pokud je hodnota v uvozovkách (single/double), zachovej mezi nimi cokoliv
    if ($raw !== '' && ($raw[0] === '"' || $raw[0] === "'")) {
        $quote = $raw[0];
        // pokud končí stejnou uvozovkou na konci řádku, stripni je
        if (substr($raw, -1) === $quote) {
            $value = substr($raw, 1, -1);
            // Unescape jednoduché sekvence pro double-quoted řetězce
            if ($quote === '"') {
                $value = str_replace(['\\n','\\r','\\t','\\"','\\\\'], ["\n","\r","\t",'"',"\\\\"], $value);
            }
        } else {
            // neuzavřené uvozovky — ošetření: vezmi vše (bez trimu) a pokračuj
            $value = substr($raw, 1);
        }
    } else {
        // ne-quoted -> odstraníme inline komentář (# ...) pokud existuje
        $valWithoutComment = preg_replace('/\s+#.*$/', '', $raw);
        $value = rtrim($valWithoutComment);
        // odstranit obalující mezery
        $value = trim($value);
    }

    // bezpečnost: pouze A-Z0-9_ (already validated by regex), ale ještě kontrola pro jistotu
    if (!preg_match('/^[A-Z][A-Z0-9_]*$/i', $key)) {
        continue;
    }

    // Nepřepisuj již existující nastavení (server má prioritu)
    if (array_key_exists($key, $_ENV) || array_key_exists($key, $_SERVER)) {
        continue;
    }

    // Ulož do $_ENV a $_SERVER (nevoláme putenv/getenv)
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}
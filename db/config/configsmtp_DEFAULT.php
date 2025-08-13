<?php
// db/config/configsmtp.php
// Vložte sem svoje SMTP údaje. Tento súbor by mal byť mimo VCS.

return [
    'use_smtp' => false,         // true = použiť priame SMTP (fsockopen AUTH LOGIN) - iba ak host podporuje
    'host' => 'smtp.example.com',
    'port' => 587,
    'secure' => 'tls',           // 'tls' alebo 'ssl' alebo '' pre plain
    'username' => 'user@example.com',
    'password' => 'tvoje_heslo',
    'from_email' => 'no-reply@knihyodautorov.sk',
    'from_name' => 'Knihy od autorov',
    'timeout' => 10
];

// Nechajte súbor bez výstupu.
// Bezpečnosť: v produkcii nastavte súbor mimo verejnú zložku alebo s obmedzeným prístupom.

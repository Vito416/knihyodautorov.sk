<?php
// /db/config/configsmtp.php
// VZOR konfigurácie SMTP. NEPOKRAČUJTE DO PRODUCTION bez úprav.
// Uložte tento súbor do /db/config/configsmtp.php a nastavte hodnoty.

$smtp_config = [
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

<?php
// secure/config.php
// MUST be placed outside webroot and not accessible publicly.
return [
    // PDO DSN like 'mysql:host=localhost;dbname=eshop;charset=utf8mb4'
    'db' => [
        'dsn' => 'mysql:host=127.0.0.1;dbname=knihy;charset=utf8mb4',
        'user' => 'dbuser',
        'pass' => 'dbpass',
        'options' => [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ],
    ],
    // Application paths (adjust)
    'paths' => [
        'storage' => __DIR__ . '/../../../storage', // outside webroot
        'uploads' => __DIR__ . '/../../../storage/uploads',
    ],
    // Crypto key for symmetric encryption (AES-256-GCM). Use 32 bytes base64.
    // Generate once: base64_encode(random_bytes(32)) and store safely.
    'crypto_key' => 'REPLACE_WITH_BASE64_32_BYTES',
    // OAuth Google
    'google' => [
        'client_id' => 'GOOGLE_CLIENT_ID',
        'client_secret' => 'GOOGLE_CLIENT_SECRET',
        'redirect_uri' => 'https://yourdomain.com/eshop/oauth_google_callback.php',
    ],
    // GoPay (placeholders)
    'gopay' => [
        'merchant_id' => 'GOPAY_MERCHANT_ID',
        'client_id' => 'GOPAY_CLIENT_ID',
        'client_secret' => 'GOPAY_CLIENT_SECRET',
        'return_url' => 'https://yourdomain.com/eshop/thank_you.php',
        'notify_url' => 'https://yourdomain.com/eshop/webhook_payment.php',
    ],
    // SMTP for sending mails (reset password etc.)
    'smtp' => [
        'host' => 'smtp.example.com',
        'port' => 587,
        'user' => 'smtp-user',
        'pass' => 'smtp-pass',
        'from_email' => 'noreply@yourdomain.com',
        'from_name' => 'Knihy od autorov',
    ],
];
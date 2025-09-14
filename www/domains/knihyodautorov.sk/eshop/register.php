<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php'; // Auth, Logger, $config

?>
<!DOCTYPE html>
<html lang="sk">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Registrácia</title>
<link rel="stylesheet" href="/css/register.css">
</head>
<body>

<div class="container">
  <h2>Registrácia</h2>
  <form id="register-form" method="post" novalidate>
    <?= \CSRF::hiddenInput('csrf_token') ?>
    
    <label for="email">Email:</label>
    <input type="email" id="email" name="email" placeholder="vas@email.sk" required>
    <div class="error-msg" id="email-error"></div>

    <label for="password">Heslo:</label>
    <input type="password" id="password" name="password" placeholder="Minimálne 12 znakov" required>
    <div class="error-msg" id="password-error"></div>

    <div id="register-messages"></div>

    <button type="submit">Registrovať sa</button>
  </form>
</div>

<!-- Modal -->
<div id="register-modal" class="modal" data-redirect="login.php">
  <div class="modal-content">
    <span class="modal-close">&times;</span>
    <p>Registrácia prebehla úspešne! Presmerovanie o chvíľu...</p>
    <button id="modal-ok">OK</button>
  </div>
</div>

<script src="/js/register.js"></script>
</body>
</html>
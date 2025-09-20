<?php
/** @var bool $success */
/** @var string $message */
$success = (bool)($success ?? false);
$message = $message ?? '';
?>
<!doctype html>
<html lang="sk">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Overenie e-mailu</title>
  <link rel="stylesheet" href="/eshop/css/login.css">
</head>
<body>
  <main class="container">
    <h1>Overenie e-mailu</h1>
    <p><?= htmlspecialchars($message) ?></p>
    <?php if ($success): ?>
      <p><a href="/eshop/login.php">Prihlásiť sa</a></p>
    <?php else: ?>
      <p><a href="/eshop/resend_verify.php">Znovu poslať overovací e-mail</a></p>
    <?php endif; ?>
  </main>
</body>
</html>

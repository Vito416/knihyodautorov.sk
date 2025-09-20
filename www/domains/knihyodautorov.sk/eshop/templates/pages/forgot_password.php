<?php
/** @var string $csrf */
?>
<!doctype html>
<html lang="sk">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Požiadavka na obnovenie hesla — e-shop</title>
  <link rel="stylesheet" href="/eshop/css/login.css">
</head>
<body>
  <main class="container">
    <h1>Obnovenie hesla</h1>

    <div id="forgotMessage" class="msg" aria-live="polite"></div>

    <form id="forgotForm" method="post" action="/eshop/actions/request_password_reset.php" novalidate>
      <?= $csrf ?? '' ?>

      <label for="email">Zadajte e-mail pre obnovenie hesla</label>
      <input id="email" name="email" type="email" required>

      <button type="submit">Poslať inštrukcie</button>
    </form>

    <p style="margin-top:12px;">
      <a href="/eshop/login.php">Prihlásiť sa</a>
    </p>
  </main>

  <script>
  document.addEventListener('DOMContentLoaded', function(){
    const f = document.getElementById('forgotForm');
    if (!f) return;
    f.addEventListener('submit', async function(e){
      e.preventDefault();
      const out = document.getElementById('forgotMessage');
      const fd = new FormData(f);
      const resp = await fetch(f.action, {method:'POST', body:fd, credentials:'same-origin'});
      const j = await resp.json();
      out.textContent = j.message || (j.success? 'Hotovo' : 'Chyba');
    });
  });
  </script>
</body>
</html>
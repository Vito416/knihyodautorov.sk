// File: www/eshop/reset_password.php
$csrfToShow = CSRF::generate();
} else {
$sessKey = 'reset_csrf_' . $row['pr_id'];
if (empty($_SESSION[$sessKey])) $_SESSION[$sessKey] = bin2hex(random_bytes(32));
$csrfToShow = $_SESSION[$sessKey];
}
}


?><!doctype html>
<html lang="sk">
<head>
<meta charset="utf-8">
<title>Zmena hesla</title>
<meta name="robots" content="noindex">
<style>body{font-family:Arial,Helvetica,sans-serif;max-width:700px;margin:2rem auto;padding:1rem}</style>
</head>
<body>
<h1>Zmena hesla</h1>


<?php if ($success): ?>
<div style="padding:1rem;background:#e6ffed;border:1px solid #b7f2c8">Heslo bolo úspešne zmenené. Môžete sa prihlásiť.</div>
<p><a href="/eshop/login.php">Prejsť na prihlásenie</a></p>
<?php else: ?>
<?php if (!empty($errors)): foreach ($errors as $err): ?>
<div style="padding:0.5rem;background:#fff1f0;border:1px solid #f5c2c7;margin-bottom:0.5rem"><?php echo e($err); ?></div>
<?php endforeach; endif; ?>


<form method="post" action="">
<label for="password">Nové heslo</label><br>
<input id="password" name="password" type="password" required style="width:100%;padding:0.5rem;margin:0.5rem 0"><br>
<label for="password_confirm">Potvrďte nové heslo</label><br>
<input id="password_confirm" name="password_confirm" type="password" required style="width:100%;padding:0.5rem;margin:0.5rem 0"><br>
<input type="hidden" name="csrf_token" value="<?php echo e($csrfToShow); ?>">
<button type="submit">Uložiť nové heslo</button>
</form>
<?php endif; ?>


</body>
</html>
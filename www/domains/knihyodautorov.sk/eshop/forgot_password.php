// File: www/eshop/forgot_password.php
]);


// Compose reset link
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'example.com';
$resetUrl = $scheme . '://' . $host . '/eshop/reset_password.php?token=' . $token;


// Send e-mail using Mailer if available, otherwise fallback to mail()
$subject = 'Obnovenie hesla';
$bodyHtml = "Dobrý deň,<br><br>Pre obnovu hesla kliknite na nasledujúci odkaz: <a href=\"" . e($resetUrl) . "\">Obnoviť heslo</a><br><br>Ak ste o to nežiadali, ignorujte túto správu.<br><br>S pozdravom,<br>Podpora";


$sent = false;
if (class_exists('Mailer')) {
try {
$mailer = new Mailer();
$sent = $mailer->sendMail($user['email'], $subject, $bodyHtml);
} catch (Throwable $ex) {
$sent = false;
}
} else {
// Fallback to mail() with basic headers
$headers = "MIME-Version: 1.0\r\n";
$headers .= "Content-type: text/html; charset=UTF-8\r\n";
$headers .= "From: no-reply@" . ($host) . "\r\n";
$sent = mail($user['email'], $subject, $bodyHtml, $headers);
}
// We do not reveal success/failure to the user
} catch (PDOException $e) {
// swallow details and log server-side
}
}
}
}
}


// Render form
$csrf = generateCsrfToken();
?><!doctype html>
<html lang="sk">
<head>
<meta charset="utf-8">
<title>Obnovenie hesla</title>
<meta name="robots" content="noindex">
<style>body{font-family:Arial,Helvetica,sans-serif;max-width:700px;margin:2rem auto;padding:1rem}</style>
</head>
<body>
<h1>Obnovenie hesla</h1>


<?php foreach ($messages as $m): ?>
<div style="padding:0.5rem;background:#f2f2f2;border-radius:4px;margin-bottom:0.5rem"><?php echo e($m); ?></div>
<?php endforeach; ?>


<form method="post" action="">
<label for="email">E‑mail</label><br>
<input id="email" name="email" type="email" required placeholder="vas@email.sk" style="width:100%;padding:0.5rem;margin:0.5rem 0"><br>
<input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
<button type="submit">Pošli odkaz na obnovenie</button>
</form>


</body>
</html>
<?php
// /admin/actions/mark-paid.php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
require __DIR__ . '/../../admin/bootstrap.php'; // načítaj $pdo, require_admin(), esc()
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
$csrf = $_POST['csrf'] ?? '';
if (!verify_csrf($csrf ?? '')) { http_response_code(403); echo "CSRF"; exit; }

$orderId = (int)($_POST['order_id'] ?? 0);
if ($orderId <= 0) { http_response_code(400); echo "Chýba order_id"; exit; }

// fetch order + items
$st = $pdo->prepare("SELECT * FROM orders WHERE id = ? LIMIT 1");
$st->execute([$orderId]);
$o = $st->fetch(PDO::FETCH_ASSOC);
if (!$o) { http_response_code(404); echo "Objednávka nenájdená"; exit; }

if ($o['status'] !== 'paid') {
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE orders SET status = 'paid', paid_at = NOW() WHERE id = ?")->execute([$orderId]);

        // vygeneruj tokeny (ak user existuje)
        $items = $pdo->prepare("SELECT book_id FROM order_items WHERE order_id = ?");
        $items->execute([$orderId]);
        $books = $items->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($o['user_id']) && $books) {
            $ttl = (int)(settings($pdo,'eshop_download_token_ttl') ?? 7);
            $expires = (new DateTime())->modify("+{$ttl} days")->format('Y-m-d H:i:s');
            $ins = $pdo->prepare("INSERT INTO download_tokens (user_id, book_id, token, expires_at, used, created_at) VALUES (?, ?, ?, ?, 0, NOW())");
            foreach ($books as $bid) {
                $token = bin2hex(random_bytes(20));
                $ins->execute([(int)$o['user_id'], (int)$bid, $token, $expires]);
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['admin_error'] = "Zlyhalo označenie ako zaplatené: ".$e->getMessage();
        header('Location: /admin/orders.php'); exit;
    }
}

// po označení skús poslať potvrdzovací email s info
try {
    $to = $o['email'] ?? null;
    if (!$to && !empty($o['user_id'])) {
        $u = $pdo->prepare("SELECT email FROM users WHERE id = ?"); $u->execute([(int)$o['user_id']]);
        $to = $u->fetchColumn() ?: null;
    }
    if ($to) {
        $subject = "Objednávka #{$o['id']} bola uhradená";
        $body = "Vaša objednávka #{$o['id']} bola označená ako uhradená. V sekcii Moje objednávky nájdete odkazy na stiahnutie.\n\nPriamy odkaz: ".(isset($_SERVER['HTTP_HOST']) ? 'https://'.$_SERVER['HTTP_HOST'] : '')."/eshop/order.php?id={$o['id']}";
        if (class_exists('\PHPMailer\PHPMailer\PHPMailer')) {
            $smtpCfg = [];
            $cfgFile = realpath(__DIR__ . '/../../db/config/configsmtp.php');
            if ($cfgFile && file_exists($cfgFile)) $smtpCfg = require $cfgFile;
            $mail = new \PHPMailer\PHPMailer\PHPMailer();
            if ($smtpCfg) {
                $mail->isSMTP();
                $mail->Host = $smtpCfg['host'] ?? '';
                $mail->SMTPAuth = true;
                $mail->Username = $smtpCfg['username'] ?? ($smtpCfg['user'] ?? '');
                $mail->Password = $smtpCfg['password'] ?? ($smtpCfg['pass'] ?? '');
                $mail->SMTPSecure = $smtpCfg['secure'] ?? 'tls';
                $mail->Port = $smtpCfg['port'] ?? 587;
                $mail->setFrom($smtpCfg['from_email'] ?? 'info@example.com', $smtpCfg['from_name'] ?? 'Eshop');
            }
            $mail->addAddress($to);
            $mail->Subject = $subject;
            $mail->Body = $body;
            $mail->send();
        } else {
            $headers = "From: " . (settings($pdo,'company_email') ?? 'info@example.com') . "\r\n";
            @mail($to, $subject, $body, $headers);
        }
    }
} catch (Throwable $e) {
    // logni, ale nepokaz UX
    error_log("mark-paid mail error: ".$e->getMessage());
}

$_SESSION['admin_msg'] = "Objednávka #{$o['id']} bola označená ako paid.";
header('Location: /admin/orders.php');
exit;
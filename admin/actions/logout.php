<?php
// /admin/actions/logout.php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../partials/notifications.php';

try {
    // Accept POST with CSRF (safer) or GET? We'll support both but POST preferred.
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['admin_csrf_token'] ?? null;
        if (!admin_csrf_check($token)) {
            admin_flash_set('error', 'Neplatný CSRF token. Odhlásenie zrušené.');
            header('Location: /admin/index.php'); exit;
        }
        // Destroy admin session user
        unset($_SESSION['admin_user_id']);
        session_regenerate_id(true);
        admin_flash_set('success', 'Úspešne odhlásené.');
        header('Location: /admin/login.php'); exit;
    } else {
        // show confirmation form
        ?>
        <!doctype html><html lang="sk"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Odhlásiť</title></head><body>
        <main style="font-family:system-ui;padding:28px;">
          <h1>Odhlásiť sa</h1>
          <p>Naozaj sa chcete odhlásiť?</p>
          <form method="post" action="/admin/actions/logout.php">
            <?php echo admin_csrf_input(); ?>
            <button type="submit" style="padding:10px 14px;border-radius:8px;background:#cf9b3a;border:none;color:#2b1608;font-weight:800">Odhlásiť</button>
            <a href="/admin/index.php" style="margin-left:12px">Zrušiť</a>
          </form>
        </main>
        </body></html>
        <?php
        exit;
    }
} catch (Throwable $e) {
    error_log('logout.php error: ' . $e->getMessage());
    header('Location: /admin/index.php'); exit;
}
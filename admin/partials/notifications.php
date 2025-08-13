<?php
// /admin/partials/notifications.php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();

// --- flash / toast helpers ---
if (!function_exists('admin_flash_set')) {
    function admin_flash_set(string $type, string $message): void {
        if (!isset($_SESSION['admin_flash']) || !is_array($_SESSION['admin_flash'])) $_SESSION['admin_flash'] = [];
        $_SESSION['admin_flash'][] = ['type'=>$type, 'message'=>$message, 'ts'=>time()];
    }
}
if (!function_exists('admin_flash_get_all')) {
    function admin_flash_get_all(): array {
        $f = $_SESSION['admin_flash'] ?? [];
        unset($_SESSION['admin_flash']);
        return is_array($f) ? $f : [];
    }
}
if (!function_exists('admin_flash_render')) {
    function admin_flash_render(): void {
        $items = admin_flash_get_all();
        if (empty($items)) return;
        ?>
        <div id="admin-flash-wrap" aria-live="polite" aria-atomic="true">
          <?php foreach ($items as $it): ?>
            <div class="admin-flash admin-flash-<?php echo htmlspecialchars($it['type']); ?>">
              <?php echo htmlspecialchars($it['message']); ?>
            </div>
          <?php endforeach; ?>
        </div>
        <style>
          #admin-flash-wrap{position:fixed;right:18px;bottom:18px;z-index:1500;display:flex;flex-direction:column;gap:10px}
          .admin-flash{background:#fff;padding:10px 14px;border-radius:10px;box-shadow:0 20px 50px rgba(0,0,0,0.12);font-weight:700;color:#2b1608}
          .admin-flash-error{background:linear-gradient(180deg,#fff0f0,#fff);border-left:4px solid #c33;color:#7b1}
          .admin-flash-success{background:linear-gradient(180deg,#fbf8ef,#fff);border-left:4px solid #cf9b3a}
          .admin-flash-info{background:linear-gradient(180deg,#f3f7ff,#fff);border-left:4px solid #4b7}
        </style>
        <script>
          (function(){
            try{
              const wrap = document.getElementById('admin-flash-wrap');
              if(!wrap) return;
              setTimeout(()=> {
                wrap.querySelectorAll('.admin-flash').forEach((el,i)=>{
                  setTimeout(()=> el.style.transform='translateX(6px)', i*120);
                  setTimeout(()=> el.remove(), 6000 + i*200);
                });
              }, 80);
            }catch(e){}
          })();
        </script>
        <?php
    }
}

// --- CSRF helpers (lightweight, file-local) ---
if (!function_exists('admin_csrf_token')) {
    function admin_csrf_token(): string {
        if (session_status() === PHP_SESSION_NONE) session_start();
        if (empty($_SESSION['admin_csrf_token']) || !is_string($_SESSION['admin_csrf_token'])) {
            $_SESSION['admin_csrf_token'] = bin2hex(random_bytes(24));
        }
        return $_SESSION['admin_csrf_token'];
    }
}
if (!function_exists('admin_csrf_input')) {
    function admin_csrf_input(): string {
        return '<input type="hidden" name="admin_csrf_token" value="'.htmlspecialchars(admin_csrf_token(), ENT_QUOTES).'">';
    }
}
if (!function_exists('admin_csrf_check')) {
    function admin_csrf_check(?string $token): bool {
        if (session_status() === PHP_SESSION_NONE) session_start();
        if (empty($_SESSION['admin_csrf_token'])) return false;
        if (!is_string($token)) return false;
        // Use hash_equals for timing-safe compare
        $ok = hash_equals((string)$_SESSION['admin_csrf_token'], (string)$token);
        return $ok;
    }
}
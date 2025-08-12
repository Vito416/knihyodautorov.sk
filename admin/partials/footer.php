<?php
// /admin/partials/footer.php
declare(strict_types=1);
if (!function_exists('admin_esc')) {
    function admin_esc($s) {
        if (function_exists('esc')) return esc($s);
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
$year = date('Y');
?>
<footer class="admin-footer" role="contentinfo" aria-label="Pätička administrácie">
  <div class="admin-footer-inner">
    <div class="admin-footer-left">
      <small>© <?php echo admin_esc($year); ?> Knihy od autorov — administrácia</small>
    </div>
    <div class="admin-footer-right">
      <small>Verzia systému: <strong>1.0</strong></small>
    </div>
  </div>
</footer>

<script src="/admin/js/admin.js" defer></script>
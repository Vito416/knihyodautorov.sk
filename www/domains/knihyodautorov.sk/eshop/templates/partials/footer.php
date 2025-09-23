<?php
// templates/partials/footer.php
declare(strict_types=1);

/**
 * Footer partial.
 * - Obsahuje jednoduchý footer s odkazmi a copyright.
 * - Vloží JS súbor /eshop/js/app.js defer.
 */

$year = date('Y');
$appName = $_ENV['APP_NAME'] ?? 'KnihyOdAutorov';
?>
<footer class="site-footer" role="contentinfo">
    <div class="wrap">
        <div class="footer-left">
            &copy; <?= (int)$year ?> <?= htmlspecialchars($appName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>. Všetky práva vyhradené.
        </div>
        <div class="footer-right">
            <a href="/eshop/privacy.php">Ochrana osobných údajov</a> |
            <a href="/eshop/terms.php">Obchodné podmienky</a> |
            <a href="/eshop/contact.php">Kontakt</a>
        </div>
        <div style="clear:both;"></div>
    </div>
</footer>

<!-- JS -->
<script src="/eshop/js/app.js" defer></script>
</body>
</html>
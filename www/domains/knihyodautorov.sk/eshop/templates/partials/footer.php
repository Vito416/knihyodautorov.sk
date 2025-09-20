<?php
// templates/partials/footer.php
declare(strict_types=1);

$appName = 'E-shop';
$year = (int) date('Y');
?>
</main>

<footer class="site-footer" role="contentinfo" aria-label="Päta stránky">
    <div class="wrap">
        <div class="footer-left">
            &copy; <?= $year ?> <?= htmlspecialchars($appName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
        </div>
        <div class="footer-right">
            <a href="/eshop/terms.php" rel="noopener">Obchodné podmienky</a>
            <span aria-hidden="true"> | </span>
            <a href="/eshop/privacy.php" rel="noopener">Ochrana osobných údajov</a>
        </div>
    </div>
</footer>

<script src="/eshop/js/app.js" defer></script>
</body>
</html>
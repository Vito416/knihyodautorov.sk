<?php
// templates/partials/flash.php
declare(strict_types=1);

/**
 * Flash messages partial.
 * Expects: $_SESSION['flash'] = [
 *     'info' => ['msg1', 'msg2'],
 *     'success' => [...],
 *     'warning' => [...],
 *     'error' => [...]
 * ];
 *
 * Renders accessible flash messages a následne ich vymaže zo session.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$types = ['info', 'success', 'warning', 'error'];

if (!empty($_SESSION['flash'])): ?>
<div class="flash-messages" role="status" aria-live="polite">
    <?php foreach ($types as $type):
        if (empty($_SESSION['flash'][$type])) continue;
        foreach ($_SESSION['flash'][$type] as $msg): ?>
            <div class="flash-<?= htmlspecialchars($type, ENT_QUOTES) ?>">
                <div class="flash-body"><?= htmlspecialchars($msg, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                <button type="button" class="flash-dismiss" aria-label="Zavrieť správu">&times;</button>
            </div>
        <?php endforeach;
    endforeach; ?>
</div>
<?php 
// Po zobrazení vymažeme správy
unset($_SESSION['flash']);
endif;
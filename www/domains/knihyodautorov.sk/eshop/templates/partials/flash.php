<?php
// templates/partials/flash.php
declare(strict_types=1);

// Read flash from $flash variable or from session (consumed)
if (!isset($flash) || $flash === null) {
    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    if (!empty($_SESSION['flash']) && is_array($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
    } else {
        $flash = [];
    }
}

// normalize single-message or associative single message to sequential array
if (!is_array($flash) || (array_values($flash) !== $flash && isset($flash['msg']))) {
    // single associative or scalar message
    if (!is_array($flash)) {
        $flash = [['type' => 'info', 'msg' => (string)$flash]];
    } else {
        $flash = [$flash];
    }
}

$messages = [];
foreach ($flash as $f) {
    if (!is_array($f)) {
        $messages[] = ['type' => 'info', 'msg' => (string)$f, 'autoclose' => null, 'id' => 'flash-' . bin2hex(random_bytes(6))];
        continue;
    }
    $type = isset($f['type']) ? strtolower((string)$f['type']) : 'info';
    if (!in_array($type, ['info','success','warning','error'], true)) $type = 'info';
    $msg = $f['msg'] ?? '';
    $autoclose = isset($f['autoclose']) && is_int($f['autoclose']) ? (int)$f['autoclose'] : null;
    $id = isset($f['id']) ? preg_replace('/[^a-z0-9_-]/i', '', (string)$f['id']) : 'flash-' . bin2hex(random_bytes(6));
    $messages[] = ['type' => $type, 'msg' => $msg, 'autoclose' => $autoclose, 'id' => $id];
}

if (empty($messages)) {
    return;
}
?>
<div class="flash-messages" aria-live="polite" aria-atomic="false" role="status">
    <?php foreach ($messages as $m):
        $type = $m['type'];
        $id = $m['id'];
        $autoclose = $m['autoclose'];
        $raw = $m['msg'];

        $cls = 'flash-' . $type;
        // allow raw rendering only for objects that look like SafeHtml
        $allowRaw = false;
        if (is_object($raw) && method_exists($raw, '__toString')) {
            $className = get_class($raw);
            if (preg_match('/(?:safe|html|raw)/i', $className)) $allowRaw = true;
        }
        $text = is_object($raw) ? (method_exists($raw, '__toString') ? (string)$raw : '') : (string)$raw;
    ?>
        <div id="<?= htmlspecialchars($id, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
             class="<?= htmlspecialchars($cls, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
             role="alert"
             aria-live="<?= $type === 'error' || $type === 'warning' ? 'assertive' : 'polite' ?>"
             data-flash-type="<?= htmlspecialchars($type, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
             <?= $autoclose !== null ? 'data-autoclose="' . (int)$autoclose . '"' : '' ?>>

            <div class="flash-body">
                <?php
                if ($allowRaw) {
                    // even for SafeHtml-like objects: output __toString() result (assumed safe)
                    echo $text;
                } else {
                    echo nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
                }
                ?>
            </div>

            <button type="button"
                    class="flash-dismiss"
                    aria-label="Zavrieť"
                    data-flash-dismiss="<?= htmlspecialchars($id, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                &times;
            </button>
        </div>
    <?php endforeach; ?>
</div>
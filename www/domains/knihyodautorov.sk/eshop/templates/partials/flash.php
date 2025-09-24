<?php
declare(strict_types=1);
$flashes = $_SESSION['flash'] ?? null;
if (empty($flashes) || !is_array($flashes)) return;
?>
<div class="flash-messages" role="status" aria-live="polite">
  <?php foreach ($flashes as $f):
    $type = $f['type'] ?? 'info';
    $msg = $f['message'] ?? '';
    $class = 'flash-info';
    if ($type === 'success') $class = 'flash-success';
    if ($type === 'warning') $class = 'flash-warning';
    if ($type === 'error') $class = 'flash-error';
  ?>
    <div class="<?= htmlspecialchars($class) ?>">
      <div class="flash-body"><?= nl2br(htmlspecialchars((string)$msg, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?></div>
      <button class="flash-dismiss" title="Zavrieť správu" aria-label="Zavrieť správu">✕</button>
    </div>
  <?php endforeach; ?>
</div>
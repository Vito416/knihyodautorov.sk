<?php
/**
 * @var array $user
 * @var string|null $status
 * @var array $messages
 * @var string $csrf_token
 */

$partials = __DIR__ . '/../partials';
try { require_once $partials . '/header.php'; } catch (\Throwable $_) {}

?>

<div class="container py-4">

    <h1>Môj profil</h1>

    <?php if (!empty($messages)): ?>
        <div class="alert alert-info">
            <ul class="mb-0">
                <?php foreach ($messages as $msg): ?>
                    <li><?= htmlspecialchars($msg) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <!-- Změna jména -->
    <div class="card mb-4">
        <div class="card-header">Zmena mena</div>
        <div class="card-body">
            <form method="post">
                <input type="hidden" name="action" value="update_name">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

                <div class="mb-3">
                    <label for="display_name" class="form-label">Meno a priezvisko</label>
                    <input type="text" class="form-control" id="display_name" name="display_name"
                           value="<?= htmlspecialchars($user['display_name'] ?? '') ?>" required>
                </div>

                <button type="submit" class="btn btn-primary">Uložiť</button>
            </form>
        </div>
    </div>

    <!-- Změna e-mailu -->
    <div class="card mb-4">
        <div class="card-header">Zmena e-mailu</div>
        <div class="card-body">
            <form method="post">
                <input type="hidden" name="action" value="update_email">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

                <div class="mb-3">
                    <label for="email" class="form-label">E-mail</label>
                    <input type="email" class="form-control" id="email" name="email"
                           value="<?= htmlspecialchars($user['email'] ?? '') ?>" required>
                </div>

                <button type="submit" class="btn btn-primary">Uložiť</button>
            </form>
        </div>
    </div>

    <!-- Změna hesla -->
    <div class="card mb-4">
        <div class="card-header">Zmena hesla</div>
        <div class="card-body">
            <form method="post">
                <input type="hidden" name="action" value="update_password">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

                <div class="mb-3">
                    <label for="old_password" class="form-label">Staré heslo</label>
                    <input type="password" class="form-control" id="old_password" name="old_password" required>
                </div>

                <div class="mb-3">
                    <label for="new_password" class="form-label">Nové heslo</label>
                    <input type="password" class="form-control" id="new_password" name="new_password" required>
                </div>

                <div class="mb-3">
                    <label for="new_password2" class="form-label">Potvrdenie nového hesla</label>
                    <input type="password" class="form-control" id="new_password2" name="new_password2" required>
                </div>

                <button type="submit" class="btn btn-primary">Zmeniť heslo</button>
            </form>
        </div>
    </div>
</div>

<?php
$footer = __DIR__ . '/../partials/footer.php';
if (file_exists($footer)) {
    try { include $footer; } catch (\Throwable $_) {}
}
?>
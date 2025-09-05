<?php
// libs/EnforcePasswordChange.php
// Use this at the beginning of pages where you want to force password change: require and call EnforcePasswordChange::check($db)
class EnforcePasswordChange {
    public static function check(PDO $db) {
        if (empty($_SESSION['user_id'])) return;
        $stmt = $db->prepare('SELECT must_change_password FROM pouzivatelia WHERE id = ? LIMIT 1');
        $stmt->execute([$_SESSION['user_id']]);
        $v = $stmt->fetchColumn();
        if ($v) {
            // redirect to change_password.php
            header('Location: /eshop/change_password.php'); exit;
        }
    }
}
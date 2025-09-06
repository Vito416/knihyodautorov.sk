<?php
class Auth {
    public static function loginWithPassword(PDO $db, $email, $password){
        $stmt = $db->prepare('SELECT id, heslo_hash, is_active, is_locked, must_change_password FROM pouzivatelia WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $u = $stmt->fetch();
        if (!$u) return [false,'Neznámy účet'];
        if (!$u['is_active']) return [false,'Účet nie je aktívny'];
        if ($u['is_locked']) return [false,'Účet je zablokovaný'];
        if (!password_verify($password, $u['heslo_hash'])) {
            // increment failed_logins
            $stmt = $db->prepare('UPDATE pouzivatelia SET failed_logins = failed_logins + 1 WHERE id = ?');
            $stmt->execute([$u['id']]);
            return [false,'Nesprávne heslo'];
        }
        // success
        session_regenerate_id(true);
        $_SESSION['user_id'] = $u['id'];
        // create persistent session record
        $sid = bin2hex(random_bytes(32));
        $stmt = $db->prepare('INSERT INTO sessions (id, user_id, created_at, last_seen_at, expires_at, ip, user_agent) VALUES (?, ?, NOW(), NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), ?, ?)');
        $stmt->execute([$sid,$u['id'], $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '']);
        setcookie('session_token', $sid, time()+60*60*24*30, '/', '', isset($_SERVER['HTTPS']), true);
        return [true,'OK'];
    }
    public static function requireLogin(){
        if (empty($_SESSION['user_id'])) {
            header('Location: /eshop/login.php'); exit;
        }
    }
    public static function logout(PDO $db){
        if (!empty($_COOKIE['session_token'])){
            $stmt = $db->prepare('UPDATE sessions SET revoked = 1 WHERE id = ?');
            $stmt->execute([$_COOKIE['session_token']]);
            setcookie('session_token','',time()-3600,'/','',isset($_SERVER['HTTPS']),true);
        }
        session_unset(); session_destroy();
    }
}
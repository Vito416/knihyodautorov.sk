<?php
// Minimal Google OAuth callback handler (manual flow, no external libs).
require __DIR__ . '/inc/bootstrap.php';
// Config
$googleCfg = $cfg['google'] ?? null;
if (!$googleCfg || empty($googleCfg['client_id']) || empty($googleCfg['client_secret'])) {
    echo 'Google OAuth nie je nakonfigurovaný'; exit;
}
$code = $_GET['code'] ?? null;
if (!$code) { echo 'Chýba code'; exit; }
// Exchange code for token
$tokenUrl = 'https://oauth2.googleapis.com/token';
$post = http_build_query([
    'code'=>$code,
    'client_id'=>$googleCfg['client_id'],
    'client_secret'=>$googleCfg['client_secret'],
    'redirect_uri'=>$googleCfg['redirect_uri'],
    'grant_type'=>'authorization_code',
]);
$opts = ['http'=>['method'=>'POST','header'=>"Content-Type: application/x-www-form-urlencoded\r\n",'content'=>$post]];
$context = stream_context_create($opts);
$res = file_get_contents($tokenUrl, false, $context);
if (!$res) { echo 'Token exchange failed'; exit; }
$tok = json_decode($res, true);
$access = $tok['access_token'] ?? null;
if (!$access) { echo 'No access token'; exit; }
// Get userinfo
$userinfo = file_get_contents('https://www.googleapis.com/oauth2/v2/userinfo?access_token='.urlencode($access));
if (!$userinfo) { echo 'Userinfo failed'; exit; }
$ui = json_decode($userinfo, true);
$email = $ui['email'] ?? null;
$google_id = $ui['id'] ?? null;
if (!$email || !$google_id) { echo 'Neúplné údaje'; exit; }
// Find or create user
$stmt = $db->prepare('SELECT ui.user_id FROM user_identities ui WHERE ui.provider = ? AND ui.provider_user_id = ? LIMIT 1');
$stmt->execute(['google', $google_id]);
$uid = $stmt->fetchColumn();
if ($uid) {
    // login
    $_SESSION['user_id'] = $uid;
    header('Location: /eshop/'); exit;
}
// try find by email
$stmt = $db->prepare('SELECT id FROM pouzivatelia WHERE email = ? LIMIT 1');
$stmt->execute([$email]);
$uid = $stmt->fetchColumn();
if (!$uid) {
    // create new user
    $hash = password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT); // random password
    $stmt = $db->prepare('INSERT INTO pouzivatelia (email, heslo_hash, is_active, created_at) VALUES (?, ?, 1, NOW())');
    $stmt->execute([$email, $hash]);
    $uid = $db->lastInsertId();
}
// create user_identity
$stmt = $db->prepare('INSERT INTO user_identities (user_id, provider, provider_user_id, email_verified, raw_profile, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
$stmt->execute([$uid, 'google', $google_id, 1, json_encode($ui)]);
// login
$_SESSION['user_id'] = $uid;
header('Location: /eshop/');
exit;
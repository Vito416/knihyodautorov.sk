<?php
// cron/webhook_retry.php
// CLI script to retry failed webhook deliveries from webhook_queue table or job_queue
// Run from cron: php /path/to/cron/webhook_retry.php
require __DIR__ . '/../secure/config.php';
require __DIR__ . '/../libs/autoload.php';
Database::init($config['db'] ?? $dbConfig ?? []); // allow older name
$db = Database::get();
// Fetch failed webhooks (table webhook_queue: id, payload, attempts, max_attempts, last_error, status)
$stmt = $db->query("SELECT * FROM webhook_queue WHERE status IN ('failed','pending') AND attempts < max_attempts ORDER BY created_at ASC LIMIT 20");
$rows = $stmt->fetchAll();
foreach($rows as $r){
    $id = $r['id'];
    $payload = json_decode($r['payload'], true);
    // attempt delivery - simple POST to target_url stored in table
    $target = $r['target_url'] ?? '';
    if (!$target) {
        $db->prepare('UPDATE webhook_queue SET status="failed", last_error=? WHERE id=?')->execute(['No target', $id]);
        continue;
    }
    $ch = curl_init($target);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $res = curl_exec($ch);
    $info = curl_getinfo($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($info['http_code'] >= 200 && $info['http_code'] < 300) {
        $db->prepare('UPDATE webhook_queue SET status="delivered", delivered_at=NOW(), last_response = ? WHERE id=?')->execute([$res, $id]);
    } else {
        $db->prepare('UPDATE webhook_queue SET attempts = attempts + 1, last_error = ?, last_response = ? WHERE id=?')->execute([$err ?: 'HTTP_'.$info['http_code'], $res, $id]);
    }
}
echo "Done\n";
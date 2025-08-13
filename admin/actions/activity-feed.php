<?php
// /admin/actions/activity-feed.php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Jednoduchý endpoint pre activity feed na dashboard. Vracia JSON.
// - probe=1 -> rýchla odpoveď {ok:true} (slúži JS na aktiváciu feedu)
// - since=<int> -> vráti len položky s id > since
// Id je generované ako (timestamp_seconds * 1000 + seq)
// Endpoint vyžaduje admin session.

require_once __DIR__ . '/../bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

try {
    require_admin(); // redirectne/exit ak nie je admin

    // probe param (rýchla kontrola dostupnosti)
    if (isset($_GET['probe'])) {
        echo json_encode(['ok' => true, 'message' => 'probe ok']);
        exit;
    }

    $since = isset($_GET['since']) ? (int)$_GET['since'] : 0;

    $items = [];
    $seq = 1;

    // Pomocná: bezpečné získanie dát (indexy polohovo závislé)
    // 1) najnovšie objednávky
    try {
        $stmt = $pdo->query("SELECT id, total_price, status, created_at, user_id FROM orders ORDER BY created_at DESC LIMIT 10");
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($orders as $o) {
            $t = strtotime($o['created_at']) ?: time();
            $id = $t * 1000 + $seq++;
            $items[] = [
                'id' => $id,
                'type' => 'order',
                'title' => 'Nová/al. objednávka #' . (int)$o['id'],
                'message' => 'Cena: ' . number_format((float)$o['total_price'], 2, ',', '.') . ' ' . ($o['currency'] ?? 'EUR') . ' — stav: ' . ($o['status'] ?? ''),
                'time' => date('Y-m-d H:i:s', $t),
                'meta' => ['order_id' => (int)$o['id'], 'user_id' => (int)$o['user_id']]
            ];
        }
    } catch (Throwable $e) {
        // ignorovať chyby, pokračovať
    }

    // 2) najnovší užívatelia
    try {
        // používame slovensky pomenovaný stĺpec datum_registracie
        $stmt = $pdo->query("SELECT id, meno, email, datum_registracie FROM users ORDER BY datum_registracie DESC LIMIT 8");
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($users as $u) {
            $t = strtotime($u['datum_registracie']) ?: time();
            $id = $t * 1000 + $seq++;
            $items[] = [
                'id' => $id,
                'type' => 'user',
                'title' => 'Nový užívateľ: ' . ($u['meno'] ?: $u['email']),
                'message' => ($u['email'] ?? ''),
                'time' => date('Y-m-d H:i:s', $t),
                'meta' => ['user_id' => (int)$u['id']]
            ];
        }
    } catch (Throwable $e) {}

    // 3) najnovšie knihy
    try {
        $stmt = $pdo->query("SELECT b.id, b.nazov, b.created_at, a.meno as autor FROM books b LEFT JOIN authors a ON b.author_id = a.id ORDER BY b.created_at DESC LIMIT 8");
        $books = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($books as $b) {
            $t = strtotime($b['created_at']) ?: time();
            $id = $t * 1000 + $seq++;
            $items[] = [
                'id' => $id,
                'type' => 'book',
                'title' => 'Nová kniha: ' . ($b['nazov'] ?? '—'),
                'message' => 'Autor: ' . ($b['autor'] ?? '—'),
                'time' => date('Y-m-d H:i:s', $t),
                'meta' => ['book_id' => (int)$b['id']]
            ];
        }
    } catch (Throwable $e) {}

    // 4) recenzie (ak sú)
    try {
        $stmt = $pdo->query("SELECT r.id, r.book_id, r.user_id, r.rating, r.created_at FROM reviews r ORDER BY r.created_at DESC LIMIT 6");
        $rev = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rev as $r) {
            $t = strtotime($r['created_at']) ?: time();
            $id = $t * 1000 + $seq++;
            $items[] = [
                'id' => $id,
                'type' => 'review',
                'title' => 'Nová recenzia (book #' . (int)$r['book_id'] . ')',
                'message' => 'Hodnotenie: ' . (int)$r['rating'],
                'time' => date('Y-m-d H:i:s', $t),
                'meta' => ['review_id' => (int)$r['id']]
            ];
        }
    } catch (Throwable $e) {}

    // Zoradiť podľa id DESC
    usort($items, function($a, $b){ return $b['id'] <=> $a['id']; });

    // Aplikovať since ak bol zadaný
    if ($since > 0) {
        $items = array_values(array_filter($items, function($it) use ($since){ return (int)$it['id'] > $since; }));
    }

    // limit výsledkov (bezpečne)
    $items = array_slice($items, 0, 40);

    $latest_id = 0;
    if (!empty($items)) $latest_id = (int)$items[0]['id'];

    echo json_encode(['ok' => true, 'latest_id' => $latest_id, 'items' => $items], JSON_UNESCAPED_UNICODE);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Chyba servera', 'err' => $e->getMessage()]);
    exit;
}
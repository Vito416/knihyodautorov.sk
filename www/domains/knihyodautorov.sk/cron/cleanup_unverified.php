<?php
require __DIR__ . '/inc/bootstrap.php';

// smaž neověřené účty starší než 7 dní, které nemají objednávky
$days = 7;
try {
    // najdi user_id, které splňují podmínky
    $sel = $db->prepare("
        SELECT u.id
        FROM pouzivatelia u
        LEFT JOIN orders o ON o.user_id = u.id
        WHERE u.is_active = 0
          AND u.created_at < (NOW() - INTERVAL ? DAY)
        GROUP BY u.id
        HAVING COUNT(o.id) = 0
        LIMIT 1000
    ");
    $sel->execute([$days]);
    $ids = $sel->fetchAll(PDO::FETCH_COLUMN);
    if (!$ids) exit(0);

    $db->beginTransaction();
    // smazeme uživatele (ON DELETE CASCADE vyčistí většinu)
    $in = implode(',', array_fill(0, count($ids), '?'));
    $db->prepare("DELETE FROM pouzivatelia WHERE id IN ($in)")->execute($ids);

    // také smaž staré tokeny bez uživatele (opatrně)
    $db->prepare("DELETE FROM email_verifications WHERE expires_at < NOW()")->execute();

    $db->commit();
} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('[cleanup_unverified] ' . $e->getMessage());
    exit(1);
}
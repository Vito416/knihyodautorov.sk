<?php
declare(strict_types=1);

/**
 * cron/Worker.php
 *
 * Univerzální, epický cron worker pro různé úlohy.
 * - Notification queue (Mailer)
 * - Cleanup old records
 * - Report generation
 * - Custom jobs
 *
 * Použití:
 * require_once __DIR__ . '/../bootstrap.php';
 * require_once __DIR__ . '/Worker.php';
 * Worker::init($pdo);
 * Worker::notification();
 * Worker::cleanup();
 * Worker::report();
 */

final class Worker
{
    private static ?PDO $pdo = null;
    private static bool $inited = false;

    /** @var array job registry: name => callable */
    private static array $jobs = [];

    public static function init(PDO $pdo): void
    {
        self::$pdo = $pdo;
        self::$inited = true;
        Logger::systemMessage('info', 'Worker initialized');
    }

    // -------------------- Notifications --------------------
    public static function notification(int $limit = 100, bool $immediate = false): array
    {
        if (!self::$inited) throw new RuntimeException('Worker not initialized.');
        if (!class_exists('Mailer')) throw new RuntimeException('Mailer lib missing.');

        $report = [];

        if ($immediate) {
            // pokus o odeslání všech pending + failed hned
            $report = Mailer::processPendingNotifications($limit);
        } else {
            // jen "enqueue" fallback, worker si je vezme při dalším spuštění
            $report = ['info' => 'Immediate processing disabled, fallback to queue only'];
        }

        Logger::systemMessage('info', 'Notification worker finished', null, $report);
        return $report;
    }

    // -------------------- Cleanup old notifications --------------------
    public static function cleanup(int $days = 30): int
    {
        if (!self::$inited) throw new RuntimeException('Worker not initialized.');
        $stmt = self::$pdo->prepare('DELETE FROM notifications WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY) AND status = "sent"');
        $stmt->execute([$days]);
        $count = $stmt->rowCount();
        Logger::systemMessage('info', 'Cleanup old notifications', null, ['deleted' => $count]);
        return $count;
    }

    // -------------------- Status report --------------------
    public static function report(): void
    {
        if (!self::$inited) throw new RuntimeException('Worker not initialized.');
        $stmt = self::$pdo->query('SELECT status, COUNT(*) AS cnt FROM notifications GROUP BY status');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        Logger::systemMessage('info', 'Notification status report', null, ['report' => $rows]);
    }

    // -------------------- Register custom jobs --------------------
    public static function registerJob(string $name, callable $callback): void
    {
        self::$jobs[$name] = $callback;
        Logger::systemMessage('info', "Registered custom job {$name}");
    }

    public static function runJob(string $name, array $args = []): void
    {
        if (!isset(self::$jobs[$name])) {
            Logger::systemMessage('warning', "Job {$name} not found");
            return;
        }
        Logger::systemMessage('info', "Running job {$name}");
        try {
            call_user_func_array(self::$jobs[$name], $args);
            Logger::systemMessage('info', "Job {$name} finished successfully");
        } catch (\Throwable $e) {
            Logger::systemError($e);
        }
    }

    // -------------------- Utility: atomic lock --------------------
    public static function lock(string $lockName, int $ttl = 300): bool
    {
        $stmt = self::$pdo->prepare('INSERT IGNORE INTO worker_locks (name, locked_until) VALUES (?, DATE_ADD(NOW(), INTERVAL ? SECOND))');
        $stmt->execute([$lockName, $ttl]);
        return $stmt->rowCount() > 0;
    }

    public static function unlock(string $lockName): void
    {
        $stmt = self::$pdo->prepare('DELETE FROM worker_locks WHERE name = ?');
        $stmt->execute([$lockName]);
    }

    // -------------------- Example: full workflow for registration mail --------------------
    public static function registrationMail(int $userId, array $payload): void
    {
        if (!class_exists('Mailer')) throw new RuntimeException('Mailer lib missing.');

        try {
            $payload['user_id'] = $userId;

            // enqueue mail
            $id = Mailer::enqueue($payload);
            Logger::systemMessage('info', 'Notification enqueued', $userId, ['notification_id' => $id]);

            // attempt immediate send (pokud SMTP dostupný)
            $report = Mailer::processPendingNotifications(1); // limit 1 = aktuální mail
            Logger::systemMessage('info', 'Immediate send attempt', $userId, $report);
        } catch (\Throwable $e) {
            Logger::systemError($e);
        }
    }
}
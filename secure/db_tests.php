<?php
// tests/web_run_database_tests.php
declare(strict_types=1);

/**
 * Web-runner to exercise BlackCat\Core\Database in-memory (sqlite) fully.
 * Place in your project (e.g. <project_root>/tests/web_run_database_tests.php) and open
 * in browser: https://your-site/.../tests/web_run_database_tests.php?token=YOUR_TOKEN
 *
 * SECURITY: create secure/test_runner_token containing a secret token (recommended)
 * and remove this file + token after running.
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);
set_time_limit(120);
header('Content-Type: text/html; charset=utf-8');

echo "<!doctype html><html><head><meta charset=\"utf-8\"><title>Database full test runner</title>\n";
echo "<style>body{font-family:system-ui,Segoe UI,Roboto,Arial;margin:18px}pre{background:#111;color:#eee;padding:12px;border-radius:6px;overflow:auto} .ok{color:green}.fail{color:red}</style>";
echo "</head><body><h2>Database full test runner</h2>";

$root = realpath(__DIR__ . '/../../..') ?: __DIR__;
$tokenFile = $root . '/secure/test_runner_token';
$expectedToken = '123456';
if (file_exists($tokenFile)) {
    $expectedToken = trim((string)@file_get_contents($tokenFile));
}
$provided = $_GET['token'] ?? $_SERVER['HTTP_X_TEST_TOKEN'] ?? null;
$remote = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

if ($expectedToken !== null) {
    if (!$provided || !hash_equals($expectedToken, $provided)) {
        http_response_code(403);
        echo "<p class='fail'>Forbidden — invalid token. Caller IP: " . htmlspecialchars($remote) . "</p>";
        echo "<p>Create {$tokenFile} with a secret and call with ?token=...</p></body></html>";
        exit;
    }
} else {
    if (!$provided) {
        http_response_code(403);
        echo "<p class='fail'>Forbidden — no token provided. Create secure/test_runner_token or call with ?token=YOUR_TOKEN</p></body></html>";
        exit;
    }
    echo "<p style='color:orange'>Warning: no token file found — using GET token (less secure).</p>";
}

// include autoloads or Database directly
$included = false;
$tryPaths = [
    $root . '/vendor/autoload.php',
    $root . '/libs/autoload.php',
    $root . '/src/autoload.php',
];
foreach ($tryPaths as $p) {
    if (file_exists($p)) { require_once $p; $included = true; echo "<p>[info] Included autoload: " . htmlspecialchars($p) . "</p>"; break; }
}
if (!$included) {
    $candidates = [
        $root . '/src/BlackCat/Core/Database.php',
        $root . '/libs/Database.php',
        $root . '/lib/BlackCat/Core/Database.php',
        $root . '/BlackCat/Core/Database.php',
    ];
    foreach ($candidates as $c) {
        if (file_exists($c)) { require_once $c; $included = true; echo "<p style='color:orange'>[warn] Included Database directly: " . htmlspecialchars($c) . "</p>"; break; }
    }
}
if (!$included) { echo "<p class='fail'>Cannot find autoload or Database.php — edit script to require your autoloader.</p></body></html>"; exit(2); }

// Minimal PSR-3 fallback + test logger (captures messages)
// If the real PSR interfaces are present, we'll implement against them.
// If not, we create a compatible fallback interface with PSR-3 compatible signatures.
if (!interface_exists('Psr\Log\LoggerInterface')) {
    // define a minimal compatible PSR-3 LoggerInterface (PHP 8+ signatures)
    eval('
    namespace Psr\Log;
    interface LoggerInterface {
        public function emergency(string|\Stringable $message, array $context = []): void;
        public function alert(string|\Stringable $message, array $context = []): void;
        public function critical(string|\Stringable $message, array $context = []): void;
        public function error(string|\Stringable $message, array $context = []): void;
        public function warning(string|\Stringable $message, array $context = []): void;
        public function notice(string|\Stringable $message, array $context = []): void;
        public function info(string|\Stringable $message, array $context = []): void;
        public function debug(string|\Stringable $message, array $context = []): void;
        public function log($level, string|\Stringable $message, array $context = []): void;
    }
    ');
}
// Minimal PSR-3 fallback + concrete test logger (typed PSR-3 signatures)
// If the real PSR-3 interfaces are not available, define a compatible fallback
// with the exact signatures expected (PHP 8+). Otherwise we implement against the real one.
if (!interface_exists('Psr\\Log\\LoggerInterface')) {
    // define a minimal compatible PSR-3 LoggerInterface (PHP 8+ signatures)
    eval('
    namespace Psr\\Log;
    interface LoggerInterface {
        public function emergency(string|\\Stringable $message, array $context = []): void;
        public function alert(string|\\Stringable $message, array $context = []): void;
        public function critical(string|\\Stringable $message, array $context = []): void;
        public function error(string|\\Stringable $message, array $context = []): void;
        public function warning(string|\\Stringable $message, array $context = []): void;
        public function notice(string|\\Stringable $message, array $context = []): void;
        public function info(string|\\Stringable $message, array $context = []): void;
        public function debug(string|\\Stringable $message, array $context = []): void;
        public function log($level, string|\\Stringable $message, array $context = []): void;
    }
    ');
}

class SimpleTestLogger implements \Psr\Log\LoggerInterface
{
    public array $records = [];
    public function emergency(string|\Stringable $message, array $context = []): void { $this->records[] = ['emergency', (string)$message]; }
    public function alert(string|\Stringable $message, array $context = []): void { $this->records[] = ['alert', (string)$message]; }
    public function critical(string|\Stringable $message, array $context = []): void { $this->records[] = ['critical', (string)$message]; }
    public function error(string|\Stringable $message, array $context = []): void { $this->records[] = ['error', (string)$message]; }
    public function warning(string|\Stringable $message, array $context = []): void { $this->records[] = ['warning', (string)$message]; }
    public function notice(string|\Stringable $message, array $context = []): void { $this->records[] = ['notice', (string)$message]; }
    public function info(string|\Stringable $message, array $context = []): void { $this->records[] = ['info', (string)$message]; }
    public function debug(string|\Stringable $message, array $context = []): void { $this->records[] = ['debug', (string)$message]; }
    public function log($level, string|\Stringable $message, array $context = []): void { $this->records[] = [$level, (string)$message]; }
}

// Test harness
ob_start();
$testsRun = 0; $testsFailed = 0; $testsPassed = 0;
function t_assertTrue($cond, $msg='') { global $testsRun,$testsFailed,$testsPassed; $testsRun++; if ($cond) { $testsPassed++; echo '.'; } else { $testsFailed++; echo "F"; echo "\n[FAIL] " . ($msg?:'assertTrue failed') . "\n"; } }
function t_assertFalse($c,$m=''){ return t_assertTrue(!$c,$m); }
function t_assertSame($a,$b,$m=''){ global $testsRun,$testsFailed,$testsPassed; $testsRun++; if ($a === $b) { $testsPassed++; echo '.'; } else { $testsFailed++; echo "F"; echo "\n[FAIL] " . ($m?:"assertSame failed: expected (".var_export($b,true).") got (".var_export($a,true).")") . "\n"; } }
function t_assertNotNull($v,$m=''){ return t_assertTrue($v !== null,$m?:'assertNotNull failed'); }
function t_assertCountEq($exp,$arr,$m=''){ global $testsRun,$testsFailed,$testsPassed; $testsRun++; $c = is_array($arr)?count($arr):null; if ($c === $exp) { $testsPassed++; echo '.'; } else { $testsFailed++; echo "F"; echo "\n[FAIL] " . ($m?:"assertCountEq failed: expected {$exp}, got " . var_export($c,true)) . "\n"; } }

function resetDatabaseSingleton(): void {
    if (!class_exists('\\BlackCat\\Core\\Database')) return;
    $ref = new ReflectionClass('\\BlackCat\\Core\\Database');
    if ($ref->hasProperty('instance')) { $prop = $ref->getProperty('instance'); $prop->setAccessible(true); $prop->setValue(null, null); }
}

echo "<p>Running tests... (caller IP: " . htmlspecialchars($remote) . ")</p>\n";

try {
    // reset and init with sqlite memory
    resetDatabaseSingleton();
    $testLogger = new SimpleTestLogger();
    $config = ['dsn' => 'sqlite::memory:', 'user' => null, 'pass' => null, 'options' => []];
    // Try to call init with provided logger to exercise logging paths
    \BlackCat\Core\Database::init($config, $testLogger);
    $db = \BlackCat\Core\Database::getInstance();
    t_assertNotNull($db, 'getInstance returned null');
    $pdo = $db->getPdo();
    t_assertTrue($pdo instanceof PDO, 'getPdo not PDO');

    // create schema
    $db->getPdo()->exec('CREATE TABLE items(id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, val INTEGER, bin BLOB)');

    // insert + fetch
    $affected = $db->execute('INSERT INTO items (name, val) VALUES (:n, :v)', ['n' => 'alpha', 'v' => 10]);
    t_assertSame(1, $affected, 'insert affected');
    $id = (int)$db->lastInsertId(); t_assertTrue($id > 0, 'lastInsertId');
    $row = $db->fetch('SELECT * FROM items WHERE id = :id', ['id' => $id]); t_assertNotNull($row, 'fetch null'); t_assertSame('alpha', $row['name'] ?? null, 'name mismatch');

    // positional params
    $db->execute('INSERT INTO items (name, val) VALUES (?, ?)', ['beta', 20]);
    $v = $db->fetchValue('SELECT val FROM items WHERE name = ?', ['beta']); t_assertSame(20, (int)$v, 'positional value');

    // various fetch helpers
    $db->execute('INSERT INTO items (name, val) VALUES (?, ?)', ['p1', 1]); $db->execute('INSERT INTO items (name, val) VALUES (?, ?)', ['p2', 2]);
    $all = $db->fetchAll('SELECT name FROM items WHERE val <= ?', [2]); t_assertCountEq(2, $all, 'fetchAll count');
    $cols = $db->fetchColumn('SELECT val FROM items WHERE val <= ?', [2]); t_assertCountEq(2, $cols, 'fetchColumn count');
    $pairs = $db->fetchPairs('SELECT name, val FROM items WHERE val <= ?', [2]); t_assertTrue(isset($pairs['p1']) && isset($pairs['p2']), 'fetchPairs missing');
    t_assertTrue($db->exists('SELECT 1 FROM items WHERE name = ?', ['p1']), 'exists false');

    // executeRaw
    $rc = $db->executeRaw('UPDATE items SET val = val + 1 WHERE name = ?', ['p1']); t_assertTrue($rc >= 0, 'executeRaw rc');

    // prepareAndRun with various param types, including NULL, bool, binary with NUL
    $db->execute('INSERT INTO items (name, val, bin) VALUES (:n, :v, :b)', ['n'=>'blob','v'=>0,'b'=>"a\0b"]);
    $r = $db->fetch('SELECT bin FROM items WHERE name = ?', ['blob']); t_assertNotNull($r, 'blob fetch');

    // transactions commit & rollback
    $db->transaction(function($d){ $d->execute('INSERT INTO items (name, val) VALUES (:n, :v)', ['n'=>'t_ok','v'=>11]); });
    t_assertTrue($db->exists('SELECT 1 FROM items WHERE name = ?', ['t_ok']), 'transaction commit failed');
    try { $db->transaction(function($d){ $d->execute('INSERT INTO items (name, val) VALUES (?, ?)', ['t_bad', 99]); throw new RuntimeException('force rollback'); }); } catch (Throwable $_) {}
    t_assertFalse($db->exists('SELECT 1 FROM items WHERE name = ?', ['t_bad']), 'transaction rollback failed');

    // nested savepoint behavior
    $db->transaction(function($d){
        $d->execute('INSERT INTO items (name, val) VALUES (?, ?)', ['outer', 1]);
        try {
            $d->transaction(function($d){ $d->execute('INSERT INTO items (name, val) VALUES (?, ?)', ['inner', 2]); throw new RuntimeException('inner fail'); });
        } catch (Throwable $_) {}
        t_assertFalse($d->exists('SELECT 1 FROM items WHERE name = ?', ['inner']), 'inner rollback failed');
    });
    t_assertTrue($db->exists('SELECT 1 FROM items WHERE name = ?', ['outer']), 'outer persist failed');

    // cachedFetchAll TTL
    $db->execute('INSERT INTO items (name, val) VALUES (?, ?)', ['cache_me', 5]);
    $a = $db->cachedFetchAll('SELECT * FROM items WHERE name = ?', ['cache_me'], 1);
    usleep(200000);
    $b = $db->cachedFetchAll('SELECT * FROM items WHERE name = ?', ['cache_me'], 1);
    t_assertTrue($a === $b, 'cache mismatch');
    sleep(1);
    $c = $db->cachedFetchAll('SELECT * FROM items WHERE name = ?', ['cache_me'], 1);
    t_assertTrue(is_array($c), 'cachedFetchAll post-expiry');

    // paginate
    for ($i=1;$i<=25;$i++) { $db->execute('INSERT INTO items (name, val) VALUES (?, ?)', ['pg'.$i, $i]); }
    $res = $db->paginate('SELECT * FROM items ORDER BY id', [], 2, 10);
    t_assertTrue(is_array($res), 'paginate not array'); t_assertCountEq(10, $res['items'], 'paginate items count'); t_assertTrue((int)$res['total'] >= 25, 'paginate total');

    // ping
    t_assertTrue($db->ping(), 'ping false');

    // test setLogger and debug logging path
    $db->setLogger($testLogger);
    $db->enableDebug(true);
    $db->execute('SELECT 1');

} catch (Throwable $e) {
    echo "\n\n[EXCEPTION] " . htmlspecialchars((string)$e->getMessage()) . "\n\n";
}

$out = ob_get_clean();
echo "<h3>Raw run output</h3><pre>" . htmlspecialchars($out) . "</pre>";

// print captured logs
echo "<h3>Captured logger records (test logger)</h3>";
if (isset($testLogger) && is_array($testLogger->records)) {
    echo "<pre>" . htmlspecialchars(print_r($testLogger->records, true)) . "</pre>";
} else {
    echo "<p>No test logger records captured.</p>";
}

// summary
echo "<h3>Summary</h3>";
echo "<p>Tests run: <strong>{$testsRun}</strong><br>Passed: <strong class='ok'>{$testsPassed}</strong> <br>Failed: <strong class='fail'>{$testsFailed}</strong></p>";

echo "<hr><p><strong>Security note:</strong> delete this file and token after use.</p>";
echo "</body></html>";
exit(0);
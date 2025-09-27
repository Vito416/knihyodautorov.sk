<?php
// run_tests_web.php
declare(strict_types=1);

/**
 * Web Test Runner (upraveno)
 *
 * Poznámky:
 *  - Tento runner je určen pro rychlé spuštění integračních testů přes web (jen pro vývoj).
 *  - BEZPEČNOST: výchozí nastavení povoluje přístup pouze z lokálního hosta. Pokud potřebuješ
 *    spouštět z jiného IP, uprav $ALLOWED_IPS nebo odstraň kontrolu.
 */

// ===== CONFIG =====
// cesta k bootstrapu (upravit dle projektu)
$bootstrapPath = __DIR__ . '/../eshop/inc/bootstrap.php';

// cesta k testům (upravit dle struktury projektu)
$testsDir = realpath(dirname(__DIR__, 4)) . '/libs/gopay/tests/integration';

// cesta k lokalnímu phpunit shim (pokud používáš)
$shim = __DIR__ . '/phpunit_shim.php';

$expectedToken = $_ENV['GOPAYTEST_TOKEN'] ?? '';

// -------------------------------------------------
// VALIDATE TOKEN
// -------------------------------------------------
$token = $_GET['token'] ?? null;
if ($token === null || !hash_equals($expectedToken, $token)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid token'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// ===== bootstrap (autoloader) =====
if (file_exists($bootstrapPath)) {
    require_once $bootstrapPath;
} else {
    echo "<p><strong>Bootstrap nenalezen:</strong> " . htmlspecialchars($bootstrapPath) . "</p>";
    echo "<p>Uprav \$bootstrapPath v " . htmlspecialchars(__FILE__) . "</p>";
    exit;
}

// include PHPUnit shim if provided (shim should define minimal TestCase & assertions)
if (file_exists($shim)) {
    require_once $shim;
}

// ===== collect test files (RECURSIVE) =====
$testFiles = [];
if (!is_dir($testsDir)) {
    echo "<p><strong>Tests dir nenalezen:</strong> " . htmlspecialchars($testsDir) . "</p>";
    exit;
}

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($testsDir));
foreach ($it as $file) {
    if (!$file->isFile()) continue;
    if ($file->getExtension() !== 'php') continue;
    $bn = $file->getBasename();
    // skip runner and shim if they happen to be in same folder
    if (in_array($bn, [basename(__FILE__), basename($shim)], true)) continue;
    $testFiles[] = $file->getPathname();
}

// ===== UI header =====
ob_start();
echo "<!doctype html><html><head><meta charset='utf-8'><title>Test Runner (web)</title>";
echo "<style>
body{font-family:Menlo,Monaco,monospace;background:#0b0b0f;color:#e6e6e9;padding:1rem}
pre{white-space:pre-wrap}
.pass{color:#6ee26e}
.fail{color:#ff6666}
.block{background:#071018;padding:0.75rem;border-radius:6px;margin-bottom:1rem;box-shadow:0 6px 20px rgba(0,0,0,0.6)}
.h{color:#ffd56b}
.small{font-size:0.9rem;color:#a8b0b8}
</style></head><body>";
echo "<h1>Web Test Runner</h1>";
echo "<p class='small'>Bootstrap: " . htmlspecialchars($bootstrapPath) . "</p>";
echo "<p class='small'>Tests dir: " . htmlspecialchars($testsDir) . "</p>";

// Track declared classes before includes so we can detect new classes
$declBefore = get_declared_classes();

// include all test files
foreach ($testFiles as $file) {
    echo "<div class='block'><div class='h'>Including:</div><pre>" . htmlspecialchars($file) . "</pre></div>";
    try {
        require_once $file;
    } catch (\Throwable $e) {
        echo "<div class='block fail'><strong>Include error in " . htmlspecialchars($file) . ":</strong>\n<pre>" . htmlspecialchars((string)$e) . "</pre></div>";
    }
}

// find new classes (those declared after includes)
$declAfter = get_declared_classes();
$new = array_diff($declAfter, $declBefore);

// Filter classes that are subclasses of PHPUnit\Framework\TestCase OR match package namespace
$testClasses = [];
foreach ($new as $cls) {
    if (!class_exists($cls)) continue;
    // If PHPUnit TestCase exists, use is_subclass_of detection
    if (class_exists(\PHPUnit\Framework\TestCase::class) && is_subclass_of($cls, \PHPUnit\Framework\TestCase::class)) {
        $testClasses[] = $cls;
        continue;
    }
    // Fallback heuristics (namespace used by GoPay tests)
    if (strpos($cls, 'GoPay\\') === 0 || stripos($cls, 'Test') !== false) {
        $testClasses[] = $cls;
    }
}

if (empty($testClasses)) {
    echo "<div class='block'><strong>No test classes found.</strong></div>";
} else {
    echo "<div class='block'><strong>Found test classes:</strong><pre>" . htmlspecialchars(implode("\n", $testClasses)) . "</pre></div>";
}

// ===== helper: call lifecycle methods even when protected/private =====
function callLifecycleMethod(object $obj, string $name): ?\Throwable {
    try {
        $r = new ReflectionClass($obj);
        if ($r->hasMethod($name)) {
            $m = $r->getMethod($name);
            if (!$m->isPublic()) {
                $m->setAccessible(true);
            }
            $m->invoke($obj);
        }
        return null;
    } catch (\Throwable $e) {
        return $e;
    }
}

// ===== runner =====
$total = 0;
$failed = 0;
$passed = 0;

foreach ($testClasses as $class) {
    echo "<div class='block'><div class='h'>Running tests in <strong>" . htmlspecialchars($class) . "</strong></div>";
    try {
        // instantiate
        $obj = null;
        try {
            $obj = new $class();
        } catch (\Throwable $e) {
            echo "<div class='fail'><strong>Cannot instantiate $class:</strong>\n<pre>" . htmlspecialchars((string)$e) . "</pre></div>";
            continue;
        }

        // call setUp (works even if protected/private)
        $err = callLifecycleMethod($obj, 'setUp');
        if ($err !== null) {
            echo "<div class='fail'><strong>setUp() failed for $class:</strong>\n<pre>" . htmlspecialchars((string)$err) . "</pre></div>";
            // If setUp failed fatally, tests may fail — but continue to attempt individual tests (some tests may still work)
        }

        // reflect public methods starting with "test"
        $r = new \ReflectionClass($obj);
        $methods = $r->getMethods(\ReflectionMethod::IS_PUBLIC);
        foreach ($methods as $m) {
            if (strpos($m->name, 'test') !== 0) continue;
            $total++;
            $methodName = $m->name;
            echo "<div style='margin-top:.5rem'><strong>Running:</strong> " . htmlspecialchars($methodName) . "</div>";
            ob_start();
            try {
                $start = microtime(true);
                // invoke public test method
                $m->invoke($obj);
                $duration = round((microtime(true) - $start) * 1000, 1);
                $out = ob_get_clean();
                echo "<div class='pass'>OK (" . $duration . "ms)</div>";
                if ($out !== '') echo "<pre>" . htmlspecialchars($out) . "</pre>";
                $passed++;
            } catch (\Throwable $e) {
                $out = ob_get_clean();
                $failed++;
                echo "<div class='fail'><strong>FAIL:</strong> " . htmlspecialchars(get_class($e)) . " - " . htmlspecialchars($e->getMessage()) . "</div>";
                if ($out !== '') echo "<pre>" . htmlspecialchars($out) . "</pre>";
                echo "<pre>" . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n\n" . htmlspecialchars($e->getTraceAsString(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</pre>";
            }
        }

        // call tearDown (works even if protected/private)
        $err = callLifecycleMethod($obj, 'tearDown');
        if ($err !== null) {
            echo "<div class='fail'><strong>tearDown() failed for $class:</strong>\n<pre>" . htmlspecialchars((string)$err) . "</pre></div>";
        }

    } catch (\Throwable $e) {
        echo "<div class='fail'><strong>Unexpected error for $class:</strong>\n<pre>" . htmlspecialchars((string)$e) . "</pre></div>";
    }
    echo "</div>"; // class block
}

echo "<div class='block'><h2>Summary</h2>";
echo "<pre>Total: $total\nPassed: $passed\nFailed: $failed</pre>";
echo "</div>";

echo "</body></html>";

$html = ob_get_clean();
echo $html;
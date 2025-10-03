<?php
declare(strict_types=1);

$ALLOW_WEB_RUN = true; // jen dočasně pro testování

$bootstrapPath = __DIR__ . '/../eshop/inc/bootstrap.php';
if (!file_exists($bootstrapPath)) {
    http_response_code(500);
    echo "<h2>Bootstrap not found</h2><pre>{$bootstrapPath}</pre>";
    exit;
}
require_once $bootstrapPath;

// --- Helpery ---
function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function step(string $label, bool $ok, string $extra = ''): void {
    echo "<div class='step'>{$label}: ".($ok ? "<span class='ok'>OK</span>" : "<span class='fail'>FAIL</span>")." {$extra}</div>";
}

echo "<!DOCTYPE html><html><head><meta charset='utf-8'><title>FileCache Test</title>";
echo "<style>
body{font-family:monospace;background:#111;color:#eee;padding:20px;}
h1{color:#0f0;}
.ok{color:#0f0;}
.fail{color:#f33;}
.step{margin:10px 0;}
pre{background:#222;padding:10px;border-radius:5px;}
</style></head><body>";
echo "<h1>FileCache.php – Extended Web Test</h1>";

try {
    $cacheDir = __DIR__ . '/../cache';
    $keyName = 'cache_crypto';
    $rotateInfo = KeyManager::rotateKey($keyName, KEYS_DIR);
    echo "Rotated: " . $rotateInfo['version'];

    $cache = new FileCache($cacheDir, true, KEYS_DIR);


    // Step 2: Set + Get
    $testKey = 'demo_key';
    $testValue = ['msg' => 'Hello world', 'time' => time()];
    $setOk = $cache->set($testKey, $testValue, 30);
    step("Set value", $setOk);
    $got = $cache->get($testKey);
    step("Get value", $got === $testValue);
    echo "<pre>".h(var_export($got, true))."</pre>";

    // Step 3: Expiry
    $cache->set('short_lived', 'temp', 1);
    $got1 = $cache->get('short_lived', 'missing');
    sleep(2);
    $got2 = $cache->get('short_lived', 'missing');
    step("Expiry test", $got1 === 'temp' && $got2 === 'missing');

    // Step 4: Rotate again + backward compatibility
    $rotateInfo2 = KeyManager::rotateKey($keyName, KEYS_DIR);
    echo "<div class='step'>Rotated new key: version <b>".h($rotateInfo2['version'])."</b></div>";
    $stillGot = $cache->get($testKey, 'missing');
    step("Backward compatibility", $stillGot === $testValue);

    // Step 5: Delete
    $cache->set('todelete', 'bye');
    $deleted = $cache->delete('todelete');
    $gone = $cache->get('todelete', 'missing') === 'missing';
    step("Delete test", $deleted && $gone);

    // Step 6: Clear
    $cache->set('clear1', 'a');
    $cache->set('clear2', 'b');
    $cleared = $cache->clear();
    $goneAll = !$cache->has('clear1') && !$cache->has('clear2');
    step("Clear test", $cleared && $goneAll);

    // Step 7: Dump cache dir
    echo "<h2>Cache directory dump</h2><pre>";
    foreach (glob($cacheDir.'/*.cache') as $f) {
        echo basename($f)." (".filesize($f)." bytes)\n";
    }
    echo "</pre>";

    // Step 8: Benchmark
    $N = 1000;
    $start = microtime(true);
    for ($i=0;$i<$N;$i++) {
        $cache->set("bench_$i", $i);
    }
    $mid = microtime(true);
    $ok = true;
    for ($i=0;$i<$N;$i++) {
        if ($cache->get("bench_$i") !== $i) { $ok = false; break; }
    }
    $end = microtime(true);

    $setTime = round(($mid - $start)*1000, 2);
    $getTime = round(($end - $mid)*1000, 2);
    step("Benchmark ($N items)", $ok, "(set: {$setTime} ms, get: {$getTime} ms)");

} catch (Throwable $e) {
    echo "<div class='fail'>EXCEPTION: ".h($e->getMessage())."</div>";
    echo "<pre>".h($e->getTraceAsString())."</pre>";
}

echo "</body></html>";
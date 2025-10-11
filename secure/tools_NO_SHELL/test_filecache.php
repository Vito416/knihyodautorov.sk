<?php

declare(strict_types=1);

use BlackCat\Core\Cache\FileCache;

ob_start();
/**
 * FileCache Full Visual + Historical Test
 */

$CACHE_DIR = __DIR__ . '/../cache_test';
$HISTORY_FILE = $CACHE_DIR.'/cache_test_history.json';
if(!is_dir($CACHE_DIR)) @mkdir($CACHE_DIR,0700,true);

$WORKERS = 4;
$ITERATIONS = 200;
$TTL_TEST = 2;
$MAX_FILES = 5;
$MAX_SIZE = 1024*10;

$results = [];
$parallelResult = [];

function h(string $s){ return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function measure(callable $fn,string $label){ 
    global $results;
    $start = microtime(true);
    $fn();
    $end = microtime(true);
    $delta = $end-$start;
    $results[]=['label'=>$label,'time'=>$delta];
    echo "<div><b>".h($label)."</b>: ".sprintf("%.4f s",$delta)."</div>";
}

// --- Bootstrap ---
$bootstrapPath = __DIR__ . '/eshop/inc/bootstrap.php';
if(!file_exists($bootstrapPath)){ echo "Bootstrap missing"; exit(1);}
require_once $bootstrapPath;

// --- Cache Instances ---
$cachePlain = new FileCache($CACHE_DIR,false,null,'CACHE_CRYPTO_KEY','cache_crypto',2,$MAX_SIZE,$MAX_FILES);
$cacheEnc   = new FileCache($CACHE_DIR,true,KEYS_DIR,'CACHE_CRYPTO_KEY','cache_crypto',2,$MAX_SIZE,$MAX_FILES);

// --- Worker mode for parallel stress ---
$mode = $_GET['mode'] ?? null;
if($mode==='worker'){
    $wid = (int)($_GET['wid']??0);
    $iters = (int)($_GET['iters']??100);
    @set_time_limit(60 + (int)($iters/10));
    try{
        $cache = new FileCache(realpath($CACHE_DIR)?:$CACHE_DIR,true,KEYS_DIR);
        for($i=0;$i<$iters;$i++){
            $k="par_{$wid}_$i";
            $v=$i;
            if(!$cache->set($k,$v,60)){ echo "ERR_SET $k"; exit(2); }
            if($cache->get($k,'__MISSING__')!==$v){ echo "ERR_GET $k"; exit(3);}
            if($i%50===0) usleep(1000);
        }
        echo "OK";
    }catch(Throwable $e){ echo "EX:".$e->getMessage(); exit(1);}
    exit(0);
}

// --- HTML Header ---
echo "<!doctype html><html><head><meta charset='utf-8'><title>FileCache Full Visual + History</title>
<style>
body{font-family:monospace;background:#111;color:#eee;padding:20px;}
.bar{display:inline-block;height:20px;background:#0f0;margin:2px 0;}
.bar-fail{background:#f55;}
table{border-collapse:collapse;width:80%;margin-top:10px;}
th,td{border:1px solid #555;padding:4px;text-align:left;}
canvas{background:#222;border:1px solid #555;margin-top:10px;}
</style>
</head><body>";
echo "<h1>FileCache Full Visual + Historical Test</h1>";

// --- Run tests ---
measure(function() use($cachePlain){ $cachePlain->set('foo','bar',60); if($cachePlain->get('foo')!=='bar') throw new Exception('Plain set/get failed'); },'Plain set/get');
measure(function() use($cacheEnc){ $cacheEnc->set('foo_enc','bar_enc',60); if($cacheEnc->get('foo_enc')!=='bar_enc') throw new Exception('Encrypted set/get failed'); },'Encrypted set/get');
measure(function() use($cachePlain,$TTL_TEST){ $cachePlain->set('ttl_test','val',$TTL_TEST); sleep($TTL_TEST+1); if($cachePlain->get('ttl_test')!==null) throw new Exception('TTL expiration failed'); },'TTL expiration');
measure(function() use($cachePlain){ $multi=['a'=>1,'b'=>2,'c'=>3]; $cachePlain->setMultiple($multi,60); $res=$cachePlain->getMultiple(array_keys($multi)); if($res!=$multi) throw new Exception('getMultiple failed'); $cachePlain->deleteMultiple(['a','b']); $res2=$cachePlain->getMultiple(array_keys($multi),'__MISSING__'); if($res2!=['a'=>'__MISSING__','b'=>'__MISSING__','c'=>3]) throw new Exception('deleteMultiple effect failed'); },'Multiple operations');
measure(function() use($cachePlain){ $cachePlain->set('del_test','x',60); $cachePlain->delete('del_test'); $cachePlain->set('clear1','x',60); $cachePlain->set('clear2','y',60); $cachePlain->clear(); },'Delete & Clear');
measure(function() use($cachePlain,$MAX_FILES){ for($i=0;$i<10;$i++) $cachePlain->set("q$i",str_repeat('x',1024),60); $metrics=$cachePlain->getMetrics(); if($metrics['evictions']<1) throw new Exception('Quota eviction not triggered'); },'Quota enforcement');

// --- Parallel stress test ---
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off')?'https':'http';
$host = $_SERVER['HTTP_HOST']??($_SERVER['SERVER_NAME']??'127.0.0.1');
$script = $_SERVER['SCRIPT_NAME']??basename(__FILE__);
$baseUrl = "$scheme://$host$script";

if(function_exists('curl_multi_init')){
    $multi=curl_multi_init();
    $handles=[];
    for($w=0;$w<$WORKERS;$w++){
        $url=$baseUrl.'?mode=worker&wid='.$w.'&iters='.$ITERATIONS;
        $ch=curl_init();
        curl_setopt($ch,CURLOPT_URL,$url);
        curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
        curl_setopt($ch,CURLOPT_TIMEOUT,60);
        curl_multi_add_handle($multi,$ch);
        $handles[$w]=$ch;
    }
    $running=null;
    do{ curl_multi_exec($multi,$running); curl_multi_select($multi,0.1);} while($running>0);
    foreach($handles as $w=>$ch){
        $out=curl_multi_getcontent($ch);
        curl_multi_remove_handle($multi,$ch);
        curl_close($ch);
        $parallelResult[$w]=trim($out)==='OK';
    }
    curl_multi_close($multi);
}

// --- Save history ---
$metrics=$cachePlain->getMetrics();
$historyEntry=[
    'time'=>time(),
    'results'=>$results,
    'parallel'=>$parallelResult,
    'metrics'=>$metrics
];
$history=[];
if(file_exists($HISTORY_FILE)) $history=json_decode(file_get_contents($HISTORY_FILE),true)??[];
$history[]=$historyEntry;
file_put_contents($HISTORY_FILE,json_encode($history,JSON_PRETTY_PRINT));

// --- Display ---
echo "<h2>Parallel Workers</h2><table><tr><th>Worker</th><th>Status</th></tr>";
foreach($parallelResult as $w=>$ok){
    echo "<tr><td>$w</td><td>".($ok?'<span style="color:#0f0">OK</span>':'<span style="color:#f55">FAIL</span>')."</td></tr>";
}
echo "</table>";

echo "<h2>Execution Times (last run)</h2>";
$maxTime=max(array_column($results,'time'))?:1;
foreach($results as $r){
    $width=intval(($r['time']/$maxTime)*500);
    echo "<div>{$r['label']} <div class='bar' style='width:{$width}px'></div> ".sprintf("%.3f s",$r['time'])."</div>";
}

// --- Historical Graph using Canvas ---
echo "<h2>Historical Test Times</h2><canvas id='history' width=800 height=200></canvas>";
echo "<script>
const history=".json_encode($history).";
const ctx=document.getElementById('history').getContext('2d');
ctx.clearRect(0,0,800,200);
const labels=history.map((h,i)=>new Date(h.time*1000).toLocaleTimeString());
const colors=['#0f0','#ff0','#0ff','#f0f','#f80','#08f','#f08','#88f'];
for(let j=0;j<history[0].results.length;j++){
    ctx.beginPath();
    ctx.strokeStyle=colors[j%colors.length];
    ctx.moveTo(0,200-history[0].results[j].time*50);
    for(let i=0;i<history.length;i++){
        let t=history[i].results[j].time*50;
        ctx.lineTo(i*100,(200-t));
    }
    ctx.stroke();
}
</script>";

// --- Current Cache Metrics + Auto-clean expired ---
echo "<h2>Cache Metrics</h2>";
$metrics = $cachePlain->getMetrics();

$filesCount    = $metrics['totalFiles'] ?? 0;
$activeCount   = $metrics['activeFiles'] ?? 0;
$expiredCount  = $metrics['expiredFiles'] ?? 0;
$sizeTotal     = $metrics['totalSize'] ?? 0;
$activeSize    = $metrics['activeSize'] ?? 0;
$expiredSize   = $metrics['expiredSize'] ?? 0;
$evictions     = $metrics['evictions'] ?? 0;

echo "Files: $filesCount (active: $activeCount, expired: $expiredCount), ".
     "Size: {$sizeTotal} bytes (active: $activeSize, expired: $expiredSize), ".
     "Evictions: $evictions<br>";

$files = glob($CACHE_DIR . '/*');
echo "<h2>Cache Files</h2><ul>";
foreach ($files as $f) {
    $size = filesize($f);
    $mtime = filemtime($f);

    $raw = @file_get_contents($f);
    $expired = false;
    if ($raw !== false) {
        $data = @unserialize($raw, ['allowed_classes' => false]);
        if (is_array($data) && isset($data['expires']) && $data['expires'] !== null) {
            if ($data['expires'] < time()) {
                $expired = true;
                // Auto-remove expired files
                @unlink($f);
            }
        }
    }

    echo "<li>" . basename($f) . " | size={$size} | mtime=" . date('H:i:s', $mtime) . " | " .
         ($expired ? '<span style="color:#f55">EXPIRED & REMOVED</span>' : '<span style="color:#0f0">ACTIVE</span>') .
         "</li>";
}
echo "</ul>";

echo "<p>All FileCache tests completed.</p></body></html>";
<?php
declare(strict_types=1);
ob_start();

header('Content-Type: text/html; charset=utf-8');
echo "<!doctype html><html><head><meta charset='utf-8'><title>Logger full test</title></head><body><pre>\n";

function out(string $s = "") {
    echo htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n";
    @ob_flush(); @flush();
}

function cp(string $label) { out("=== CHECKPOINT: $label ==="); }

cp('START full Logger harness');

/* Temporary error log */
$tmpDir = sys_get_temp_dir() ?: '/tmp';
$tmpFile = @tempnam($tmpDir, 'logger_test_errlog_');
if ($tmpFile === false) {
    out("[-] Could not create temp file for error log in $tmpDir");
    $tmpFile = null;
} else {
    ini_set('log_errors','1');
    ini_set('error_log', $tmpFile);
    error_reporting(E_ALL);
    ini_set('display_errors','0');

    register_shutdown_function(function() use ($tmpFile) {
        if ($tmpFile && file_exists($tmpFile)) {
            out("\n=== Captured error_log (tail) ===");
            $lines = 200;
            $fp = fopen($tmpFile,'rb');
            if ($fp) {
                fseek($fp, -4096*$lines, SEEK_END);
                $tail = stream_get_contents($fp);
                echo htmlspecialchars($tail ?: "(empty)", ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8') . "\n";
                fclose($fp);
            }
        }
    });

    set_error_handler(function($errno,$errstr,$errfile,$errline){
        $msg = sprintf("PHP ERROR [%d] %s in %s:%d",$errno,$errstr,$errfile,$errline);
        error_log($msg);
        return false;
    });

    set_exception_handler(function($ex){
        error_log("Uncaught Exception: ".$ex->getMessage());
        http_response_code(500);
        echo "\nUncaught exception: ".htmlspecialchars($ex->getMessage(), ENT_QUOTES|ENT_SUBSTITUTE)."\n";
        exit(1);
    });
}

/* Require bootstrap */
try {
    $db = require __DIR__.'/inc/bootstrap.php';
    out("[OK] bootstrap loaded, \$db type: ".(is_object($db)?get_class($db):gettype($db)));
} catch (\Throwable $e) {
    out("[-] bootstrap failed: ".$e->getMessage());
    exit(1);
}

/* Test Logger functions */
cp('TEST Logger functions');

if (class_exists('Logger')) {
    try {
        Logger::auth('login_success', 1, ['note'=>'test auth']);
        Logger::register('register_success', 2, ['note'=>'test register']);
        Logger::verify('verify_success', 3, ['note'=>'test verify']);
        Logger::session('session_created', 1, ['note'=>'test session']);
        Logger::systemMessage('notice','Test systemMessage', 1, ['ctx'=>'web']);
        Logger::systemError(new Exception('Test systemError'), 1);
        Logger::error('Test error shortcut',1);
        Logger::warn('Test warn shortcut',1);
        Logger::info('Test info shortcut',1);
        Logger::critical('Test critical shortcut',1);
        if (class_exists('DeferredHelper')) {
            DeferredHelper::flush();
            out("[Logger] Deferred queue flushed");
        }

        out("[Logger] All logging functions invoked successfully");
    } catch (\Throwable $e) {
        out("[Logger] Logging functions FAILED: ".$e->getMessage());
    }
} else {
    out("[Logger] Logger class not available");
}

cp('FINAL DIAGNOSTICS');
out("Temp error log path: ".($tmpFile??'(none)'));
if ($tmpFile && file_exists($tmpFile)) out("Temp error log size: ".filesize($tmpFile)." bytes");

out("\n=== END Logger full test ===");
echo "</pre></body></html>";
<?php
// scripts/maintenance/cleanup_temp_files.php
// Removes temporary files older than X seconds from secure/../tmp and storage temp areas.
// Run by cron once daily.
$base = __DIR__ . '/../../secure/../tmp';
$maxAge = 60*60*24*3; // 3 days
if (!is_dir($base)) exit;
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
$now = time();
foreach($files as $f){
    $file = (string)$f;
    if (is_file($file) && ($now - filemtime($file)) > $maxAge) {
        @unlink($file);
    }
}
echo "Cleanup finished\n";
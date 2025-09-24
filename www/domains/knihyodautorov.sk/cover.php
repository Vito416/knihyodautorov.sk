<?php
declare(strict_types=1);
/**
 * cover.php - secure proxy for cover files (uniform error responses)
 *
 * Security notes:
 *  - For any rejected/invalid request we return the SAME neutral 404 response
 *    (no details, constant small body) to avoid probing/footprinting.
 *  - We add a small fixed delay on error branches to reduce timing differences.
 *  - Important: existing files still return 200/304 — that's required for usability.
 */

error_reporting(0);

// ---------------- config ----------------
$projectRoot = realpath(dirname(__DIR__, 3));
if ($projectRoot === false) {
    internal_log('fatal_project_root_missing'); // <<< DOPLNIT: log bez path
    uniformError(); // <<< ZMĚNA: nepodávat 500, jen uniform 404
}

$allowedBases = [
    $projectRoot . '/storage/books/covers',
    $projectRoot . '/storage/uploads/covers',
];

$useXAccel = (bool) ($_ENV['USE_X_ACCEL'] ?? false);
$xAccelMap = []; // optional mapping if using X-Accel-Redirect
$maxFileBytes = (int) ($_ENV['COVER_MAX_BYTES'] ?? 20 * 1024 * 1024);
$requireAuth = (string) ($_ENV['COVERS_REQUIRE_AUTH'] ?? '') === '1';

// canonicalize allowed bases
$allowedReal = [];
foreach ($allowedBases as $b) {
    $r = realpath($b);
    if ($r !== false) $allowedReal[rtrim($r, DIRECTORY_SEPARATOR)] = rtrim($r, DIRECTORY_SEPARATOR);
}
$canonXAccelMap = [];
foreach ($xAccelMap as $k => $v) {
    $rp = realpath($k);
    if ($rp !== false) $canonXAccelMap[rtrim($rp, DIRECTORY_SEPARATOR)] = '/' . ltrim($v, '/');
}

// simple internal logger (append-only) - does not leak to client
function internal_log(string $msg): void {
    // adjust logfile path/perms as appropriate; keep simple and non-verbose
    $logfile = sys_get_temp_dir() . '/cover_proxy.log';
    @file_put_contents($logfile, '[' . date('c') . '] ' . $msg . PHP_EOL, FILE_APPEND | LOCK_EX);
}

// uniform error response to avoid probing
function uniformError(): void {
    // small fixed delay to reduce timing differences (40 ms)
    // WARNING: keep this small to avoid easing DoS
    usleep(40000);

    // neutral headers (no details)
    header_remove(); // remove any previously set headers
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    // constant short body
    echo 'Not found';
    exit;
}

// ---------------- read/validate param ----------------
$pathParam = $_GET['path'] ?? '';
$pathParam = (string)$pathParam;
if ($pathParam === '' || strpos($pathParam, "\0") !== false) {
    internal_log('bad_request empty-or-nul from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    uniformError();
}

// decode percent-encoding
$pathParam = rawurldecode($pathParam);

// forbid absolute filesystem paths from callers
$looksAbsolute = false;
if (PHP_OS_FAMILY === 'Windows') {
    if (preg_match('#^[A-Za-z]:\\\\#', $pathParam) || strncmp($pathParam, '\\\\', 2) === 0) $looksAbsolute = true;
} else {
    if (strlen($pathParam) > 0 && $pathParam[0] === '/') $looksAbsolute = true;
}
if ($looksAbsolute) {
    internal_log('absolute_path_attempt from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . ' param=' . substr($pathParam,0,200));
    uniformError();
}
if (strpos($pathParam, '..') !== false) {  // <<< DOPLNIT: zabránění directory traversal
    internal_log('path_traversal_attempt from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    uniformError();
}
// normalize candidate under project root
$candidate = $projectRoot . '/' . ltrim($pathParam, '/');
$realPath = @realpath($candidate);
if ($realPath === false || !is_file($realPath) || !is_readable($realPath)) {
    internal_log('not_found candidate=' . basename($candidate) . ' client=' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    uniformError();
}
$realPath = rtrim($realPath, DIRECTORY_SEPARATOR);

// ensure inside allowed dirs
$allowed = false;
$matchedBase = null;
foreach ($allowedReal as $base) {
    $baseWithSep = $base . DIRECTORY_SEPARATOR;
    if (strncmp($realPath . DIRECTORY_SEPARATOR, $baseWithSep, strlen($baseWithSep)) === 0
        || $realPath === $base) {
        $allowed = true;
        $matchedBase = $base;
        break;
    }
}
if (!$allowed) {
    internal_log('access_denied outside_allowed base check real=' . $realPath . ' client=' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    uniformError();
}

// optional auth check
if ($requireAuth) {
    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    $userId = $_SESSION['user_id'] ?? null;
    if (empty($userId)) {
        internal_log('auth_required client=' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . ' path=' . $pathParam);
        uniformError();
    }
}

// prepare metadata
$filesize = @filesize($realPath);
$mtime = @filemtime($realPath);
if ($filesize === false) $filesize = 0;
if ($mtime === false) $mtime = time();

// enforce size
if ($maxFileBytes > 0 && $filesize > $maxFileBytes) {
    internal_log('oversize file=' . $realPath . ' size=' . $filesize);
    uniformError();
}

// MIME check (image only)
$finfo = @finfo_open(FILEINFO_MIME_TYPE);
$mime = $finfo ? @finfo_file($finfo, $realPath) : null;
if ($finfo) @finfo_close($finfo);
if ($mime === false || $mime === null) $mime = 'application/octet-stream';
$allowedExts = ['png','jpg','jpeg','gif','webp','svg']; // <<< DOPLNIT whitelist přípon
$ext = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
if (!in_array($ext, $allowedExts, true)) {
    internal_log('forbidden_ext file=' . $realPath . ' ext=' . $ext);
    uniformError();
}

if (strpos($mime, 'image/') !== 0 && $mime !== 'image/svg+xml') {
    internal_log('forbidden_type file=' . $realPath . ' mime=' . $mime);
    uniformError();
}

// ETag & conditional GET for valid files (allowed to leak 200/304)
$etag = '"' . md5($filesize . '|' . $mtime) . '"';
$lastModifiedGmt = gmdate('D, d M Y H:i:s', $mtime) . ' GMT';
$ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? null;
if ($ifNoneMatch !== null) {
    $clientEtags = array_map('trim', explode(',', $ifNoneMatch));
    if (in_array($etag, $clientEtags, true)) {
        header('HTTP/1.1 304 Not Modified');
        header('ETag: ' . $etag);
        header('Cache-Control: public, max-age=86400');
        exit;
    }
}
$ifModifiedSince = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? null;
if ($ifModifiedSince !== null) {
    $since = strtotime($ifModifiedSince);
    if ($since !== false && $since >= $mtime) {
        header('HTTP/1.1 304 Not Modified');
        header('ETag: ' . $etag);
        header('Cache-Control: public, max-age=86400');
        exit;
    }
}

// If using X-Accel-Redirect / X-Sendfile mapping, prefer that (faster & hides PHP)
if ($useXAccel && $matchedBase !== null && isset($canonXAccelMap[$matchedBase])) {
    $internalPrefix = $canonXAccelMap[$matchedBase];
    $relative = ltrim(substr($realPath, strlen($matchedBase)), DIRECTORY_SEPARATOR);
    $internalLocation = rtrim($internalPrefix, '/') . '/' . ltrim($relative, '/');

    header('X-Accel-Redirect: ' . $internalLocation);
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . $filesize);
    header('Last-Modified: ' . $lastModifiedGmt);
    header('ETag: ' . $etag);
    header('Cache-Control: public, max-age=86400, immutable');
    header('X-Content-Type-Options: nosniff');
    exit;
}

// Serve file via PHP stream (valid file)
header('Content-Type: ' . $mime);
header('Content-Length: ' . $filesize);
header('Last-Modified: ' . $lastModifiedGmt);
header('ETag: ' . $etag);
header('Cache-Control: public, max-age=86400, immutable');
header('Pragma: public');
header('X-Content-Type-Options: nosniff');

$fp = @fopen($realPath, 'rb');
if ($fp === false) {
    internal_log('open_fail file=' . $realPath);
    uniformError(); // fallback uniform error
}
while (ob_get_level()) ob_end_flush();
set_time_limit(0);
fpassthru($fp);
fclose($fp);
exit;
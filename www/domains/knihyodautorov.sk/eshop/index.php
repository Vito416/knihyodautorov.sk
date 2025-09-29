<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php'; // bootstrap by měl inicializovat Database::init(...) + session + CSRF apod.

// --- Acquire Database singleton (expect Database::init() was volané v bootstrapu) ---
try {
    $database = Database::getInstance();
} catch (Throwable $e) {
    // pokud DB není inicializovaná, zkusíme logovat a ukončit s užitečnou stránkou
    try { if (class_exists('Logger')) Logger::error('Database not initialized in index.php', null, ['exception' => (string)$e]); } catch (Throwable $_) {}
    http_response_code(500);
    echo (class_exists('Templates') ? Templates::render('pages/error.php', ['message' => 'Database not available', 'user' => null]) : '<h1>Internal server error</h1><p>Database not available.</p>');
    exit;
}

// --- current user (bootstrap může nastavit $userId / $user) ---
$currentUserId = $userId ?? null;
$user = $user ?? null;

// --- Route detection ---
$uri = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '', '/');
$base = trim('eshop', '/'); // adjust pokud je jiný base
$route = preg_replace('#^' . preg_quote($base, '#') . '/?#i', '', $uri);
$route = $route ?: 'catalog';
$route = preg_replace('/[^a-z0-9_\-]/i', '', $route);

// --- detect fragment/ajax request (to return only content without header/footer) ---
$isFragmentRequest = false;
// explicit query param ?ajax=1 or ?fragment=1
if (isset($_GET['ajax']) && (string)$_GET['ajax'] === '1') $isFragmentRequest = true;
if (isset($_GET['fragment']) && (string)$_GET['fragment'] === '1') $isFragmentRequest = true;
// X-Requested-With header (classic AJAX)
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') $isFragmentRequest = true;
// optional: accept header asking HTML fragment (not required)
if (isset($_SERVER['HTTP_ACCEPT']) && stripos($_SERVER['HTTP_ACCEPT'], 'text/html') !== false && isset($_GET['ajax'])) $isFragmentRequest = true;

// --- Routes: string = handler file (default share true), array = ['file'=>..., 'share'=> true|false|[keys]] ---
$routes = [
    'catalog'        => 'catalog.php',
    'detail'         => 'detail.php',
    'cart'           => 'cart.php',
    'cart_add'           => '/actions/cart_add.php',
    'cart_mini'           => '/actions/cart_mini.php',
    'checkout'       => 'checkout.php',
    'order_submit'       => '/actions/order_submit.php',
    'gopay_callback' => 'gopay_callback.php',
    'order'          => 'order.php',
    'login'          => 'login.php',
    'logout'         => 'logout.php',
    'register'       => 'register.php',
    'verify'         => 'verify.php',
    'password_reset' => 'password_reset.php',
    'password_reset_confirm' => 'password_reset_confirm.php',
    'google'         => 'google_auth.php',
    'profile'        => 'profile.php',
    'download'       => 'download.php',
];

// --- Route exists? ---
if (!isset($routes[$route])) {
    http_response_code(404);
    echo Templates::render('pages/404.php', ['route' => $route, 'user' => $user]);
    exit;
}

// --- Build trustedShared using TrustedShared helper ---
// TrustedShared::create bude best-effort: použije předanou Database, user a userId.
// EnrichUser=true zajistí načtení purchased_books (pokud máš v DB odpovídající metody).
// --- Build trustedShared using TrustedShared helper (fallback safe) ---
if (class_exists('TrustedShared') && method_exists('TrustedShared', 'create')) {
    $trustedShared = TrustedShared::create([
        'database'     => $database,
        'user'         => $user,
        'userId'       => $currentUserId ?? null,
        'gopayAdapter' => $gopayAdapter ?? null,
        'enrichUser'   => false, // pokud už máš manuální fetch výše; změň podle volby A/B
    ]);
} else {
    // fallback: keep minimal manual trustedShared to avoid fatal errors
    $trustedShared = [
        'user'         => $user,
        'csrfToken'    => $csrfToken ?? null,
        'categories'   => [],
        'db'           => $database,
        'gopayAdapter' => $gopayAdapter ?? null,
        'now_utc'      => gmdate('Y-m-d H:i:s'),
    ];
    if (class_exists('Logger')) {
        try { Logger::warn('TrustedShared class missing, using fallback'); } catch (Throwable $_) {}
    }
}

// --- Normalize route config ---
$routeConfig = $routes[$route];
if (is_string($routeConfig)) {
    $handlerPath = __DIR__ . '/' . $routeConfig;
    $shareSpec = true; // default: sdílet všechny trustedShared keys
} elseif (is_array($routeConfig) && isset($routeConfig['file'])) {
    $handlerPath = __DIR__ . '/' . $routeConfig['file'];
    $shareSpec = $routeConfig['share'] ?? true;
} else {
    http_response_code(500);
    echo Templates::render('pages/error.php', ['message' => 'Internal route config error', 'user' => $user]);
    exit;
}
// --- Decide which trustedShared keys to inject into handler scope (sharedForInclude) ---
$sharedForInclude = TrustedShared::select($trustedShared, $shareSpec);

// --- Handler include in isolated scope, with selected shared vars extracted (EXTR_SKIP) ---
$handlerResult = (function(string $handlerPath, array $sharedVars) {
    if (!empty($sharedVars) && is_array($sharedVars)) {
        // EXTR_SKIP: extracted vars nebudou přepsat existující lokální proměnné v handleru
        extract($sharedVars, EXTR_SKIP);
    }

    ob_start();
    try {
        // include handler (may echo, redirect+exit, or return array)
        $ret = include $handlerPath;
        $out = (string) ob_get_clean();
    } catch (Throwable $e) {
        if (ob_get_length() !== false) @ob_end_clean();
        try { if (class_exists('Logger')) Logger::systemError($e); } catch (Throwable $_) {}
        $errHtml = Templates::render('pages/error.php', ['message' => 'Internal server error', 'user' => null]);
        return ['ret' => ['content' => $errHtml], 'content' => $errHtml];
    }

    return ['ret' => $ret, 'content' => $out];
})($handlerPath, $sharedForInclude);

// --- If headers were already sent (redirect etc.), flush captured output and stop ---
if (headers_sent()) {
    if (!empty($handlerResult['content'])) echo $handlerResult['content'];
    return;
}

// --- Normalize handler return into $result (template | content | vars) ---
$result = ['template' => null, 'content' => null, 'vars' => []];
if (is_array($handlerResult['ret'])) {
    if (!empty($handlerResult['ret']['template'])) $result['template'] = (string)$handlerResult['ret']['template'];
    if (!empty($handlerResult['ret']['content']))  $result['content']  = (string)$handlerResult['ret']['content'];
    if (!empty($handlerResult['ret']['vars']) && is_array($handlerResult['ret']['vars'])) $result['vars'] = $handlerResult['ret']['vars'];
}

// Prefer echoed content if handler didn't set 'content' explicitly
if ($result['content'] === null && $handlerResult['content'] !== '') {
    $result['content'] = $handlerResult['content'];
}

// --- Decide which trustedShared keys to pass to the template (sharedForTemplate) ---
$sharedForTemplate = TrustedShared::select($trustedShared, $shareSpec);

// --- Compose final variables for template ---
// We want to PROTECT trustedShared from being overwritten by handler vars,
// so we merge handler vars first, then sharedForTemplate (shared wins).
$contentVars = array_merge($result['vars'], $sharedForTemplate);

// --- Render selection logic ---
$contentHtml = '';

if (!empty($result['template'])) {
    $template = $result['template'];

    // Prevent path traversal and absolute paths.
    if (strpos($template, '..') !== false || strpos($template, "\0") !== false || (isset($template[0]) && $template[0] === '/')) {
        try { if (class_exists('Logger')) Logger::warn('Invalid template path returned by handler', null, ['template' => $template]); } catch (Throwable $_) {}
        $contentHtml = Templates::render('pages/error.php', ['message' => 'Invalid template', 'user' => $user]);
    } else {
        // Resolve to templates directory: templates/<template>
        $tplPath = __DIR__ . '/templates/' . ltrim($template, '/');
        if (!is_file($tplPath) || !is_readable($tplPath)) {
            try { if (class_exists('Logger')) Logger::warn('Template file missing', null, ['template' => $template, 'path' => $tplPath]); } catch (Throwable $_) {}
            $contentHtml = Templates::render('pages/error.php', ['message' => 'Template not found', 'user' => $user]);
        } else {
            // Call renderer with final vars so template receives db, categories, user, etc.
            $contentHtml = Templates::render($template, $contentVars);
        }
    }

} elseif (!empty($contentVars['VAR'])) {
    // handler returned raw HTML via vars['VAR']
    $contentHtml = (string) $contentVars['VAR'];
} elseif (!empty($result['content'])) {
    $contentHtml = $result['content'];
} else {
    $contentHtml = Templates::render('pages/error.php', ['message' => 'Empty content', 'user' => $user]);
}

// --- ensure content-type header ---
if (!headers_sent()) header('Content-Type: text/html; charset=utf-8');

// If fragment request — return only content (no header/footer)
if (!empty($isFragmentRequest)) {
    if (!headers_sent()) header('Content-Type: text/html; charset=utf-8');
    echo $contentHtml;
    return; // nebo exit;
}

// otherwise render full page as before
echo Templates::render('partials/header.php', $trustedShared);
echo $contentHtml;
echo Templates::render('partials/footer.php', $trustedShared);
// done
<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php'; // vrací $db (PDO) + session + CSRF init

// --- Database wrapper pro starší kód (fetchOne / fetchAll) ---
$database = Database::getInstance(); // singleton
$dbWrapper = new class($database) {
    private Database $db;
    public function __construct(Database $db) { $this->db = $db; }
    public function fetchOne(string $sql, array $params = []): ?array { return $this->db->fetch($sql, $params); }
    public function fetchAll(string $sql, array $params = []): array { return $this->db->fetchAll($sql, $params); }
};

// --- current user (z bootstrapu může přijít $userId / $user) ---
$currentUserId = $userId ?? null;
$user = null;
if ($currentUserId !== null) {
    try {
        $user = $dbWrapper->fetchOne('SELECT * FROM pouzivatelia WHERE id=:id LIMIT 1', ['id' => $currentUserId]);
        if ($user) {
            $pbooks = $dbWrapper->fetchAll(
                'SELECT DISTINCT oi.book_id
                 FROM orders o
                 INNER JOIN order_items oi ON oi.order_id = o.id
                 WHERE o.user_id = :uid
                 AND o.status = "paid"',
                ['uid' => $currentUserId]
            );
            $user['purchased_books'] = array_map(fn($b) => (int)$b['book_id'], $pbooks);
        }
    } catch (\Throwable $_) {
        try { if (class_exists('Logger')) Logger::error('Failed to fetch user in index', $currentUserId ?? null, ['exception'=> (string)$_]); } catch (\Throwable $_) {}
        $user = null;
    }
}

// --- CSRF token (pokud existuje) ---
$csrfToken = null;
try {
    if (class_exists('CSRF') && method_exists('CSRF', 'token')) {
        $csrfToken = CSRF::token();
    }
} catch (Throwable $_) {
    $csrfToken = null;
}

// --- Route detection ---
$uri = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '', '/');
$base = trim('eshop', '/'); // adjust pokud je jiný base
$route = preg_replace('#^' . preg_quote($base, '#') . '/?#i', '', $uri);
$route = $route ?: 'catalog';
$route = preg_replace('/[^a-z0-9_\-]/i', '', $route);

// --- Routes: string = handler file (default share true), array = ['file'=>..., 'share'=> true|false|[keys]] ---
$routes = [
    'catalog'        => 'catalog.php',
    'detail'         => 'detail.php',
    'cart'           => 'cart.php',
    'checkout'       => 'checkout.php',
    'gopay_callback' => 'gopay_callback.php',
    'order'          => 'order.php',
    'login'          => 'login.php',
    'logout'         => 'logout.php',
    'register'       => 'register.php',
    'verify'         => 'verify.php',
    'password_reset' => 'password_reset.php',
    'password_reset_confirm' => 'password_reset_confirm.php',
    'google'         => 'google_auth.php',
    'profile'           => 'profile.php',
    'download'       => 'download.php',
];

// --- Route exists? ---
if (!isset($routes[$route])) {
    http_response_code(404);
    echo Templates::render('pages/404.php', ['route' => $route, 'user' => $user]);
    exit;
}

// --- Prepare trusted shared vars for header/footer ---
$categories = [];
try {
    $categories = $dbWrapper->fetchAll('SELECT * FROM categories ORDER BY nazov ASC');
} catch (\Throwable $_) {
    try { if (class_exists('Logger')) Logger::warn('Failed to fetch categories for header'); } catch (\Throwable $_) {}
    $categories = [];
}

// trustedShared obsahuje vše, co chceme sdílet (header/footer + případně do handleru)
$trustedShared = [
    'user'       => $user,
    'csrfToken'  => $csrfToken,
    'categories' => $categories,
    'db'         => $dbWrapper, // dostupné skrze shared
];

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
$sharedForInclude = [];
if ($shareSpec === true) {
    $sharedForInclude = $trustedShared;
} elseif ($shareSpec === false) {
    $sharedForInclude = [];
} elseif (is_array($shareSpec)) {
    foreach ($shareSpec as $k) {
        if (array_key_exists($k, $trustedShared)) $sharedForInclude[$k] = $trustedShared[$k];
    }
}

// --- Handler include in isolated scope, with selected shared vars extracted (EXTR_SKIP) ---
$handlerResult = (function(string $handlerPath, array $sharedVars) {
    if (!empty($sharedVars) && is_array($sharedVars)) {
        // EXTR_SKIP: local handler variables override extracted values if handler defines same name
        extract($sharedVars, EXTR_SKIP);
    }

    ob_start();
    try {
        // include handler (may echo, redirect+exit, or return array)
        $ret = include $handlerPath;
        $out = (string) ob_get_clean();
    } catch (\Throwable $e) {
        if (ob_get_length() !== false) @ob_end_clean();
        try { if (class_exists('Logger')) Logger::systemError($e); } catch (\Throwable $_) {}
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
$sharedForTemplate = [];
if ($shareSpec === true) {
    $sharedForTemplate = $trustedShared;
} elseif ($shareSpec === false) {
    $sharedForTemplate = [];
} elseif (is_array($shareSpec)) {
    foreach ($shareSpec as $k) {
        if (array_key_exists($k, $trustedShared)) $sharedForTemplate[$k] = $trustedShared[$k];
    }
}

// --- Compose final variables for template ---
// We want to PROTECT trustedShared from being overwritten by handler vars,
// so we merge handler vars first, then sharedForTemplate (shared wins).
$contentVars = array_merge($result['vars'], $sharedForTemplate);

// OPTIONAL debug:
// error_log('contentVars keys: ' . implode(',', array_keys($contentVars)));

// --- Render selection logic ---
$contentHtml = '';

if (!empty($result['template'])) {
    $template = $result['template'];

    // Prevent path traversal and absolute paths.
    if (strpos($template, '..') !== false || strpos($template, "\0") !== false || (isset($template[0]) && $template[0] === '/')) {
        try { if (class_exists('Logger')) Logger::warn('Invalid template path returned by handler', null, ['template' => $template]); } catch (\Throwable $_) {}
        $contentHtml = Templates::render('pages/error.php', ['message' => 'Invalid template', 'user' => $user]);
    } else {
        // Resolve to templates directory: templates/<template>
        $tplPath = __DIR__ . '/templates/' . ltrim($template, '/');
        if (!is_file($tplPath) || !is_readable($tplPath)) {
            try { if (class_exists('Logger')) Logger::warn('Template file missing', null, ['template' => $template, 'path' => $tplPath]); } catch (\Throwable $_) {}
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

// --- Render full page: header + content + footer ---
// header/footer always get full trustedShared (independent of shareSpec)
echo Templates::render('partials/header.php', $trustedShared);
echo $contentHtml;
echo Templates::render('partials/footer.php', $trustedShared);

// done
# 🐈‍⬛ Front Controller — `index.php`

Lightweight, secure, and production-ready front controller for the BlackCat e-shop.  
Designed for PHP 8.1+, zero framework dependencies, safe includes, parameterized routes and a minimal early-bootstrap bypass (for payment notifications).

---

## ✨ Key Features

- ✅ **Early minimal bootstrap** for endpoints (e.g. GoPay `/notify`) — very fast, avoids full app bootstrap.  
- ✅ **Parametric routing** with named parameters `{id}`, and greedy multi-segment `{path+}`.  
- ✅ **Canonical URL support** with safe percent-encoding comparison.  
- ✅ **Isolated handler includes** — selected trusted vars injected (EXTR_SKIP) to avoid overwrites.  
- ✅ **TrustedShared helper integration** (best-effort + safe fallback).  
- ✅ **Fragment / AJAX detection** — returns content only (no layout) for `?ajax=1` or `X-Requested-With: XMLHttpRequest`.  
- ✅ **Safe error handling & logging** — graceful 404/500 pages and PSR-3 friendly logging calls.  
- ✅ **Handler contract**: handlers may `echo`, `header('Location: ...') + exit`, or `return ['template'=>..., 'vars'=>..., 'status'=>...]`.

---

## 🚀 Quick Start

Drop `index.php` into your site root (or subfolder) and ensure `inc/bootstrap.php` exists and initializes sessions, CSRF, and `Database::init(...)`.

### Minimal early notify (example)

Put a lightweight handler for payment notifications at `gopay/notify.php` and keep a minimal bootstrap `inc/bootstrap_database_minimal.php`:

```php
// top of index.php (already included)
// detect and short-circuit notify before full bootstrap
$reqPath = bc_normalize_path(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');
if ($reqPath === '/eshop/notify' || $reqPath === '/notify') {
    require_once __DIR__ . '/inc/bootstrap_database_minimal.php';
    require __DIR__ . '/gopay/notify.php';
    exit;
}
```

This keeps notify handling fast and robust (no sessions, no heavy libs).

---

## 🛣️ Route definitions (format)

Routes support two forms:

1. **Simple (string)** — maps a route key to a handler file:
```php
'rate' => 'rate.php'
```

2. **Pattern (array)** — supports `{name}` and greedy `{name+}`, HTTP methods, share spec, canonical and meta:
```php
'category' => [
  'pattern'   => 'category/{path+}',
  'file'      => 'category.php',
  'methods'   => ['GET'],
  'share'     => true,                 // true|false|['db','csrfToken']
  'canonical' => 'category/{path+}',
  'meta'      => ['auth_required' => false],
],
```

**Notes**
- Patterns are matched in defined order. Put specific routes before greedy patterns.
- `{name}` matches a single segment (no `/`), `{name+}` matches multi-segment (can contain `/`).
- `methods` restricts allowed HTTP verbs (responds 405 if mismatched).

---

## 🔁 Canonical redirects

If a route defines `canonical`, the front controller will attempt to build the canonical path using extracted params and will perform a **301 redirect** when appropriate.

Comparison is done on **decoded** paths (`rawurldecode`) to avoid percent-encoding mismatches.

If a required placeholder (e.g. `{slug}`) is not present in the URL, handlers should compute the missing value (usually via DB lookup) and perform the canonical redirect **inside the handler**.

Example handler pattern:

```php
// detail.php (inside handler)
$id = (int)($params['id'] ?? 0);
$book = $db->fetch('SELECT id, slug FROM books WHERE id = :id', ['id'=>$id]);
if (!$book) return ['status'=>404, 'template'=>'pages/404.php'];

$slug = $params['slug'] ?? $book['slug'];
if ($slug !== $book['slug']) {
  header('Location: ' . rtrim($BASE, '/') . '/detail/' . $book['id'] . '-' . rawurlencode($book['slug']), true, 301);
  exit;
}
```

---

## 🧩 Handler contract (what handlers may return)

Handlers may:
- `echo` HTML directly (captured by front controller).  
- `header('Location: ...'); exit;` for redirects (front controller checks headers_sent).  
- `return` an array:
```php
return [
  'template' => 'detail.php',      // path under templates/
  'vars'     => ['book' => $book], // variables merged with trustedShared
  'status'   => 200,               // optional HTTP status
  'content'  => '<div>…</div>'     // optional raw content
];
```

When returned as `vars`, keys from `TrustedShared` **win** (shared keys are merged last to prevent handler overwrite).

---

## 🛡️ TrustedShared helper

`TrustedShared::create()` builds the canonical array passed into handlers and templates:
```php
$trustedShared = TrustedShared::create([
  'database'   => $database,
  'user'       => $user,
  'userId'     => $currentUserId,
  'gopayAdapter'=> $gopayAdapter,
  'enrichUser' => false,
]);
```
Use `TrustedShared::select($trustedShared, $shareSpec)` to choose keys to expose to handlers/templates. The front controller uses `EXTR_SKIP` when injecting shared vars to **prevent accidental overwrite**.

If the `TrustedShared` class is not available, a minimal fallback array is used (user, csrfToken, categories, db, now_utc).

---

## 📡 Fragment / AJAX requests

Fragment requests (return content-only, no header/footer) are detected by:
- query `?ajax=1` or `?fragment=1`, or
- `X-Requested-With: XMLHttpRequest` header.

When detected, the controller returns only the rendered content (no header/footer templates).

---

## 🧾 Logging & Error handling

- All exceptions in handler includes are caught and logged (via `Logger::systemError` if available).  
- Missing templates or invalid template names render a friendly error page (`pages/error.php`).  
- Status codes from handlers are honored (100–599).  
- When headers are already sent (redirects), the controller will flush captured output and stop.

---

## 🔐 Security considerations

- Handlers should always use **prepared statements** and input validation (front controller does not sanitize DB input).  
- `extract(..., EXTR_SKIP)` is used to protect shared variables.  
- Path traversal protections: templates must be resolved under `templates/` and absolute or `..` paths are rejected.  
- Check `is_file()`/`is_readable()` for handlers before including to avoid warnings.

---

## 📚 License & Author

Part of the **BlackCat Core** framework  
(c) 2025 — Black Cat Academy s. r. o. — license: [SEE IN LICENSE](https://github.com/blackcatacademy/blackcat-eshop/blob/master/LICENSE)  
Author: *Vit Black*

---
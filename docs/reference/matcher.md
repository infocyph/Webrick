# Matcher & Routing Engine

How the matcher turns an incoming path/verb into a handler call—plus performance characteristics and debugging tips.

---

## Overview

* Takes **HTTP method**, **host**, **path**, and optional **attributes**.
* Resolves the **best route** by:

    1. Matching **domain** (if any),
    2. Matching **prefix/group** scope,
    3. Evaluating **static** paths first,
    4. Evaluating **tokenized** paths (`{name:constraint}`),
    5. Falling back to **catch-alls**.

Outputs:

* **Handler** (`callable`)
* **Route params** (assoc array)
* **Route meta** (name, middleware list, options)

---

## Matching rules (deterministic)

* Registration order inside a group defines **priority** among peers.
* **Static beats dynamic**: `/users/new` before `/users/{id:int}`.
* **Narrow beats broad**: `{id:int}` before `{id:any}` in the same scope.
* **Catch-all last**: `{path:.*}` only at the tail of a path.
* **Domain scope**: a group with `domain:` only matches that host.

---

## Constraints

Built-in tokens map to regexes. Examples:

| Token   | Regex idea           | Example                     |
| ------- | -------------------- | --------------------------- |
| `:int`  | `\d+`                | `/posts/{id:int}`           |
| `:uuid` | canonical v1–v5 UUID | `/assets/{id:uuid}`         |
| `:slug` | `[A-Za-z0-9._-]+`    | `/tags/{slug:slug}`         |
| `:hex`  | `[A-Fa-f0-9]+`       | `/color/{hex:hex}`          |
| `:any`  | `[^/]+`              | `/u/{name:any}`             |
| custom  | your regex           | `/code/{c:([A-Z]{2}\d{4})}` |

Optional segments: `{name:int?}` (be sure handler provides a default or accepts `?int`).

---

## Parameters extraction & typing

* Extracted params are **strings** initially; handler signatures can **type-hint** scalars:

  ```php
  Route::get('/u/{id:int}', function (int $id) { /* ... */ });
  ```
* Missing optional params resolve to `null` (when `?`), or the handler’s default.

---

## 405 & 404 behavior

* If a path matches but the method does not, matcher returns **405 Method Not Allowed** with an `Allow` header listing valid methods.
* If no path matches in scope (including domain), return **404 Not Found**.

---

## Performance & route cache

The matcher can work directly from a **prebuilt route cache** (directory or fused file). Benefits:

* Avoids reparsing templates on boot.
* Static/dynamic partitions and prefix trees are ready to go.
* O(segments) path walk with small constant factors.

See `reference/route_cache.md` and `deployments/route-cache-warmup.md`.

---

## Domain & group stacking

Groups can be nested:

```php
Route::group(domain:'api.example.com', prefix:'/v1', namePrefix:'api.v1.', callback:function () {
  Route::get('/status', fn()=>['ok'=>true], 'status');
});
```

Final route **name** is `api.v1.status`; final **path** is `/v1/status`.

---

## Debugging matches

Enable a debug dump (in dev) to inspect what the matcher saw:

```php
GET /__routes
```

Shows:

* Route name → method(s) → path template
* Resolved middleware chain
* Domain/prefix composition
* Order indexes

If you don’t ship a route viewer, add a helper to dump names and templates for local use.

---

## Common pitfalls

| Symptom                           | Cause                       | Fix                                                      |
| --------------------------------- | --------------------------- | -------------------------------------------------------- |
| `/users/new` hits show controller | Dynamic captured "new"      | Register static route **before** dynamic                 |
| `{path:.*}` swallows everything   | Catch-all placed too early  | Move it to the last position in scope                    |
| Wrong domain serving admin routes | No `domain:` scope          | Wrap admin routes in a `Route::group(domain:'admin...')` |
| 405 instead of 404                | Path matches another method | Verify verbs; add the missing method or adjust handler   |
| Param not injected                | Name mismatch               | Path `{userId:int}` must match handler param `$userId`   |

---

## Example internals (conceptual)

Sharded cache layout might look like:

```
var/cache/routes/
├─ domains/
│  ├─ default.php
│  └─ admin.example.com.php
├─ static/
│  ├─ GET.php
│  ├─ POST.php
│  └─ ...
└─ dynamic/
   ├─ GET.php
   ├─ POST.php
   └─ ...
```

Each file returns arrays of compiled patterns and handlers; the kernel includes only what it needs on first request.

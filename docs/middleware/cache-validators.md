# Cache Validators (ETag & Last-Modified)

Attach validation metadata and short-circuit requests using the client’s **If-*** headers. When a validator matches, the middleware returns **304 Not Modified** (for safe methods) or **412 Precondition Failed** (for conditional updates) without running your handler.

---

## What it does

* Reads client headers:

    * `If-None-Match`, `If-Modified-Since` (freshness checks)
    * `If-Match`, `If-Unmodified-Since` (preconditions for updates)
    * (When present) coordinates with `Range` semantics*
* Resolves server-side validators via **provider(s)**: `ETag` and/or `Last-Modified`
* Sets `ETag` / `Last-Modified` on normal responses when the controller didn’t
* Applies correct outcomes:

    * **GET/HEAD** with match → **304 Not Modified** (no body)
    * **PUT/PATCH/DELETE** failing preconditions → **412 Precondition Failed**
* Works seamlessly with **Compression** (post-global) by letting compression decide final ETag strategy for full-body responses.

* If you also support byte ranges, evaluative order ensures invalid/stale ranges fall back safely to 200 full responses.

---

## Why use it

* Saves bandwidth and CPU by skipping body generation
* Plays nicely with proxies and browsers (conditional GETs)
* Enforces **optimistic concurrency control** for state-changing requests with `If-Match` / `If-Unmodified-Since`

---

## Wiring

Add to **pre-global** so it can short-circuit before handlers:

```php
$preGlobal[] = \Infocyph\Webrick\Middleware\CacheValidatorsMiddleware::class;
```

Place it **after** negotiation/limits/throttle, and **before** your route handler.

---

## Providing validators

You can supply an **ETag** and/or **Last-Modified** through:

### 1) Controller sets headers explicitly

```php
use Infocyph\Webrick\Response\Response;

Route::get('/posts/{id:int}', function (int $id) {
    $post = load_post($id);
    return Response::json($post)
        ->withHeader('ETag', '"post-' . $post['rev'] . '"')
        ->withHeader('Last-Modified', gmdate(DATE_RFC7231, strtotime($post['updated_at'])));
});
```

### 2) Automatic provider (recommended)

Configure a provider that, given the current request (or route params), returns a tuple: `[$etag, $lastModified]`.

**Example pattern (pseudo):**

```php
$preGlobal[] = new \Infocyph\Webrick\Middleware\CacheValidatorsMiddleware(
    provider: function (\Infocyph\Webrick\Request\Request $r): array {
        if ($id = $r->param('id')) {
            $row = db_find('posts', (int)$id); // returns ['rev' => 'abc123', 'updated_at' => '...']
            $etag = '"' . hash('sha1', 'post:'.$row['rev']) . '"';
            $last = gmdate(DATE_RFC7231, strtotime($row['updated_at']));
            return [$etag, $last];
        }
        return [null, null];
    }
);
```

If the controller later sets one of these headers explicitly, controller headers **win**.

---

## Request evaluation model

Given server-side validators `(ETag_s, LM_s)` and client headers:

1. **If-Match** present → require a match against `ETag_s`

    * Fail → **412 Precondition Failed**
2. **If-Unmodified-Since** present → require `LM_s <= IUS` (resource not modified since timestamp)

    * Fail → **412 Precondition Failed**
3. **If-None-Match** present (GET/HEAD) → if any matches `ETag_s` → **304 Not Modified**

    * For non-safe methods (e.g., `PUT` with `If-None-Match: *`), RFC allows **precondition failure** when entity exists
4. **If-Modified-Since** present (GET/HEAD) → if `LM_s <= IMS` → **304 Not Modified**
5. Otherwise → proceed to handler and set validators on response if missing

> **Priority**: `If-Match`/`If-Unmodified-Since` (preconditions) are evaluated **before** freshness (`If-None-Match`/`If-Modified-Since`).
> `If-None-Match` is more precise than `If-Modified-Since`; if both present, strong ETag usually takes precedence.

---

## 304 Not Modified semantics

When returning **304**:

* **No body**; the middleware strips entity headers that should not appear (e.g., `Content-Type`, `Content-Length`)
* Preserves cache-relevant headers: `ETag`, `Last-Modified`, `Cache-Control`, `Expires`, `Vary`, etc.
* For **HEAD**, status can be 304 or 200 with headers only—middleware picks the standards-compliant path

---

## 412 Precondition Failed semantics

For unsafe or updating methods (e.g., `PUT`, `PATCH`, `DELETE`) with unmet preconditions:

* Returns **412** and should include machine-readable error info in body (you can customize via an error factory)
* Clients must re-fetch the latest representation and retry with updated validators

---

## Coordinating with Compression

* If your controller sets an **ETag** and **Compression** later changes the bytes-on-the-wire, choose an **ETag strategy** in compression (default: recompute-strong) to keep validators correct.
* For **short-circuit** 304 paths, compression isn’t run—no compressed body is produced (correct and fast).

---

## Range requests (optional consideration)

If you support `Range: bytes=...`:

* Evaluate validators first; if stale, respond with **412** (for `If-Match`/`If-Unmodified-Since`) or **200** full body if the range is invalid and no preconditions require failure.
* For valid ranges and fresh validators → **206 Partial Content** (outside the scope of this middleware; implement in your handler/another middleware).

---

## Examples

### 1) Static asset with Last-Modified only

```php
Route::get('/about', function () {
    $html = file_get_contents(__DIR__.'/../views/about.html');
    $lm = gmdate(DATE_RFC7231, filemtime(__DIR__.'/../views/about.html'));
    return Response::create($html, 200, ['Content-Type'=>'text/html; charset=UTF-8'])
        ->withHeader('Last-Modified', $lm)
        ->withHeader('Cache-Control', 'public, max-age=300');
});
```

Now clients using `If-Modified-Since` can be answered with **304**.

### 2) API resource with strong ETag

```php
Route::get('/users/{id:int}', function (int $id) {
    $user = repo()->findUser($id);
    $etag = '"user-' . hash('sha256', $user['rev']) . '"';
    return Response::json($user)->withHeader('ETag', $etag);
});
```

Client:

```
GET /users/7
If-None-Match: "user-<hash>"
```

→ **304** when unchanged.

### 3) Optimistic concurrency on update

```php
Route::put('/users/{id:int}', function (Request $r, int $id) {
    $current = repo()->findUser($id);
    $currentEtag = '"user-' . $current['rev'] . '"';

    // Enforce If-Match
    $ifMatch = $r->getHeaderLine('if-match');
    if (!$ifMatch || !in_array($currentEtag, array_map('trim', explode(',', $ifMatch)), true)) {
        return Response::json(['error'=>'precondition failed'], 412);
    }

    // ... perform update producing new rev ...
    $updated = repo()->updateUser($id, $r->json());
    return Response::json($updated)
        ->withHeader('ETag', '"user-' . $updated['rev'] . '"');
});
```

(If you rely on the middleware to enforce `If-Match`, supply a provider and let it short-circuit to **412** automatically.)

---

## Configuration knobs (typical)

* `provider(callable|null)` – returns `[$etag, $lastModified]` (either can be `null`)
* `preferEtagOverLastModified` (bool) – when both client validators provided, prioritize ETag logic
* `enableIfMatchPreconditions` (bool, default true)
* `enableIfNoneMatch` (bool, default true)
* `enableIfModifiedSince` / `enableIfUnmodifiedSince` (bools)
* `clockSkewSeconds` – tolerate small timestamp skew (e.g., 1–5s)

*(Adapt names to your constructor/options as implemented.)*

---

## Troubleshooting

| Symptom                        | Likely cause                       | Fix                                                                |
| ------------------------------ | ---------------------------------- | ------------------------------------------------------------------ |
| 200 instead of 304             | No validators provided             | Ensure controller sets ETag/Last-Modified or configure a provider  |
| 304 served but content differs | ETag mismatches due to compression | Use compression’s **recompute-strong** or align strategy           |
| Clients never revalidate       | Missing cache headers              | Add `Cache-Control`/`Expires` with reasonable freshness            |
| Updates overwrite stale state  | `If-Match` not enforced            | Require `If-Match` on updates or let middleware precondition-check |
| Time-based validators flaky    | Clock skew                         | Allow small skew; use ETag where possible                          |

---

## Best practices

* Prefer **ETag** for precision; use **Last-Modified** as a coarse fallback.
* Keep ETag generation **stable** across releases (hash of canonical representation or revision ID).
* Apply validators to **GET/HEAD** responses and require **If-Match** for **PUT/PATCH/DELETE**.
* Pair with **Compression** and **Vary Accumulator** to keep caches correct.
* Be generous with `Cache-Control` on static/slow-changing content; keep dynamic endpoints private or short-lived.

---

## Checklist

* [ ] Add CacheValidators middleware in **pre-global**
* [ ] Provide ETag/Last-Modified (controller or provider)
* [ ] Enforce preconditions for unsafe methods (`If-Match` / `If-Unmodified-Since`)
* [ ] Return clean **304** (no body) and preserve cache headers
* [ ] Align with compression ETag strategy
* [ ] Document client revalidation in your API docs

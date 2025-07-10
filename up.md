## 1 How **Request → Route → Response** hang together today

| Stage                   | What you already ship                                                                                                                                             | Nice touches                                                                                      | Gaps / frictions                                                                                                                                                                                |
| ----------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Request**       | `Request` façade extends your lean PSR-7 `ServerRequest` and keeps Laravel-style helpers (e.g. `input()`, `expectsJson()`, dot-notation `data()` etc.) | • Lazy JSON / XML parsing`<br>`• Automatic method-spoofing (`getEffectiveMethod()`)         | • Validation stub only throws `InvalidArgumentException`; no rules engine or error bag`<br>`• No first-class *FormRequest* analogue (rules + authorisation + sanitisation in one place) |
| **Routing layer** | Clean PSR-15 router contract (`RouterInterface`) plus HTTP-verb shortcuts and `urlFor()` generator                                                            | • Route compilation & caching already abstracted (`RouteCache`)                                | • I/O path is missing: we don’t see an**actual dispatcher** that turns the compiled table into a constant-time lookup.                                                                  |
| **Response**      | Ultra-lean immutable `Response` with helpers `json()`, `redirect()`, `attachment()/download()`                                                            | • Branch-free internal cloning`<br>`• Built-in `Cache-Control` builder and security headers | • No*Response Macro* registration like Laravel’s `Response::macro()` (would fit nicely given you already use `MacroMix`)                                                                |

---

## 2 Fast-path performance: where to shave more latency

| Hot path                         | Suggested tweak                                                                                                                                                                                                                                                            | Expected win                                                                                     |
| -------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------ |
| **Route dispatch**         | Compile*one* combined PCRE for static & param routes (FastRoute-style) and freeze it to an opcode-cached PHP file instead of serialising objects. Keep the matching loop branch-free by ordering routes **first by method, then by host, finally by regex index**. | 10-30 µs saved per request on PHP-FPM for medium tables (3-4× faster than naïve `foreach`). |
| **Method override & CSRF** | You already pay to parse `$_POST` for `_method` and CSRF every time. Gate those middlewares behind a cheap header check (`content-type` + `content-length>0`) to avoid `php://input` reads on most GET requests.                                                 | \~5 µs off uncached GETs                                                                        |
| **Request variable map**   | `Request::buildVariableMap()` walks the whole bag on every clone. Memoise the *final* merged map and only rebuild when `query`, `parsedBody`, or `cookie` actually change.                                                                                       | \~2 µs per additional `with*()` hop                                                           |
| **Response emit**          | In `SapiEmitter` you already chunk-flush but still echo full body for small payloads. Add `if ($len < 8192) { echo $body; return; }` fast-path.                                                                                                                        | Avoid fsync/flush call on tiny JSON                                                              |

---

## 3 Feature-parity audit vs. Laravel 10/11

| Laravel feature                                      | Present?                                                               | How to add / where to hook                                                                                                     |
| ---------------------------------------------------- | ---------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------ |
| Route groups (`Route::prefix()->middleware()`)     | **Partly** (middleware via `RouteInterface::withMiddleware()`) | Add a `scope()` / `group()` builder that pushes defaults onto a stack during registration.                                 |
| Route model binding & implicit bindings              | ✗                                                                     | Decorate the dispatcher: when a placeholder has `:int` etc. inject the matching Eloquent model or return 404.                |
| Resource / apiResource routes                        | ✗                                                                     | Thin macro that expands to seven route definitions; keep optional `only()`/`except()`.                                     |
| Route caching CLI (`route:cache`, `route:clear`) | **Half-done** (class `RouteCache` exists)                      | Provide small CLI wrapper (Symfony Console) that calls `RouteCache::warm()` and writes to `bootstrap/cache/routes.php`.    |
| Signed URLs / URL verification                       | ✗                                                                     | Re-use your existing `CookieEncryptionMiddleware` AES-GCM helper to HMAC the query string and add `Route::signed()` check. |
| Response macros (`Response::macro()`)              | ✗ (but `MacroMix` is already included)                              | Expose `Response::macro()` & `Response::hasMacro()` proxies that just forward to `MacroMix`.                             |

---

## 4 Concrete next steps (ordered, bite-sized)

1. **Ship a real dispatcher**
   *Take inspiration from nikic/FastRoute*:

   ```php
   // at container boot
   $routes = $cache->load() ?? $compiler->build($definitions);
   [$static, $dynamic] = $optimiser->split($routes);
   $matcher = new CombinedRegexMatcher($static, $dynamic);
   ```

   The matcher returns `[handler, vars]` in ≤ O(1).
2. **Write a `RouteServiceProvider`** that registers:

   * global middlewares (trust-proxies, error-handler, etc.)
   * route groups / prefixes
   * HTTP kernel order list (so users can reorder like Laravel’s `$middlewarePriority`).
3. **Elevate validation**
   Swap the stub in `Request::validate()` for the new `symfony/validator`-5 component; cache parsed rules to APCu.
4. **Add macro support everywhere**
   `MacroMix` is already a trait ­– expose sugar on `Response`, `Router`, and `Request` so userland can do:

   ```php
   Response::macro('caps', fn($txt) => Response::json(['msg'=>strtoupper($txt)]));
   ```
5. **Generate CLI tooling**
   Small Symfony Console app:

   * `webrick route:list` – dump compiled table
   * `webrick route:cache` – warm and write PHP file
   * `webrick cache:clear` – prune PSR-6/16 stores

---

## 5 Micro-optimisations you can do in-place

* Replace `preg_match(self::NO_COMPRESS_RX…)` inside `CompressionMiddleware` with `str_contains()` slice checks for the
  common happy-path (`text/html;…`) before falling back to regex.
* Inline the tiny `norm()` helper in `HeaderBag` (it’s hot in every header lookup).
* In `Response::json()` switch to `json_encode($data, flags|JSON_THROW_ON_ERROR)` then catch once in the middleware to
  avoid per-call branch.

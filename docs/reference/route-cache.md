# Route Cache Reference

Deep dive into the structure, build process, and runtime integration of Webrick’s route cache.

---

## What is cached?

* **Compiled route table**: prefix trees for static segments and compiled regexes for dynamic segments.
* **Metadata**: route names, middleware arrays, domain scopes, group prefixes.
* **Helpers**: precomputed URL templates for fast `urlFor()`.

Two modes:

* **Sharded (directory)** – multiple small PHP files.
* **Fused (single file)** – one big PHP file.
* **Generated (in-memory)** – no cache artifact files.

---

## Build inputs

* Your **registrar closure** (the same one called in production boot):

    * It must `require routes/*.php`, add groups, and (if used) call attribute loaders.
* Optional **options** (e.g., expose URL services for `urlFor()`).

Example builder (recap):

```php
use Infocyph\Webrick\Support\RouteCache;

RouteCache::build([
  'cache'   => __DIR__ . '/../.route-cache', // sharded directory
  'routes'  => __DIR__ . '/../routes/web.php',
  'registrarOptions' => [
    'exposeUrlServices' => true,
  ],
]);
```

---

## Fused vs Sharded: choosing

| Criterion     | Sharded             | Fused          |
| ------------- | ------------------- | -------------- |
| App size      | Medium–large        | Tiny–small     |
| Deploy diffs  | Smaller             | Larger         |
| Invalidation  | Partial possible    | All-or-nothing |
| Include cost  | Many small includes | One include    |
| Human diffing | Easier              | Harder         |

Default to **sharded** unless your app is very small.

Use **generated** only when you intentionally want no route-cache artifacts.

---

## Runtime wiring

At kernel boot, point to the cache:

```php
use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Infocyph\Webrick\Router\Matching\ShardedMatcher;
use Psr\Log\NullLogger;

$kernel = RouterKernel::bootWithRegistrar(
  log: new NullLogger(),
  matcher: ShardedMatcher::make(__DIR__ . '/../.route-cache'),
  register: require __DIR__ . '/../routes.php',
  routeCache: __DIR__ . '/../.route-cache', // sharded directory
  // routeCache: __DIR__ . '/../.route-cache/__routes.php', // fused file
);
```

* If the cache exists and passes a quick **signature check** (version/hash), the matcher uses it.
* If missing (dev), the kernel may fall back to building in-memory (config-dependent). In **prod**, prefer failing fast.

---

## Cache contents (conceptual)

A sharded directory may include:

```
routes/
  meta.php                # version, build time, app hash
  url_templates.php       # name => '/users/{id}'
  name_index.php          # quick lookup name -> internal id
  domains/default.php     # default host routing table
  domains/admin.php       # domain-scoped table
  methods/GET.static.php  # flat map: '/ping' => handler/meta
  methods/GET.dynamic.php # compiled regex list with param maps
  methods/POST.*.php      # similarly for each method
```

Each PHP file returns a **plain array**; includes are cheap and OPCache stores them.

---

## Validations at build time

The builder should error out (CI failure) on:

* **Duplicate route names**
* **Conflicting static paths** for the same method/scope
* **Illegal tokens** or unbalanced braces
* **Ambiguous optional segments** that create indistinguishable patterns

Treat these as **compile-time** failures rather than runtime surprises.

---

## URL generation synergy

When `exposeUrlServices` is enabled during build, the cache also stores **reverse templates**:

```php
Route::urlFor('users.show', ['id'=>42]);
// uses cached template "/users/{id}" and escapes/joins quickly
```

Absolute URLs use host rules (domain groups) or app base URL.

---

## Compression, validators & cache

* Route cache is **unrelated** to response/body caches; it only speeds up **matching** and **URL generation**.
* It plays well with **Compression** and **CacheValidators** by simply getting the request to the right handler faster.

---

## Rebuild & invalidation

* **CI on every commit**: run the builder and ship the result with the artifact/container.
* **No runtime rebuild** in prod (recommended). If you must support it:

    * Guard with a flag; rebuild on a **maintenance** window.
    * Swap the cache directory/file atomically.

---

## Troubleshooting

| Symptom                                  | Likely cause                                | Fix                                                                            |
| ---------------------------------------- | ------------------------------------------- | ------------------------------------------------------------------------------ |
| Boot fails with “cache version mismatch” | Code & cache out of sync                    | Rebuild cache in CI with the current code; redeploy                            |
| Route missing in prod                    | Attribute loader wasn’t called during build | Ensure `$register` closure calls the attribute loader                          |
| Stale route still matched                | Old artifact deployed                       | Verify release artifact includes fresh `.route-cache`; reload FPM          |
| Dev builds fine, CI fails                | Different PHP/extension set                 | Align PHP versions/exts between CI and prod; lock composer platform            |
| URL generation throws                    | Name not in cache                           | Ensure route is named (`'as' => '...')`; rebuild with `exposeUrlServices:true` |

---

## Checklist

* [ ] Use **sharded** cache by default; fused for very small apps
* [ ] Build in **CI**, fail fast on conflicts/invalid tokens
* [ ] Ship the cache with the artifact; point kernel to the same path
* [ ] Include attribute routes in the same registration flow (build & runtime)
* [ ] Keep prod immutable—no runtime rebuilds; use maintenance if ever necessary

# Route Cache & Warmup

Build your route matcher **ahead of time** so production boots fast and avoids scanning/reflecting on every request. Webrick supports **sharded** (directory) and **fused** (single file) caches; both can be produced in CI and shipped with the release.

---

## Why cache routes?

* **Faster boot**: skip discovery/registration work on every request.
* **Stable perf**: avoid variance from filesystem/Composer autoload during runtime.
* **Safer deploys**: cache is validated during CI—fail early, not in prod.

---

## Modes: Sharded vs Fused

| Mode        | What it is                                    | Best for              | Pros                                                      | Cons                                                     |
| ----------- | --------------------------------------------- | --------------------- | --------------------------------------------------------- | -------------------------------------------------------- |
| **Sharded** | Many small optimized PHP files in a directory | Medium/large apps     | Incremental, diff-friendly; quick invalidation of subsets | Slightly more inodes; tiny overhead of multiple includes |
| **Fused**   | One big optimized PHP file                    | Small apps, functions | One include; simple artifact                              | Rebuild whole file for any change; big diff              |

Pick one and stick to it per environment. Sharded is a great default.

---

## Directory layout

```
var/
└─ cache/
   └─ routes/          # sharded cache directory (commit: NO; deploy: YES)
```


---

## RouteCache API Reference

### Build (Sharded)
```php
use Infocyph\Webrick\Support\RouteCache;
use Psr\Log\NullLogger;

$sentinel = RouteCache::build([
    'cache'   => __DIR__ . '/../var/cache/routes',  // Directory (auto-detects sharded)
    'routes'  => __DIR__ . '/../routes/web.php',    // Routes file
    'matcher' => 'sharded',                          // Optional (auto-detected by path)
    'signKey' => $signKey,
    'signedDefaultTtl' => 900,
    'fallbackAliasesFromRegistrar' => true,
    'logger' => new NullLogger(),
    'registrarOptions' => [
        'autoSlashRedirect' => false,
        'exposeUrlServices' => true,
    ],
    'preGlobal' => [],   // Optional: middleware for warmup validation
    'postGlobal' => [],
    'bindUrlServices' => static function (Collection $routes) use ($signKey): void {
        Response::bindUrlServices($routes, $signKey, 900);
    }
]);

echo "Route cache built. Sentinel: {$sentinel}\n";
```

### Build (Fused)
```php
$sentinel = RouteCache::build([
    'cache'  => __DIR__ . '/../var/cache/routes.php',  // Single file (auto-detects fused)
    'matcher' => 'fused',                               // Explicit
    'routes' => __DIR__ . '/../routes/web.php',
    'signKey' => $signKey,
    'signedDefaultTtl' => 900,
    'fallbackAliasesFromRegistrar' => true,
    'logger' => new NullLogger(),
    'registrarOptions' => [
        'autoSlashRedirect' => false,
        'exposeUrlServices' => true,
    ],
]);
```

### Build with Closure (No routes.php)
```php
use Infocyph\Webrick\Router\Definition\Registrar;

$sentinel = RouteCache::build([
    'cache' => __DIR__ . '/../var/cache/routes',
    'register' => static function (Registrar $r): void {
        // Define routes directly
        $r->get('/ping', fn() => 'pong', 'ping');
        $r->get('/hello/{name}', fn($req, $name) => Response::json(['hello' => $name]));

        // Or include files
        require __DIR__ . '/../routes/web.php';

        // Or scan attributes
        AttributeRouteLoader::registerFromDirs($r, [
            'App\\Http\\Routes\\' => __DIR__ . '/../src/Http/Routes'
        ]);
    },
    'signKey' => $signKey,
    'signedDefaultTtl' => 900,
    'fallbackAliasesFromRegistrar' => true,
]);
```

### Clear Cache
```php
// Safe clear (keeps directory, removes PHP files)
$removed = RouteCache::clear([
    'cache' => __DIR__ . '/../var/cache/routes',
    'aggressive' => false  // Default: safe mode
]);

if ($removed) {
    echo "Cache cleared successfully.\n";
}

// Aggressive clear (removes entire directory - use with caution)
$removed = RouteCache::clear([
    'cache' => __DIR__ . '/../var/cache/routes',
    'aggressive' => true  // ⚠️ Deletes the whole directory
]);
```

**Note**: Aggressive mode is useful in containerized environments where you recreate the directory on each build.

### Validation During Build

The builder validates routes and fails fast on:

- **Duplicate route names**: Two routes with same `name:`
- **Conflicting paths**: Same path + method with different handlers
- **Invalid tokens**: Malformed `{param:constraint}` syntax
- **Ambiguous patterns**: Optional segments that create indistinguishable routes

**Example CI Script**:
```bash
#!/bin/bash
set -e  # Exit on error

echo "Building route cache..."
php scripts/build-route-cache.php

if [ $? -eq 0 ]; then
    echo "✅ Route cache built successfully"
else
    echo "❌ Route cache build failed - check for conflicts"
    exit 1
fi
```
Ensure the directory exists and is **readable** by PHP-FPM at runtime. You may let CI precreate and populate it.

---

## CI script (sharded) – example

`scripts/build-route-cache.php`:

```php
<?php
declare(strict_types=1);

use Infocyph\Webrick\Support\RouteCache;
use Psr\Log\NullLogger;

require __DIR__ . '/../vendor/autoload.php';

$cacheDir = __DIR__ . '/../var/cache/routes';
$routes   = __DIR__ . '/../routes/web.php';

if (!is_dir($cacheDir) && !mkdir($cacheDir, 0775, true)) {
    fwrite(STDERR, "Cannot create cache dir: $cacheDir\n");
    exit(1);
}

RouteCache::build([
  'cache'   => $cacheDir,         // sharded directory
  'routes'  => $routes,           // registrar uses your $register closure
  'logger'  => new NullLogger(),
  'registrarOptions' => [
    'exposeUrlServices' => true,
    // If you use attribute routes, call your loader in the same $register closure
  ],
]);

echo "[webrick] route cache built into $cacheDir\n";
```

**Run in CI:**

```bash
php scripts/build-route-cache.php
```

Add `var/cache/routes/` to the release artifact (or bake into your container image).

---

## Using fused mode (tiny apps)

If your library exposes a `RouteCache::buildFused()` (or a mode flag), switch to single-file output:

```php
RouteCache::build([
  'cache'  => __DIR__ . '/../var/cache/routes.php', // fused file
  'mode'   => 'fused',
  // ...same options...
]);
```

At boot, point the matcher to the fused file instead of a directory.

---

## Boot configuration

When you boot the kernel, set the **same path** you built in CI:

```php
$kernel = RouterKernel::bootWithRegistrar(
  // ...
  routeCache: __DIR__ . '/../var/cache/routes',        // sharded dir OR
  // routeCache: __DIR__ . '/../var/cache/routes.php', // fused file
  // ...
);
```

No extra flags needed—if the cache exists, the matcher uses it.

---

## Attribute routes (don’t forget)

If you use **attribute-based** routes, the **same loader call** must run during warmup and runtime. The safest pattern:

* Put `AttributeRouteLoader::registerFromDirs(...)` inside the same `$register` closure that includes `routes/web.php`.
* The warmup script invokes that closure—so both prod and CI generate **identical** registrations.

---

## Validation & failure modes

* The builder should **fail** when:

    * A duplicate route name/path conflicts
    * A malformed parameter token is detected
    * Your `$register` closure throws

Treat failures as **CI failures**; don’t deploy a broken cache.

---

## Cache invalidation strategies

* **On release**: you ship a fresh cache with each artifact → no runtime rebuilds needed.
* **Manual clear** (rare): if you must clear at runtime, remove the directory/file and reload FPM; the app can rebuild on boot if you enable that behavior (off by default in prod).
* **Tagging** (optional): if your cache supports tags, you can clear subsets—usually overkill for route caches.

---

## Permissions

* Artifact must include `var/cache/routes/` with proper ownership for your PHP-FPM user (e.g., `www-data`).
* In container builds, ensure the directory exists and is writable at build time (even if runtime is read-only).

---

## Measuring the win

Compare app boot time with and without cache:

* **Without**: bootstrap + route registration + attribute scanning
* **With**: include cached files only

Expose a simple `/__bootcheck` and log a `boot_ms` metric during the first request after FPM worker start.

---

## Troubleshooting

| Symptom                               | Likely cause                       | Fix                                                                              |
| ------------------------------------- | ---------------------------------- | -------------------------------------------------------------------------------- |
| 404 on all routes in prod             | `routeCache` points to wrong path  | Verify absolute path; ensure artifact contains the cache                         |
| Warmup script works locally, fails CI | Missing PHP extensions/permissions | Match CI PHP version/exts to prod; create cache dir                              |
| Attribute routes missing              | Loader not invoked during warmup   | Ensure `$register` runs the attribute loader                                     |
| Fused file ignored                    | Kernel expects directory           | Switch `routeCache` to file path or use sharded mode                             |
| Cache “sticks” after refactor         | Old artifact deployed / no restart | Redeploy new artifact; reload PHP-FPM; ensure symlink flip updated the cache dir |

---

## Checklist

* [ ] Choose **sharded** (default) or **fused** cache mode
* [ ] Add a **warmup script** and run it in CI
* [ ] Ship the cache in the artifact/container
* [ ] Configure kernel `routeCache` to the same path
* [ ] Keep attribute registration identical in warmup & runtime
* [ ] Verify permissions and measure boot improvements

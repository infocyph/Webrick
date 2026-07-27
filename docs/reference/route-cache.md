# Route Cache Reference

Webrick route cache stores compiled PHP routing artifacts so normal requests do
not rebuild route structures. The build step is allowed to spend CPU and I/O to
reduce request-time work.

## Modes and locations

| Mode | `cache` / `routeCache` value | Artifact |
| --- | --- | --- |
| `sharded` | Directory, for example `.route-cache` | Root, aliases and route shard PHP files |
| `fused` | File, for example `.route-cache/fused.php` | One PHP routing data file |
| `generated` | File, for example `.route-cache/generated.php` | One PHP file with generated matcher code |

If the matcher mode is omitted, a path ending in `.php` selects fused mode;
other paths select sharded mode. Specify the mode explicitly in deployment
automation so intent remains clear.

## Build with the PHP API

```php
use Infocyph\Webrick\Support\RouteCache;

$artifact = RouteCache::build([
    'matcher' => 'sharded',
    'cache' => __DIR__ . '/../.route-cache',
    'routes' => __DIR__ . '/../routes.php',
    'signKey' => $_ENV['WEBRICK_SIGN_KEY'] ?? 'dev-key',
    'signedDefaultTtl' => 900,
    'registrarOptions' => [
        'urlBaseUri' => $_ENV['WEBRICK_URL_BASE_URI'] ?? 'http://localhost',
    ],
]);
```

Relevant options:

| Key | Values / example | Meaning |
| --- | --- | --- |
| `matcher` | `sharded`, `fused`, `generated` | Cache format |
| `cache` | `/app/cache/routes` or `/app/cache/routes.php` | Required output location |
| `routes` | `/app/routes.php` | Route file; use this or `register` |
| `register` | callable | Programmatic route registration; use this or `routes` |
| `signKey` | non-empty secret string or `null` | Signed URL generation key |
| `signedDefaultTtl` | integer seconds, for example `900` | Default temporary URL lifetime |
| `signedUrlConfig` | `SignedUrlConfig` or configuration array | Full signing behavior |
| `urlBaseUri` | `https://example.com` | Base URI for absolute URL generation |
| `registrarOptions` | associative array | Options forwarded to the registrar |
| `preGlobal`, `postGlobal` | middleware descriptor lists | Global middleware metadata for build parity |
| `fallbackAliasesFromRegistrar` | `true` or `false` | Allow alias-only registration fallback |
| `attributeDirs` | namespace-to-directory map | Attribute discovery inputs |
| `attributeClasses` | list of class strings | Explicit attribute route classes |
| `logger` | `Psr\Log\LoggerInterface` | Build diagnostics |

Use the same route inputs and URL configuration for cache build and runtime.

## Build with the CLI

```bash
php ./webrick route:cache --matcher=sharded --cache=.route-cache --routes=routes.php
php ./webrick route:cache --matcher=fused --cache=.route-cache/fused.php --routes=routes.php
php ./webrick route:cache --matcher=generated --cache=.route-cache/generated.php --routes=routes.php
```

The installed Composer binary is also available at `vendor/bin/webrick` and a
package checkout can use:

```bash
composer run webrick -- route:cache --matcher=sharded --cache=.route-cache --routes=routes.php
```

CLI build options include:

- `--signkey=KEY`
- `--ttl=900`
- `--alias-fallback=1|0`
- `--attr-dirs="App\\Http\\=src/Http"`
- `--attr-classes="App\\Http\\HealthController,App\\Http\\UserController"`
- `--verbose=1`

## Runtime wiring

```php
$kernel = RouterKernel::bootWithRegistrar(
    log: $logger,
    matcher: ShardedMatcher::make(),
    register: $register,
    routeCache: __DIR__ . '/../.route-cache',
    registrarOptions: [
        'signKey' => $_ENV['WEBRICK_SIGN_KEY'],
        'signedDefaultTtl' => 900,
        'urlBaseUri' => 'https://example.com',
    ],
);
```

Use `FusedMatcher::make()` with the fused file or
`GeneratedMatcher::make()` with the generated file. The matcher factory never
receives the cache path.

On a valid cache hit:

- the registrar does not rebuild the matcher;
- class handlers and string middleware remain scalar until their route matches;
- the materialized route is memoized;
- URL aliases and the URL generator remain lazy unless a custom binding callback
  requests eager behavior.

If alias metadata is unavailable and `fallbackAliasesFromRegistrar` is `true`,
the kernel can run registration only to rebuild URL aliases without replacing
the cached matcher. A complete deployment artifact should normally make that
fallback unnecessary.

## What the cache includes

- compiled route match data;
- handler and middleware descriptors;
- route names, paths, domains, constraints and CORS metadata;
- name-to-path and name-to-domain alias metadata;
- generated matching code in generated mode.

It does not contain instantiated application services, a request, controller
objects for class-based handlers, or resolved lazy middleware.

## Clear artifacts

```bash
php ./webrick route:clear --matcher=sharded --cache=.route-cache
php ./webrick route:clear --matcher=fused --cache=.route-cache/fused.php
php ./webrick route:clear --matcher=generated --cache=.route-cache/generated.php
```

Normal sharded clearing removes known Webrick PHP artifacts while allowing
different named matcher-cache files to coexist. `--aggressive=1` recursively
purges the sharded cache directory while preserving a root `.gitignore`.

## Deployment rules

- Build in CI or deployment, never in normal request handling.
- Build as the same application release that will consume the artifacts.
- Publish code and route caches atomically.
- Keep artifacts read-only to the web process when runtime rebuilds are not
  required.
- Rebuild after changing Webrick, route definitions, handler or middleware
  descriptors, attribute discovery, signing settings, or matcher mode.
- Delete and rebuild caches during a major Webrick upgrade.
- Treat cache files as executable trusted PHP; never load user-provided cache
  artifacts.

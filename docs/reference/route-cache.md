# Route Cache Reference

Webrick route cache stores compiled PHP routing artifacts so normal requests do
not rebuild route structures. The build step is allowed to spend CPU and I/O to
reduce request-time work.

## Modes and locations

| Mode | `cache` / `routeCache` value | Artifact |
| --- | --- | --- |
| `sharded` | Directory, for example `.route-cache` | Atomic manifest plus immutable generation directories containing aliases and route shards |
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

String handlers, `[Controller::class, 'method']`, named functions, and safe
first-class public static callables are stored as native scalar descriptors.
Webrick converts a first-class static callable only when reflection proves that
it is unbound, captures no variables, names a public static method, and
preserves the called class for late static binding. Captured closures, instance
callables, and genuine closures retain the serializer fallback.

Every matcher exposes `middlewareRequirements()`, returning registered alias
names actually referenced by cached routes. Framework integrations may use the
list as a cache-time module activation hint. Unknown or dynamically registered
aliases must retain the normal registrar fallback. The cache stores descriptors
only; it never freezes executable, scoped, or stateful middleware pipelines.

If alias metadata is unavailable and `fallbackAliasesFromRegistrar` is `true`,
the kernel can run registration only to rebuild URL aliases without replacing
the cached matcher. A complete deployment artifact should normally make that
fallback unnecessary.

## What the cache includes

- compiled route match data;
- handler and middleware descriptors;
- registered middleware alias requirements referenced by routes;
- route names, paths, domains, constraints and CORS metadata;
- name-to-path and name-to-domain alias metadata;
- generated matching code in generated mode.

It does not contain instantiated application services, a request, controller
objects for class-based handlers, or resolved lazy middleware.

## Validation and publication

Cache generation deliberately does more work than a request:

1. route metadata is normalized to its final scalar representation;
2. every scalar route payload is fully validated;
3. PHP is written to a temporary file;
4. the staged PHP file is loaded and its version, shape and checksum are verified;
5. only a valid artifact is atomically renamed into service.

Fused and generated modes replace one file atomically. Sharded mode writes all
files into a unique immutable `generation-*` directory. It publishes a validated
manifest and, where symbolic links are available, atomically switches a
`__current` pointer. The manifest is the portable fallback. A failed build
leaves the previous generation usable. Readers pin the resolved generation, and
old directories remain available so persistent workers can finish lazy shard
loading safely; clear them during a controlled deploy or with `route:clear`.

Cache format versions are exact. Webrick rejects an old format with a rebuild
message instead of normalizing it during a request. All cache files are trusted
application-generated executable PHP; do not accept their paths from requests
or untrusted configuration.

## Clear artifacts

```bash
php ./webrick route:clear --matcher=sharded --cache=.route-cache
php ./webrick route:clear --matcher=fused --cache=.route-cache/fused.php
php ./webrick route:clear --matcher=generated --cache=.route-cache/generated.php
```

Normal sharded clearing removes the manifest, legacy root artifacts, and
generation directories while allowing different named matcher-cache files to
coexist. `--aggressive=1` recursively purges the sharded cache directory while
preserving a root `.gitignore`.

## Deployment rules

- Build in CI or deployment, never in normal request handling.
- Build as the same application release that will consume the artifacts.
- Let the cache builder publish validated artifacts atomically; do not copy
  individual sharded files into a live cache directory.
- Keep artifacts read-only to the web process when runtime rebuilds are not
  required.
- Rebuild after changing Webrick, route definitions, handler or middleware
  descriptors, attribute discovery, signing settings, or matcher mode.
- Delete and rebuild caches during a major Webrick upgrade.
- Treat cache files as executable trusted PHP; never load user-provided cache
  artifacts.

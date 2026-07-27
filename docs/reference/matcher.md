# Matcher

Webrick provides three matching strategies. All three can run in memory and all
three can boot from a deploy-time cache artifact.

Matcher factories take no arguments:

```php
$matcher = ShardedMatcher::make();
```

When using `RouterKernel`, pass the cache location as `routeCache:`. The kernel
enables cache reads on the matcher. Cache writes remain an explicit
`RouteCache::build()` or `route:cache` operation.

## Comparison

| Matcher | Artifact | Request-time shape | Good fit |
| --- | --- | --- | --- |
| `ShardedMatcher` | Directory of PHP files | Loads root metadata and the relevant shard | Medium or large route sets |
| `FusedMatcher` | One PHP file | Uses one consolidated routing structure | Simple immutable deployment artifacts |
| `GeneratedMatcher` | One PHP file with generated matcher code | Executes bucketed generated PHP matching code | Workloads validated to benefit from generated matching |

Do not choose only from a synthetic microbenchmark. Benchmark your route count,
static/dynamic mix, host routing, OPcache settings, filesystem, process model,
and traffic distribution.

## Sharded matcher

```php
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Infocyph\Webrick\Router\Matching\ShardedMatcher;
use Psr\Log\NullLogger;

$kernel = RouterKernel::bootWithRegistrar(
    log: new NullLogger(),
    matcher: ShardedMatcher::make(),
    register: static function (Registrar $registrar): void {
        require __DIR__ . '/routes.php';
    },
    routeCache: __DIR__ . '/.route-cache',
);
```

The cache location is a directory. The build produces root and alias metadata
plus route shards. This limits the routing data required for a request and
usually provides the best production default for non-trivial applications.

## Fused matcher

```php
use Infocyph\Webrick\Router\Matching\FusedMatcher;

$kernel = RouterKernel::bootWithRegistrar(
    log: new NullLogger(),
    matcher: FusedMatcher::make(),
    register: $register,
    routeCache: __DIR__ . '/.route-cache/fused.php',
);
```

The cache location is one PHP file. Fused mode is convenient when the deployment
system prefers a single immutable routing artifact.

## Generated matcher

```php
use Infocyph\Webrick\Router\Matching\GeneratedMatcher;

$kernel = RouterKernel::bootWithRegistrar(
    log: new NullLogger(),
    matcher: GeneratedMatcher::make(),
    register: $register,
    routeCache: __DIR__ . '/.route-cache/generated.php',
);
```

Generated mode emits a PHP file containing generated bucketed matching code.
Without `routeCache:` it compiles an in-memory matcher during finalization.
Cache generation is explicit; normal request boot does not write it.

## Cached route materialization

For all modes, production-friendly route definitions use scalar cache metadata:

```php
Route::get('/users/{id:int}', [UserController::class, 'show'], 'users.show');
```

Class strings, class-method handlers, and string middleware are exported as
ordinary PHP arrays. The selected route is validated and materialized once, and
persistent workers memoize it. This avoids serializing and deserializing every
route during request boot.

Closure handlers, object-backed handlers, and object middleware remain
supported through the serializer fallback. Prefer class-based route definitions
when cache size, boot time, and predictable deployment artifacts matter.

## Standalone matcher use

Outside `RouterKernel`, configure cache reading explicitly:

```php
$matcher = FusedMatcher::make()
    ->enableCache(__DIR__ . '/.route-cache/fused.php');

if ($matcher->canBootFromCache()) {
    $matcher->finalize();
}
```

`enableCache()` reads an artifact; it does not authorize cache generation.
Application code should normally use `RouteCache::build()` or the CLI to write
artifacts.

## Cache verification

Concrete matchers inherit `verifyCacheOnLoad()`:

```php
$matcher = FusedMatcher::make()
    ->verifyCacheOnLoad()
    ->enableCache($cacheFile);
```

Verification spends extra work while loading the cache. Enable it when the
deployment integrity model benefits from an additional content check. Route
caches are executable, trusted deployment artifacts regardless of this option.

## Recommendation

- Start with `ShardedMatcher`.
- Choose `FusedMatcher` when one-file artifact handling is operationally better.
- Evaluate `GeneratedMatcher` against representative routes and production-like
  runtime settings.
- Build all cache modes outside the request path and rebuild after upgrades or
  route-definition changes.

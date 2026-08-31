# Matcher

Webrick provides three matching strategies. All three can run in memory and all
three can boot from a deploy-time cache artifact. They share the same canonical
route/index semantics; the difference is how that canonical state is executed
and stored for production.

Matcher factories take no arguments:

```php
$matcher = FusedMatcher::make();
```

When using `RouterKernel`, pass the cache location as `routeCache:`. The kernel
enables cache reads on the matcher. Cache writes remain an explicit
`RouteCache::build()` or `route:cache` operation.

## Comparison

| Matcher | Artifact | Request-time shape | Good fit |
| --- | --- | --- | --- |
| `FusedMatcher` | One PHP file | Uses one consolidated canonical routing structure | Default baseline for normal applications |
| `GeneratedMatcher` | One PHP file with generated matcher code | Executes bucketed generated PHP matching code | Maximum-throughput candidates validated by representative benchmarks |
| `ShardedMatcher` | Directory of PHP files | Loads the relevant host/path shard into the worker | Very large route sets where reduced working set or memory is demonstrably valuable |

Do not choose only from a synthetic microbenchmark. Benchmark your route count,
static/dynamic mix, host routing, OPcache settings, filesystem, process model,
worker lifetime and traffic distribution.

The intended selection model is:

- **Fused** is the normal starting point and canonical performance baseline.
- **Generated** is a throughput specialization. Select it when production-like
  measurements show a meaningful dispatch advantage after accounting for
  generated-code size, OPcache usage and worker boot cost.
- **Sharded** is a scale/working-set specialization. Select it when a very large
  route table benefits measurably from loading only relevant route groups after
  accounting for shard management and first-use loading costs.

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

The cache location is one PHP file. Fused mode keeps one consolidated canonical
routing structure and executes it through the canonical matcher engine. It has
the smallest operational surface of the three modes and is the recommended
starting point for normal applications.

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

Generated mode can reduce generalized matcher-engine work by specializing host,
path, segment and method dispatch into executable PHP. That specialization also
increases generated code and OPcache footprint, so treat it as a measured
throughput option rather than an automatic upgrade from Fused.

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
plus route shards partitioned by host/path grouping. This can reduce the routing
working set for very large route collections. Cached workers may load a shard on
first use and retain it afterward, so compare both cold and warm behavior and do
not assume sharding is faster merely because the route table is non-trivial.

## Cached route materialization

For all modes, production-friendly route definitions use scalar cache metadata:

```php
Route::get('/users/{id:int}', [UserController::class, 'show'], 'users.show');
```

Class strings, class-method handlers and string middleware are exported as
ordinary PHP arrays. The selected route is validated and materialized once and
persistent workers memoize it. This avoids serializing and deserializing every
route during request boot.

Closure handlers, object-backed handlers and object middleware remain
supported through the serializer fallback. Prefer class-based route definitions
when cache size, boot time and predictable deployment artifacts matter.

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

- Start with `FusedMatcher` for normal applications and use it as the canonical
  comparison baseline.
- Move to `GeneratedMatcher` only when representative production-like benchmarks
  show a meaningful throughput advantage after including boot and OPcache costs.
- Move to `ShardedMatcher` for very large route sets only when measurements show
  a useful working-set, memory or locality advantage that outweighs shard
  loading and artifact-management costs.
- Measure cold and warm behavior separately, especially for FPM versus
  persistent-worker deployments.
- Build every cache mode outside the request path and rebuild after upgrades or
  route-definition changes.

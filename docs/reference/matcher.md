# Matcher

Webrick provides three matching strategies. All three can run in memory and all
three can boot from a deploy-time cache artifact. Fused and Sharded share the
same compiled matcher IR and request-time route-discrimination engine; Sharded
changes the physical storage/working-set boundary. Generated remains a separate
generated-code strategy.

Matcher factories take no arguments:

```php
$matcher = FusedMatcher::make();
```

When using `RouterKernel`, pass the cache location as `routeCache:`. The kernel
enables cache reads on the matcher. Cache writes remain an explicit
`RouteCache::build()` or `route:cache` operation.

## Comparison

| Matcher | Artifact | Request-time shape | Measured role |
| --- | --- | --- | --- |
| `FusedMatcher` | One PHP file | Direct static maps + shared compiled combined-PCRE matcher IR | **Default/general production matcher** |
| `GeneratedMatcher` | One PHP file with generated matcher code | Executes generated PHP matching code | **Niche generated-code mode; not the general throughput recommendation** |
| `ShardedMatcher` | Directory of PHP files | Lazily loads relevant shards that use the same compiled matcher IR as Fused | **Very-large-route / cold-boot / working-set specialization** |

The Webrick 5 matcher revision benchmarks changed the recommended roles. On the
1,000-route FastRoute-reference corpus, Fused and Sharded stayed close to each
other while Generated became substantially slower on dynamic, 404 and 405 paths.
At 5,000 routes, Sharded demonstrated a very large cold-boot advantage and a
smaller startup working set, while Fused retained materially faster warm
request dispatch. Generated also degraded sharply as route counts increased.

Do not choose only from one synthetic microbenchmark. Benchmark your route count,
static/dynamic mix, host routing, OPcache settings, filesystem, process model,
worker lifetime and traffic distribution.

The measured selection model is:

- **Fused** is the normal starting point, canonical matcher implementation and
  default production choice. It offers the best general balance of warm dispatch,
  deployment simplicity and predictable one-artifact behavior.
- **Generated** is no longer the default "maximum throughput" alternative.
  Keep it only when a specific small/known route corpus demonstrates a repeatable
  advantage or when the generated-code mode is otherwise operationally useful.
  Do not assume that advantage survives route-set growth.
- **Sharded** is a scale/startup/working-set specialization. Select it when very
  large route sets make lazy shard loading, extremely cheap cold boot or reduced
  startup memory more important than Fused's faster warm dispatch.

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

The cache location is one PHP file. Fused mode stores the compiled matcher IR
required for direct static lookup and combined-PCRE dynamic dispatch. It has the
smallest operational surface of the production-oriented matcher modes and is the
recommended starting point for normal applications.

The matcher hot path is compile-first: regex-compilable dynamic routes are merged
into bounded `(*MARK:...)` PCRE chunks; callable or unsafe-to-compose constraints
remain in ordered fallback steps so route precedence and segment semantics are
preserved.

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

Generated can still be fast for small route sets, especially simple static
lookups, but the Webrick 5 scale benchmarks show that its generated-condition
strategy degrades significantly as dynamic route counts grow. It should therefore
be treated as a niche measured mode rather than a general performance upgrade
from Fused.

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
plus route shards partitioned by host/path grouping. Every loaded shard uses the
same compiled matcher IR and combined-PCRE executor as Fused; sharding changes
storage and working-set behavior, not routing semantics.

On the 5,000-route matcher profile, Sharded booted in tens of microseconds instead
of loading the whole Fused artifact at startup, but paid a first-use shard load
and slower warm dispatch. This makes it appropriate when cold boot/startup memory
or very large routing tables dominate, not as a blanket request-throughput
optimization.

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

- Start with `FusedMatcher` for normal applications. It is the measured general
  production choice and canonical comparison baseline.
- Use `ShardedMatcher` for very large route sets when measurements show that its
  dramatically cheaper cold boot/lazy working set is worth the first-shard-load
  and slower warm-dispatch costs.
- Use `GeneratedMatcher` only when the application's actual route corpus proves a
  repeatable benefit. Do not select it merely because generated code sounds
  faster; the Webrick 5 scale benchmark showed poor dynamic/miss scaling.
- Measure cold and warm behavior separately, especially for FPM versus
  persistent-worker deployments.
- Build every cache mode outside the request path and rebuild after upgrades or
  route-definition changes.

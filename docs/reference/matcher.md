# Matcher

Webrick provides three selectable matching strategies. Fused and Sharded share
the same compact production matcher IR and request-time executor. Sharded changes
the physical storage and loaded working-set boundary. Generated remains a
separate generated-code strategy for route topologies that specifically benefit
from it.

Matcher factories take no arguments:

```php
$matcher = FusedMatcher::make();
```

Cache writes are explicit build/deploy operations. Normal request handling reads
an already-built route artifact and never generates matcher state on demand.

## Comparison

| Matcher | Artifact | Request-time shape | Webrick 5 role |
| --- | --- | --- | --- |
| `FusedMatcher` | One PHP file | Scalar static IDs + adaptive positional-PCRE IR + central route table | **Default/general production matcher** |
| `GeneratedMatcher` | One PHP file with generated matcher code | Specialized generated PHP branches | **Conditional sparse/distinct/static-heavy mode** |
| `ShardedMatcher` | Directory of PHP files | Relevant shards using the same compact IR as Fused | **Very-large-route / cold-boot / working-set specialization** |

The strategies intentionally optimize different deployment/topology problems.
There is no claim that one strategy wins every corpus.

## Fused matcher

Fused is the normal starting point and the canonical production matcher.

```php
use Infocyph\Webrick\Router\Matching\FusedMatcher;

$matcher = FusedMatcher::make();
```

Its compiled group is intentionally compact:

- one central `routeId => route payload` table;
- static routes represented by `method => path => routeId` maps;
- one positional dynamic-PCRE representation shared by compact and rich matching;
- ordered fallback islands only for callable or unsafe-to-compose constraints;
- compiled static and safe dynamic method metadata for 405/automatic OPTIONS;
- build-time adaptive literal discriminators for dense route families when the
  compiler can prove that the discriminator preserves precedence;
- a wildcard-only single-group request path when the application has no
  domain-specific routing.

The production fast executor returns scalar route IDs and parameter maps. Rich
`MatchOutcome` callers reuse that same discrimination result and resolve the
winning payload only after a match; Webrick does not maintain a second routing
algorithm merely to produce rich results.

### Dynamic route compilation

Ordinary safe regex routes are combined into bounded `(*MARK:...)` PCRE steps.
The compiler may instead build a literal-segment discriminator when a dense
family contains a segment that sharply and safely partitions candidates. This is
why structured route sets can avoid scanning a large combined-regex family.

Callable constraints and arbitrary regexes keep their historical segment-local
semantics inside narrow ordered fallback steps. One exotic route therefore does
not force the whole ordinary bucket onto the fallback path.

The internal PCRE chunk target is an implementation detail, not a user tuning
knob. Adaptive families can bypass the generic chunk walk entirely, so a raw
chunk-size microbenchmark is not sufficient evidence for application-level
selection.

## Generated matcher

```php
use Infocyph\Webrick\Router\Matching\GeneratedMatcher;

$matcher = GeneratedMatcher::make();
```

Generated emits specialized PHP matching code. It has a real measured niche:
static routes, isolated/distinct-prefix dynamic routes, and several small
feature-specific paths can execute faster than the generic compiled executor.

It is **not** the general throughput recommendation. Dense shared-prefix dynamic
families scale poorly because generated conditions are evaluated as a growing
branch sequence. Webrick 5 measurements showed this topology becoming many
multiples slower than Fused while Generated remained competitive or faster on
sparse/distinct/static-heavy shapes.

Use Generated only when the application's representative route corpus proves a
repeatable advantage. Do not select it merely because generated code sounds
faster, and re-run the benchmark after material route-set growth.

## Sharded matcher

```php
use Infocyph\Webrick\Router\Matching\ShardedMatcher;

$matcher = ShardedMatcher::make();
```

The cache location is a directory. Routes are partitioned by host/path grouping,
and each loaded shard uses the same compact central-route-table IR and executor
as Fused. Sharding therefore changes storage/startup behavior rather than route
semantics.

Sharded is useful when a very large route table makes startup/working-set cost
more important than maximum warm dispatch. Measurements on the Webrick 5
5,000-route profile consistently showed:

- dramatically cheaper initial cache boot than loading the full Fused artifact;
- a smaller total artifact;
- a first-use shard loading cost;
- slower warm matching than Fused.

Persistent workers amortize first-shard loading. Short-lived process models may
need a representative cold/warm deployment benchmark before choosing Sharded.

## Routing semantics shared by the strategies

Matcher selection does not change required routing behavior. The semantic gates
cover, among other cases:

- static and dynamic routes;
- registration precedence;
- multiple parameters;
- callable constraints and safe built-in regex constraints;
- HEAD-to-GET fallback;
- explicit and automatic OPTIONS;
- complete 405 `Allow` discovery, including overlapping dynamic shapes;
- exact-host precedence and wildcard-host fallback;
- extension HTTP methods;
- not-found behavior;
- compact and rich result paths.

## Cached route materialization

Production-friendly definitions use scalar cache metadata:

```php
Route::get('/users/{id:int}', [UserController::class, 'show'], 'users.show');
```

Class strings, class-method handlers and string middleware are exported as
ordinary PHP arrays. Fused and Sharded store each route payload once in the
central route table. The winning route is materialized only when a rich caller or
dispatch layer needs the `CompiledRoute`, and persistent workers memoize the
materialized result.

Closure handlers, object-backed handlers and object middleware remain supported
through the serializer fallback. Prefer class-based route definitions when cache
size, boot time and predictable deployment artifacts matter.

## Standalone matcher use

Configure cache reading explicitly:

```php
$matcher = FusedMatcher::make()
    ->enableCache(__DIR__ . '/.route-cache/fused.php');

if ($matcher->canBootFromCache()) {
    $matcher->finalize();
}
```

`enableCache()` reads an artifact; it does not authorize cache generation.
Application code should normally build artifacts through the route-cache build
flow or CLI outside the request path.

## Cache verification

Concrete matchers support explicit cache verification:

```php
$matcher = FusedMatcher::make()
    ->verifyCacheOnLoad()
    ->enableCache($cacheFile);
```

Verification spends extra work while loading the cache. Route caches remain
trusted executable deployment artifacts regardless of this option.

## Recommendation

- Start with **`FusedMatcher`**. It is Webrick 5's default, canonical comparison
  baseline and best general balance of warm dispatch and deployment simplicity.
- Use **`ShardedMatcher`** when a representative large-route deployment proves
  that its very cheap cold boot and smaller/lazy working set are worth slower
  first-use/warm behavior.
- Use **`GeneratedMatcher`** only for a measured sparse/distinct/static-heavy
  corpus. Avoid treating it as a blanket performance mode for dense shared-prefix
  dynamic routing.
- Benchmark cold and warm behavior separately, especially when comparing FPM and
  persistent workers.
- Rebuild route-cache artifacts after Webrick upgrades or route-definition
  changes; matcher artifact schemas are versioned and stale artifacts fail
  closed.

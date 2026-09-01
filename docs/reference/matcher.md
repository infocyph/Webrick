# Matcher

Webrick provides three selectable matching strategies. Fused and Sharded share
the same compact production matcher IR and request-time executor. Sharded changes
the physical storage and loaded working-set boundary. Generated remains a
separate generated-code strategy for small route topologies that specifically
benefit from it.

Matcher factories take no arguments:

```php
$matcher = FusedMatcher::make();
```

Cache writes are explicit build/deploy operations. Normal request handling reads
an already-built route artifact and never generates matcher state on demand.

## Comparison

| Matcher | Artifact | Request-time shape | Webrick 5 role | Approximate route-count guidance |
| --- | --- | --- | --- | --- |
| `FusedMatcher` | One PHP file | Scalar static IDs + adaptive positional-PCRE IR + central route table | **Default/general production matcher** | **Any size; default from tens through 10,000+ routes.** Especially preferred once a route set becomes mixed/dynamic or reaches roughly 1,000+ routes. |
| `GeneratedMatcher` | One PHP file with generated matcher code | Specialized generated PHP branches | **Conditional small/sparse/distinct mode** | **Best candidate below ~100 routes; sometimes useful up to ~1,000 when routes are mostly static or strongly distinct.** Avoid assuming it scales beyond that. |
| `ShardedMatcher` | Directory of PHP files | Relevant shards using the same compact IR as Fused | **Very-large-route / cold-boot / working-set specialization** | **Usually worth evaluating around ~5,000+ routes; increasingly relevant around 10,000+** when cold boot or loaded working set matters more than maximum warm dispatch. |

These counts are **rules of thumb, not thresholds**. Route topology can matter more
than route count. A 300-route dense shared-prefix dynamic application can favor
Fused more strongly than a 1,000-route mostly-static application, while a
5,000-route application with long-lived workers may still prefer Fused because
warm throughput matters more than startup cost.

### Route-count quick guide

| Approximate route count | `FusedMatcher` | `GeneratedMatcher` | `ShardedMatcher` | Practical starting point |
| ---: | --- | --- | --- | --- |
| **< 100** | **Excellent/default.** Very small fixed overhead and no topology assumptions. | **Strong candidate** when routes are mostly static, isolated or strongly distinct; benchmark it because it can beat Fused here. | Usually unnecessary. | Start Fused; benchmark Generated if the corpus suits generated branches. |
| **100–1,000** | **Default/preferred**, especially for mixed or dense dynamic routing. | **Conditional.** Can still win static/distinct layouts, but shared-prefix dynamic families can already become much slower than Fused. | Usually unnecessary unless startup/working-set pressure is unusual. | Use Fused unless Generated proves a repeatable application-specific win. |
| **1,000–5,000** | **Strong default.** Structured dynamic/miss performance remains stable. | **Exceptional / benchmark-only.** Do not infer a win from small-route results. | Evaluate only when route-cache boot or loaded working set is already material. | Fused for normal deployments. |
| **5,000–10,000** | **Preferred for warm throughput.** | **Not a general choice.** Large generated functions and dense dynamic families show severe scaling costs. | **Strong candidate** when cold boot, per-worker loaded state or deployment startup matters. | Benchmark Fused vs Sharded according to warm-speed versus startup/memory priorities. |
| **10,000+** | **Still valid and fast for warm dispatch.** Route count alone is not a reason to leave Fused. | **Avoid as a general large-route mode.** Use only with extraordinary application-specific evidence. | **Strong candidate** for lazy loading and startup/working-set reduction. | Benchmark Fused vs Sharded; choose by deployment/runtime tradeoff. |

The strategies intentionally optimize different deployment/topology problems.
There is no claim that one strategy wins every corpus.

## Fused matcher

Fused is the normal starting point and the canonical production matcher. Route
count by itself is not a reason to leave Fused: the Webrick 5 certification kept
its dynamic/miss dispatch roughly flat even at 10,000 routes in the structured
reference corpus.

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

Generated emits specialized PHP matching code. It has a real measured niche on
**small** applications: static routes, isolated/distinct-prefix dynamic routes,
and several feature-specific paths can execute materially faster than the
compact executor.

As a practical starting point, Generated is most interesting below roughly
**100 routes**. It can remain competitive up to roughly **1,000 routes** when the
corpus is predominantly static or strongly partitioned by literal prefixes, but
that upper range is topology-dependent and must be benchmarked. A dense dynamic
corpus can favor Fused much earlier.

It is **not** the general throughput recommendation and it is not a large-route
strategy. Two separate scaling effects were observed during Webrick 5
certification:

- dense shared-prefix dynamic families degrade rapidly because generated
  conditions become a growing branch sequence;
- once the generated function itself becomes very large, even exact static
  dispatch can degrade sharply. In the 5,000- and 10,000-route certification
  corpora Generated static dispatch was tens of microseconds while Fused stayed
  around a few hundred nanoseconds.

The same certification still showed Generated winning the small 49-route native
Webrick corpus and 100/1,000-route static cases, plus isolated/distinct feature
paths in the 1,000-route capability corpus. That is why it remains selectable,
but only as a measured small-route specialization.

Use Generated only when the application's representative route corpus proves a
repeatable advantage. Re-run that benchmark after material route-set growth; a
Generated result from a small application should not be assumed to scale.

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
more important than maximum warm dispatch. As a practical rule of thumb, begin
measuring Sharded around **5,000 routes** if route-cache boot or per-worker loaded
state is becoming material. At **10,000+ routes**, it is increasingly reasonable
to benchmark Fused and Sharded side by side rather than assuming the one-file
artifact is the best deployment shape.

Measurements on the Webrick 5 5,000-route profile consistently showed:

- dramatically cheaper initial cache boot than loading the full Fused artifact;
- a smaller total artifact;
- a first-use shard loading cost;
- slower warm matching than Fused.

Persistent workers amortize first-shard loading. Short-lived process models may
need a representative cold/warm deployment benchmark before choosing Sharded.
A large route count alone does not make Sharded faster; it makes Sharded's
startup and working-set tradeoff more likely to be useful.

## Routing semantics shared by the strategies

Matcher selection does not change required routing behavior. The semantic gates
cover, among other cases:

- static and dynamic routes;
- registration precedence;
- multiple and named route parameters;
- callable constraints and safe built-in regex constraints;
- HEAD-to-GET fallback;
- explicit and automatic OPTIONS;
- complete 405 `Allow` discovery, including overlapping dynamic shapes;
- exact-host precedence and wildcard-host fallback;
- extension HTTP methods;
- not-found behavior;
- compact and rich result paths.

### Matcher optimization does not change handler binding

The Webrick 5 matcher-performance stages optimize **how a route is found**, not
how the selected route is executed. A successful matcher still returns the
named route-variable map used by the existing dispatch/runtime layer.

This means matcher choice does not remove or replace:

- named route parameters and the request `route_params` attribute;
- compiled direct-handler argument ordering through `ExecutionPlan::routeArguments`;
- InterMix `resolveNow()` invocation with named route parameters;
- named `request` injection when a compiled InterMix invocation requires it;
- middleware execution and middleware access to route parameters;
- handler/controller/container resolution, route aliases/names or URL-generation
  metadata;
- route CORS/Produces metadata and other selected-route execution-plan behavior.

These concerns remain above the matcher boundary. Fused/Sharded compact scalar
IDs and Generated's generated branches only change route discrimination; after a
match, Webrick feeds the same route identity and named variables into the normal
execution plan.

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

- Start with **`FusedMatcher` at any route count**. It is Webrick 5's default,
  canonical comparison baseline and best general balance of warm dispatch and
  deployment simplicity.
- For **small applications, especially below ~100 routes**, benchmark
  **`GeneratedMatcher`** when routes are mostly static, isolated or strongly
  distinct. It may remain useful into the hundreds and occasionally around
  1,000 routes, but only when the real topology proves it.
- Around **~5,000 routes and above**, start evaluating **`ShardedMatcher`** when
  cold boot or loaded working set is becoming a material constraint. Around
  **10,000+ routes**, a Fused-vs-Sharded deployment benchmark is recommended.
- Do not use route count as the only selector: dense dynamic sharing, host layout,
  cache warmth, OPcache, filesystem, process model and worker lifetime can move
  the crossover substantially.
- Rebuild route-cache artifacts after Webrick upgrades or route-definition
  changes; matcher artifact schemas are versioned and stale artifacts fail
  closed.

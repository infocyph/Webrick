Matcher
=======

Webrick provides three selectable matching strategies. Fused and Sharded share the same compact production matcher IR and request-time executor. Sharded changes the physical storage and loaded working-set boundary. Generated remains a separate generated-code strategy for route topologies whose generated control flow stays compact and benchmark-proven.

Matcher factories take no arguments:

.. code:: php

   $matcher = FusedMatcher::make();

Cache writes are explicit build/deploy operations. Normal request handling reads an already-built route artifact and never generates matcher state on demand.

Comparison
----------

.. list-table:: Matcher roles
   :header-rows: 1
   :widths: 16 20 25 39

   * - Matcher
     - Artifact
     - Webrick 5 role
     - Approximate measured guidance
   * - ``FusedMatcher``
     - One PHP file
     - **Default/general production matcher**
     - Valid at any measured size and generally the safer warm-latency choice from roughly **2,250 routes onward**; remains flat through 10,000+ in the structured scale corpus.
   * - ``GeneratedMatcher``
     - One PHP file with generated matcher code
     - **Small-to-medium/simple-topology specialization**
     - Strong measured candidate through roughly **1,500 routes** in the current synthetic envelope; near parity around **1,750–2,000**. Benchmark explicitly beyond that and do not treat it as a general 5,000+ mode.
   * - ``ShardedMatcher``
     - Directory of immutable PHP shards
     - **Cold-boot / bounded-working-set specialization**
     - Evaluate when several-thousand-route cache boot or loaded state becomes material; warm dispatch is now much closer to Fused after candidate-group memoization.

These counts are **benchmarking heuristics, not thresholds**. Route topology can matter more than route count, and Generated's measured results are intentionally non-monotonic at some larger sizes. Webrick therefore does not auto-select a matcher from route count.

Route-count quick guide
~~~~~~~~~~~~~~~~~~~~~~~

.. list-table:: Route-count quick guide
   :header-rows: 1
   :widths: 14 23 30 20 30

   * - Routes
     - ``FusedMatcher``
     - ``GeneratedMatcher``
     - ``ShardedMatcher``
     - Practical starting point
   * - **< 1,000**
     - Excellent safe default.
     - Strong candidate for simple/static/distinct route sets; often worth benchmarking first for minimum warm latency.
     - Usually unnecessary.
     - Start Fused when topology is unknown; benchmark Generated early when matcher latency is important.
   * - **1,000–1,500**
     - Strong and predictable.
     - Current synthetic envelope materially favored Generated throughout this band.
     - Usually unnecessary unless startup pressure is unusual.
     - Benchmark Fused and Generated on the real route set.
   * - **1,500–2,250**
     - Strong and predictable.
     - Crossover zone: near parity was measured around 1,750–2,000.
     - Consider only for a specific residency/startup need.
     - Benchmark Fused and Generated; do not infer the winner from count alone.
   * - **2,250–5,000**
     - Generally preferred for warm latency and artifact efficiency.
     - Benchmark-only exception; isolated topology wins can occur, but the general trend favors Fused.
     - Becomes interesting when cold boot or working-set cost is visible.
     - Fused for normal deployments; compare Sharded when residency matters.
   * - **5,000–10,000**
     - Preferred for fully resident warm throughput.
     - Not a general choice; the current 5,000-route envelope shows a severe generated-code cliff.
     - Strong candidate when cold boot or per-worker loaded state matters.
     - Benchmark Fused versus Sharded according to runtime priorities.
   * - **10,000+**
     - Still valid and fast for warm dispatch.
     - Avoid as a general large-route mode without extraordinary application-specific evidence.
     - Strong candidate for lazy loading and startup/working-set reduction.
     - Benchmark Fused versus Sharded; choose by deployment tradeoff.

The strategies intentionally optimize different deployment/topology problems. There is no claim that one strategy wins every corpus.

Fused matcher
-------------

Fused is the normal starting point and the canonical production matcher. Route count by itself is not a reason to leave Fused: the Webrick 5 certification kept its dynamic/miss dispatch roughly flat even at 10,000 routes in the structured reference corpus.

.. code:: php

   use Infocyph\Webrick\Router\Matching\FusedMatcher;

   $matcher = FusedMatcher::make();

Its compiled group is intentionally compact:

- one central ``routeId => route payload`` table;
- static routes represented by ``method => path => routeId`` maps;
- one positional dynamic-PCRE representation shared by compact and rich matching;
- ordered fallback islands only for callable or unsafe-to-compose constraints;
- compiled static and safe dynamic method metadata for 405/automatic OPTIONS;
- build-time adaptive literal discriminators for dense route families when the compiler can prove that the discriminator preserves precedence;
- a wildcard-only single-group request path when the application has no domain-specific routing.

The production fast executor returns scalar route IDs and parameter maps. Rich ``MatchOutcome`` callers reuse that same discrimination result and resolve the winning payload only after a match; Webrick does not maintain a second routing algorithm merely to produce rich results.

Dynamic route compilation
~~~~~~~~~~~~~~~~~~~~~~~~~

Ordinary safe regex routes are combined into bounded ``(*MARK:...)`` PCRE steps. The compiler may instead build a literal-segment discriminator when a dense family contains a segment that sharply and safely partitions candidates. This is why structured route sets can avoid scanning a large combined-regex family.

Callable constraints and arbitrary regexes keep their historical segment-local semantics inside narrow ordered fallback steps. One exotic route therefore does not force the whole ordinary bucket onto the fallback path.

The internal PCRE chunk target is an implementation detail, not a user tuning knob. Adaptive families can bypass the generic chunk walk entirely, so a raw chunk-size microbenchmark is not sufficient evidence for application-level selection.

Generated matcher
-----------------

.. code:: php

   use Infocyph\Webrick\Router\Matching\GeneratedMatcher;

   $matcher = GeneratedMatcher::make();

Generated emits specialized PHP matching code. The completed Webrick 5 crossover study shows that its useful envelope is wider than the earlier documentation suggested: it is a real **small-to-medium/simple-topology specialization**, not merely a tiny-route mode.

In the current PHP 8.4.25 synthetic cache envelope with OPcache disabled, Generated materially beat Fused through roughly **1,500 routes** and reached near parity around **1,750–2,000 routes**. Representative medians were:

- 1,000 routes: **0.772 µs Generated** versus **1.606 µs Fused**;
- 1,500 routes: **0.979 µs** versus **1.738 µs**;
- 1,750 routes: **1.507 µs** versus **1.567 µs**;
- 2,000 routes: **1.575 µs** versus **1.623 µs**;
- 2,250 routes: **1.776 µs** versus **1.626 µs**, where Fused became the generally safer choice.

These numbers are not a route-count switch. Generated showed isolated near-wins again around 3,500 and 4,500 routes, which demonstrates that branch/code shape and route topology can move the crossover. Webrick therefore keeps Generated explicit rather than auto-selecting it.

Generated also pays more for build, cache artifact, boot and resident code. At **5,000 routes** the measured generated representation crossed a severe execution/code-size cliff: median warm dispatch was about **69.001 µs** versus **1.745 µs** for Fused, and the cache artifact was about **26.04 MB** versus **9.76 MB**. It is therefore not a general large-route strategy.

Use Generated when representative application measurements prove that its generated branches win. Re-run that measurement after material route-set or topology changes, and include cache build/boot/artifact cost when choosing it for short-lived workers.

Sharded matcher
---------------

.. code:: php

   use Infocyph\Webrick\Router\Matching\ShardedMatcher;

   $matcher = ShardedMatcher::make();

The cache location is a directory. Routes are partitioned by host/path grouping, and each loaded shard uses the same compact central-route-table IR and executor as Fused. Sharding therefore changes storage/startup behavior rather than route semantics.

Sharded is useful when route-cache boot or loaded working set matters more than keeping every compiled group resident. Begin measuring it once several-thousand-route applications make those costs visible; around 5,000+ routes it becomes a natural Fused comparison when workers do not need the whole route table immediately.

The final Webrick 5 residency pass removed an avoidable cached hot-path cost by memoizing resolved candidate groups per host/prefix. Same-run warm latency improved from **5.277 → 2.082 µs** at 1,000 routes, **5.348 → 2.237 µs** at 5,000, and **5.633 → 2.405 µs** at 10,000, while first-shard latency remained effectively neutral. An isolated 10,000-route cached profile measured roughly **57 µs cold boot**, **986 µs first shard hit**, and **2.49 µs warm dispatch**.

Fused remains faster once its complete IR is resident, while Sharded can boot with almost no matcher state loaded and materialize only the route groups traffic touches. Persistent workers amortize first-shard loading; short-lived processes should compare the complete cold/first/warm envelope before choosing a mode.

A large route count alone does not make Sharded faster. It makes Sharded's startup and working-set tradeoff more likely to be useful.

Routing semantics shared by the strategies
------------------------------------------

Matcher selection does not change required routing behavior. The semantic gates cover, among other cases:

- static and dynamic routes;
- registration precedence;
- multiple and named route parameters;
- callable constraints and safe built-in regex constraints;
- HEAD-to-GET fallback;
- explicit and automatic OPTIONS;
- complete 405 ``Allow`` discovery, including overlapping dynamic shapes;
- exact-host precedence and wildcard-host fallback;
- extension HTTP methods;
- not-found behavior;
- compact and rich result paths.

Matcher optimization does not change handler binding
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

The Webrick 5 matcher-performance stages optimize **how a route is found**, not how the selected route is executed. A successful matcher still returns the named route-variable map used by the existing dispatch/runtime layer.

This means matcher choice does not remove or replace:

- named route parameters and the request ``route_params`` attribute;
- compiled direct-handler argument ordering through ``ExecutionPlan::routeArguments``;
- InterMix ``resolveNow()`` invocation with named route parameters;
- named ``request`` injection when a compiled InterMix invocation requires it;
- middleware execution and middleware access to route parameters;
- handler/controller/container resolution, route aliases/names or URL-generation metadata;
- route CORS/Produces metadata and other selected-route execution-plan behavior.

These concerns remain above the matcher boundary. Fused/Sharded compact scalar IDs and Generated's generated branches only change route discrimination; after a match, Webrick feeds the same route identity and named variables into the normal execution plan.

Cached route materialization
----------------------------

Production-friendly definitions use scalar cache metadata:

.. code:: php

   Route::get('/users/{id:int}', [UserController::class, 'show'], 'users.show');

Class strings, class-method handlers and string middleware are exported as ordinary PHP arrays. Fused and Sharded store each route payload once in the central route table. The winning route is materialized only when a rich caller or dispatch layer needs the ``CompiledRoute``, and persistent workers memoize the materialized result.

Closure handlers, object-backed handlers and object middleware remain supported through the serializer fallback. Prefer class-based route definitions when cache size, boot time and predictable deployment artifacts matter.

Standalone matcher use
----------------------

Configure cache reading explicitly:

.. code:: php

   $matcher = FusedMatcher::make()
       ->enableCache(__DIR__ . '/.route-cache/fused.php');

   if ($matcher->canBootFromCache()) {
       $matcher->finalize();
   }

``enableCache()`` reads an artifact; it does not authorize cache generation. Application code should normally build artifacts through the route-cache build flow or CLI outside the request path.

Cache verification
------------------

Concrete matchers support explicit cache verification:

.. code:: php

   $matcher = FusedMatcher::make()
       ->verifyCacheOnLoad()
       ->enableCache($cacheFile);

Verification spends extra work while loading the cache. Route caches remain trusted executable deployment artifacts regardless of this option.

Recommendation
--------------

- Start with **``FusedMatcher`` at any route count** when the application's topology has not yet been measured. It remains Webrick 5's canonical general-production baseline.
- For simple/static/distinct applications up to roughly **1,500 routes**, benchmark **``GeneratedMatcher`` seriously**; the current synthetic envelope materially favors it in that range. Treat roughly **1,500–2,250** as a crossover zone and measure both.
- From roughly **2,250 routes onward**, Fused is the generally safer warm-latency/artifact choice, while Generated remains available only when representative application measurements prove an exception. Do not auto-select by route count.
- Around several thousand routes, especially **~5,000+**, evaluate **``ShardedMatcher``** when cold boot or loaded working set is a material constraint. Around **10,000+**, a Fused-versus-Sharded deployment benchmark is recommended.
- Rebuild route-cache artifacts after Webrick upgrades or route-definition changes; matcher artifact schemas are versioned and stale artifacts fail closed.

# Webrick 5 Matcher Optimization Completion

This ledger records the implementation decisions for the FastRoute-comparison
matcher optimization program. It is a matcher implementation/performance ledger,
not a replacement for the later full-project QA/static-analysis/release gate.

## Final architecture

Fused and Sharded now compile the canonical route graph into the same immutable
production IR:

- one central route-payload table keyed by deterministic scalar route ID;
- method-first static path maps containing route IDs only;
- positional branch-reset dynamic PCREs shared by compact and rich matching;
- adaptive literal-segment discrimination when the compiler can prove it safely
  partitions a dense route family;
- narrow ordered fallback islands for callable or unsafe-to-compose constraints;
- compiled static and safe dynamic method metadata for 405/automatic OPTIONS;
- one compact executor; rich `MatchOutcome` is an adapter that resolves the
  winning route payload after matching.

Sharded persists this same IR in independently loadable host/path shards.
Generated remains an independent generated-code strategy.

## Stage decisions

| Stage | Decision | Result |
| --- | --- | --- |
| 1. Scalar production IR / dedicated executor | **KEEP** | Removed rich route interpretation from compact dispatch and materially improved static/dynamic matching. |
| 2. Positional branch-reset PCRE captures | **KEEP** | Removed named-capture bookkeeping from the hot dynamic path and produced a large repeatable dynamic gain. |
| 3. Adaptive route classification | **CONDITIONAL** | Compiler emits literal-segment discrimination only for dense safe families where it reduces candidate work without changing precedence. |
| 4. Compiled miss/method metadata | **CONDITIONAL** | Static and provably unique dynamic families use precompiled method knowledge; ambiguous overlapping shapes preserve the accumulative semantic fallback. |
| 5. Host/topology specialization | **KEEP** | Wildcard-only Fused artifacts use a single-group path and avoid unnecessary host-map/wildcard resolution. |
| 6. Compact route tables / fallback islands | **KEEP** | Centralized route payloads and removed duplicate rich/fast regex tables while preserving the Stage 1–5 speed gains. |
| 7. Matcher portfolio | **KEEP Fused; CONDITIONAL Sharded; CONDITIONAL Generated** | Fused is default. Sharded is a startup/working-set specialization. Generated is only for measured small/sparse/distinct corpora and is unsuitable as a blanket large-route performance mode. |

## Representative same-run evidence

Benchmark numbers vary across hosted runners, so decisions use same-run relative
comparisons and repeated medians rather than comparing isolated absolute numbers
from different runs.

### Compact IR reference smoke — PHP 8.4, 1,000 routes

A representative post-compaction run measured:

| Scenario | FastRoute | Fused | Generated | Sharded |
| --- | ---: | ---: | ---: | ---: |
| Static late | 135.7 ns | 161.8 ns | 128.5 ns | 220.3 ns |
| Dynamic middle | 1,768 ns | **1,029 ns** | 10,609 ns | 1,084 ns |
| Dynamic late | 3,270 ns | **1,059 ns** | 18,519 ns | 1,116 ns |
| 404 | 2,901 ns | **1,419 ns** | 18,279 ns | 1,503 ns |
| 405 | 3,313 ns | **1,362 ns** | 18,272 ns | 1,432 ns |

This corpus demonstrates the general production decision: Fused is near the
FastRoute static target while substantially outperforming it on the structured
dynamic/miss cases that Webrick can classify at build time.

### Final scale certification — PHP 8.4 and 8.5

The final bounded certification completed successfully on both PHP 8.4.25 and
PHP 8.5.10 at 100 / 1,000 / 5,000 / 10,000 routes. The PHP 8.5 10,000-route
medians were representative of the final portfolio decision:

| Scenario | FastRoute | Fused | Generated | Sharded |
| --- | ---: | ---: | ---: | ---: |
| Static late | 258.3 ns | **278.5 ns** | 62,553.9 ns | 349.9 ns |
| Dynamic middle | 39,696.8 ns | **1,987.8 ns** | 322,446.4 ns | 2,056.6 ns |
| Dynamic late | 105,734.9 ns | **1,969.9 ns** | 533,685.6 ns | 2,054.2 ns |
| 404 | 52,985.1 ns | **2,562.0 ns** | 530,038.1 ns | 2,703.3 ns |
| 405 | 106,697.5 ns | **2,540.5 ns** | 534,411.0 ns | 2,646.1 ns |

Fused remained broadly flat as the structured corpus grew. Generated did not:
its dense dynamic path degraded early, and by 5,000–10,000 routes even its
static generated function had become large enough to cost tens of microseconds.
Sharded remained close to Fused for warm discrimination while retaining a
separate startup/working-set purpose.

### Generated topology evidence — PHP 8.4/8.5, 1,000-route capability corpus

Generated is not uniformly slow. It was faster than Fused on isolated or
sparse/distinct paths such as distinct-prefix matching, multi-parameter isolated
routes, callable fallback, HEAD, automatic OPTIONS, extension methods and
host-specific routes. However, dense shared-prefix dynamic families measured in
the tens of microseconds for Generated versus roughly two microseconds for Fused.

That is a real workload advantage and a real workload failure mode. Therefore
Generated remains **conditional**, never the default or an assumed upgrade over
Fused.

### Artifact/startup profile — PHP 8.4, 5,000 routes

After Stage 6 compaction a representative profile measured:

| Metric | Fused | Sharded |
| --- | ---: | ---: |
| Artifact size | 9,759,168 B | 8,589,168 B |
| Build | 256 ms | 156 ms |
| Cold cache boot | 42.9 ms | 52.7 us |
| First target hit | 22.2 us | 423 us |
| Warm target hit | 943 ns | 3,170 ns |

Before compaction, the same optimization line had reached roughly 14.55 MB for
Fused and 13.34 MB for Sharded at 5,000 routes. Centralizing route payloads and
sharing the positional representation reduced those artifacts by about one
third while retaining the fast request path.

This also establishes Sharded's distinct purpose: much cheaper cold boot and a
smaller/lazy loaded working set, traded for first-shard and warm-dispatch cost.

## Semantic gates retained during optimization

The matcher sanity gate covers the behavior most exposed by these changes:

- static/dynamic matching and registration precedence;
- built-in regex constraints with internal capture groups;
- callable constraint fallback;
- adaptive first/late/miss routes;
- HEAD-to-GET fallback including rich `headFallback` materialization;
- explicit OPTIONS and automatic OPTIONS;
- static, adaptive and overlapping-dynamic 405 `Allow` discovery;
- exact-host precedence and wildcard-host fallback;
- extension HTTP methods;
- scalar compact results and rich `MatchOutcome` route materialization.

## Matcher selection

The following route counts are approximate **evaluation bands**, not hard
thresholds. Topology, host layout, worker lifetime, cache warmth and startup
constraints can move the crossover substantially.

| Approximate route count | Primary recommendation | Alternative worth evaluating |
| ---: | --- | --- |
| **< 100** | Fused | Generated for mostly static/isolated/distinct routes. |
| **100–1,000** | Fused | Generated only when representative measurements prove its topology is favorable. |
| **1,000–5,000** | Fused | Usually no matcher change; measure Sharded only if startup/working-set pressure is already material. |
| **5,000–10,000** | Fused for warm throughput | Sharded when cold boot or loaded working set is a primary constraint. |
| **10,000+** | Benchmark Fused and Sharded | Choose by warm throughput versus startup/working-set tradeoff; Generated is not a general large-route option. |

### Fused — default

Use Fused unless a representative deployment benchmark demonstrates a reason not
to. It is the canonical Webrick 5 matcher and the best general balance of warm
throughput, semantics, artifact simplicity and maintainability. The final
10,000-route certification confirms that route count alone is not a reason to
leave Fused.

### Sharded — large-route/startup specialization

Begin considering Sharded around several thousand routes when cold boot or loaded
working set becomes visible. Around 5,000+ routes it becomes a reasonable
benchmark candidate; around 10,000+ routes a Fused-vs-Sharded deployment
comparison is recommended. Persistent workers are the strongest fit because
shard loading is amortized.

### Generated — measured small-topology specialization

Generated remains available because it has repeatable advantages on some small
static/sparse/distinct route shapes. As a practical rule it is most interesting
below roughly 100 routes, can remain useful into the hundreds and occasionally
around 1,000 routes, and must be re-benchmarked as the route set grows. Dense
shared-prefix dynamic families are a demonstrated scaling weakness, and large
generated functions eventually hurt even static dispatch.

## Rejected / deliberately not generalized

- No feature or route semantic was removed to improve the benchmark.
- No first-match-only shortcut was accepted for ambiguous 405 discovery.
- No arbitrary user regex was silently promoted into whole-path PCRE composition.
- No adaptive classifier is emitted unless its topology is provably safe.
- No speculative chunk-size change is treated as a release optimization after
  adaptive discrimination made the old raw chunk microbenchmark topology-specific.
- Generated is not promoted to the default merely because generated PHP can win
  isolated branches.

## Completion boundary

The matcher implementation/performance program is **complete**:

- Stages 1–7 are closed and classified;
- semantic matcher sanity gates passed during development;
- the final 100/1,000/5,000/10,000 comparative matrix completed on PHP 8.4 and 8.5;
- capability-specific Webrick matcher benchmarks remain available for local regression/performance work;
- the temporary FastRoute dev dependency, comparison benchmark, and matcher-only GitHub Actions workflows were removed after certification;
- public matcher guidance reflects the measured portfolio and approximate route-count evaluation bands.

Full project QA, static analysis, coding style, dependency architecture and
release certification remain the separate subsequent phase.

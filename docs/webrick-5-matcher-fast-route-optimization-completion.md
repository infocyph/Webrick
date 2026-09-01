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
| 7. Matcher portfolio | **KEEP Fused; CONDITIONAL Sharded; CONDITIONAL Generated** | Fused is default. Sharded is a startup/working-set specialization. Generated is only for measured sparse/distinct/static-heavy corpora and is unsuitable as a blanket dense-dynamic performance mode. |

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

### Generated topology evidence — PHP 8.4, 1,000-route capability corpus

Generated is not uniformly slow. It was faster than Fused on isolated or
sparse/distinct paths such as distinct-prefix matching, multi-parameter isolated
routes, callable fallback, HEAD, automatic OPTIONS, extension methods and
host-specific routes. However, the same run measured dense shared-prefix dynamic
families at approximately 31 microseconds for a middle route and 75 microseconds
for a late route versus roughly 2.1 microseconds for Fused.

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

### Fused — default

Use Fused unless a representative deployment benchmark demonstrates a reason not
to. It is the canonical Webrick 5 matcher and the best general balance of warm
throughput, semantics, artifact simplicity and maintainability.

### Sharded — large-route/startup specialization

Use Sharded when cold boot or loaded working set dominates and measurements show
that lazy shard loading outweighs its first-hit and warm-dispatch overhead.
Persistent workers are the strongest fit because shard loading is amortized.

### Generated — measured topology specialization

Generated remains available because it has repeatable advantages on some
static/sparse/distinct route shapes. Do not use it as the generic high-performance
mode. Dense shared-prefix dynamic families are a demonstrated scaling weakness.
Select it only after benchmarking the application's real route corpus and repeat
that benchmark after material route-set growth.

## Rejected / deliberately not generalized

- No feature or route semantic was removed to improve the benchmark.
- No first-match-only shortcut was accepted for ambiguous 405 discovery.
- No arbitrary user regex was silently promoted into whole-path PCRE composition.
- No adaptive classifier is emitted unless its topology is provably safe.
- No speculative chunk-size change is treated as a release optimization after
  adaptive discrimination made the old raw chunk microbenchmark topology-specific.
- Generated is not promoted to the default merely because generated PHP can win
  isolated branches.

## Remaining boundary

The matcher implementation/performance stages are complete once the final
100/1,000/5,000/10,000 PHP 8.4/8.5 comparative certification is recorded and the
benchmark workflow is restored to manual dispatch. Full project QA, static
analysis, coding-style, dependency architecture and release certification remain
a separate subsequent phase.

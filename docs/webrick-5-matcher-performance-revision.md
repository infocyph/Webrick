# Webrick 5 — Matcher Architecture and Performance Revision Plan

## Scope

This is the final matcher architecture/performance development phase before the deferred Webrick 5 QA/certification pass.

Baseline when this plan was created: `14266ab9d7b49fd73f7caf2f2efe5a7bf0b0f4be` on `webrick-5/batch-1-correctness`.

The original design intent of `FusedMatcher` and `ShardedMatcher` was to retain the performance philosophy of `nikic/FastRoute` while extending it for Webrick's own routing model: host routing, richer constraints, persistent workers, compiled production artifacts, deterministic route identity, and optional sharded working sets.

The Webrick 5 correctness refactor preserved the high-level compile-first model, but the dynamic-route hot path drifted away from FastRoute-style machinery. Current canonical matching narrows candidates by host, segment count and first literal prefix, then iterates candidate routes and route segments in PHP and runs individual constraint checks. That is semantically clean, but it moves route discrimination back into userland loops.

FastRoute's recommended MarkBased dispatcher keeps static routes as direct hash lookups and compiles dynamic routes into combined PCRE chunks using `(*MARK:...)`, allowing PCRE to perform most dynamic-route discrimination in native code. This revision restores that class of machinery while preserving Webrick-specific improvements.

This is not a feature expansion. It is a focused performance-architecture correction for the routing hot path before final QA and benchmarking.

---

## Non-negotiable design goals

1. **Compile once, match cheaply.** Route parsing, regex construction, route conflict validation, host normalization and artifact generation remain build/finalization work.
2. **FastRoute-class dynamic dispatch.** Regex-compilable dynamic routes must use combined PCRE chunks rather than one PHP candidate loop plus one `preg_match()` per variable segment.
3. **One semantic matcher core for Fused and Sharded.** Sharding changes storage/working-set boundaries only; it must not create a different matching algorithm.
4. **Static routes remain direct lookups.** Do not regress exact static routing into scans or regex matching.
5. **Callable constraints remain supported without penalizing the common case.** Non-PCRE constraints use a separate, narrow fallback lane.
6. **Host routing remains first-class.** Exact host and wildcard host semantics must remain deterministic and cheap.
7. **HEAD, OPTIONS and 405 behavior remain identical across matcher modes.** Optimization must not create semantic divergence.
8. **No request-path compilation or artifact writes.** Production request matching must not generate regexes, PHP files, route structures or cache files.
9. **No request-derived global/static growth.** Persistent-worker safety rules from the completed correctness phase remain in force.
10. **No unnecessary route materialization.** Compiled production dispatch should continue returning compact route indexes/params until the selected route is actually needed.
11. **Deterministic artifacts.** The same route set/configuration must produce stable matcher structures and hashes.
12. **Benchmark before declaring a winner.** Fused is the baseline/default direction; Generated and Sharded must justify their additional complexity with measured benefits.

---

## Reference performance model

FastRoute MarkBased is the external performance reference for comparable routing semantics.

Its useful properties are:

- static lookup: `method -> URI -> route`;
- dynamic routes compiled ahead of time;
- multiple dynamic route regexes merged into bounded PCRE chunks;
- `(*MARK:<route-id>)` identifies the winning route from one `preg_match()`;
- captures are mapped directly to route variables;
- chunking limits regex size while reducing userland iteration.

Webrick must not copy FastRoute mechanically. Webrick has additional information available at compile time and should use it to reduce candidate sets further.

Target Webrick shape:

```text
host
  -> method
    -> static exact-path map
    -> dynamic segment-count bucket
      -> first-literal-prefix bucket
        -> combined PCRE chunk(s)
          -> MARK route id
          -> captures
    -> callable-constraint fallback lane
```

The exact array order (`host -> method -> path` versus `host -> path -> method`) is a benchmark decision, not an assumption.

---

## 1. Introduce a compiled matcher IR

Create one compact build-time/runtime representation consumed by both Fused and Sharded.

The IR must separate:

- static exact routes;
- regex-compilable dynamic routes;
- callable/non-PCRE dynamic fallbacks;
- route payload/index metadata;
- variable-name capture maps;
- method maps and allowed-method metadata;
- exact-host and wildcard-host groups.

The IR should retain the useful current pre-bucketing dimensions:

- host;
- segment count;
- first literal path segment/prefix;
- method where measurement shows that method-first indexing reduces work.

Do not retain `CompiledRoute` objects in production cache data when a scalar route index/payload descriptor is sufficient.

### Required properties

- deterministic ordering;
- no runtime validation of already validated compiled regex structures;
- no repeated segment-spec normalization on each request;
- no rebuilding allowed-method information through full route scans where it can be compiled;
- cache payload version bump when the persisted matcher representation changes.

---

## 2. Restore a combined-PCRE dynamic fast lane

For routes whose constraints can be represented by PCRE, compile candidate routes into combined anchored regex chunks.

Use a MARK-style discriminator or an equivalently efficient PCRE mechanism so a successful match identifies the route without a PHP loop over every route in the bucket.

Conceptually:

```text
bucket routes
  -> compile patterns
  -> chunk patterns
  -> ^(?| routeA(*MARK:a) | routeB(*MARK:b) | ... )$
  -> preg_match()
  -> MARK
  -> route index + capture map
```

### Requirements

- route variable captures must map deterministically to parameter names;
- literals must be correctly escaped;
- custom route regex constraints must preserve their validated semantics;
- nested/capturing user regex groups must not corrupt route capture numbering;
- delimiter/modifier handling must stay compatible with Webrick's constraint grammar;
- optional route syntax, if represented in compiled routes, must keep registration order/precedence semantics;
- duplicate and shadow/conflict behavior must remain deterministic;
- malformed/uncompilable regex must fail during build/finalization, never first request;
- PCRE errors must not silently become 404s during artifact generation or validation.

---

## 3. Keep callable constraints on an isolated fallback lane

Callable constraints cannot be merged directly into combined PCRE.

Do not make every route pay the callable-constraint cost.

Each dynamic bucket should therefore have two lanes:

1. **PCRE fast lane** — combined regex chunks for ordinary literal/regex constraints;
2. **callable fallback lane** — only routes that genuinely require runtime callable checks.

The fallback lane should still use host/segment-count/prefix narrowing and should avoid rebuilding normalized segment structures per request.

If a route mixes PCRE constraints with a callable constraint, keep the entire route in the fallback lane unless a measured two-stage prefilter is demonstrably faster and remains simple.

---

## 4. Make FusedMatcher the canonical implementation

`FusedMatcher` becomes the reference implementation of the compiled matcher IR.

Its production artifact remains one PHP file containing all matcher data required for dispatch.

Request-time expectations:

- resolve exact/wildcard host group;
- select static/dynamic path;
- direct static lookup for exact routes;
- select dynamic segment/prefix/method bucket with minimal allocations;
- execute one or a small bounded number of combined PCRE chunks;
- map MARK/captures directly to compact route index + params;
- only use callable fallback when required;
- produce HEAD/OPTIONS/405 outcome without rescanning the complete route table.

Avoid generic normalization helpers in `matchCompiled()` when the artifact has already been validated during build/load.

---

## 5. Rebase ShardedMatcher on the exact Fused machinery

`ShardedMatcher` must use the same compiled matcher IR and the same dynamic PCRE executor as Fused.

Sharded's only architectural distinction should be physical partitioning/lazy working-set loading.

Target shape:

```text
Fused
  -> one artifact
     -> shared compiled matcher groups

Sharded
  -> manifest/generation
     -> host/prefix shard artifacts
        -> the same compiled matcher groups
```

Do not maintain separate route-discrimination logic for Sharded.

### Sharded-specific goals

- preserve immutable generation + atomic manifest publication;
- preserve no-request-path directory discovery;
- memoize loaded shards per persistent worker;
- use pre-known shard metadata to avoid unnecessary `is_file()` probing where practical;
- make first-hit shard loading explicit in benchmarks;
- measure FPM versus persistent-worker behavior separately;
- prove a memory/working-set or very-large-route-set benefit before recommending Sharded.

---

## 6. Reassess GeneratedMatcher after the shared core is fast

Do not optimize Generated first.

Once Fused/Sharded share the new compiled-PCRE core, benchmark Generated against them.

Generated currently specializes matching by emitting PHP switches/conditions. That is a genuinely different optimization strategy, but it also increases generated code size, OPcache footprint and compiler complexity.

After the Fused revision:

- preserve Generated only if representative workloads show a meaningful throughput/latency benefit or another clear operational advantage;
- consider reusing the same compiled PCRE bucket metadata inside generated code rather than emitting one PHP condition per dynamic route;
- measure artifact size, OPcache memory, cold boot and warm dispatch, not only microbenchmark dispatch time;
- do not keep generated complexity merely because the matcher already exists.

No removal decision is made before benchmark evidence.

---

## 7. Static-map orientation benchmark

Benchmark at least these static layouts:

```text
host -> path -> method
host -> method -> path
```

Measure:

- GET-heavy traffic;
- mixed verbs on the same URI;
- many unique URIs;
- host-specific and wildcard-host routes;
- memory footprint;
- production compiled route-index return path.

Use the faster/smaller orientation if the difference is repeatable. Do not change public semantics.

---

## 8. Dynamic chunk-size benchmark

FastRoute MarkBased uses an approximate chunk size around 30 routes. Webrick should not assume that is optimal after adding host/segment/prefix pre-bucketing.

Test multiple chunk targets, for example:

- 8;
- 16;
- 24;
- 32;
- 48;
- 64;
- adaptive sizing by pattern/capture complexity if justified.

Measure both compile cost and warm dispatch cost.

Prefer a simple fixed default unless adaptive sizing produces a substantial repeatable gain.

---

## 9. Avoid request-path allocation and normalization

Audit the new matcher hot path specifically for:

- `explode()`/path segmentation that can be skipped for static routes;
- repeated `count()`/prefix extraction across candidate groups;
- rebuilding verb maps;
- route payload normalization;
- segment-spec normalization;
- temporary arrays used only to calculate 405/OPTIONS;
- unnecessary `MatchOutcome` allocation on the production compiled lane;
- route object materialization before a match is known;
- repeated lowercasing/canonicalization already guaranteed by caller contracts.

The public matcher APIs may keep rich `MatchOutcome` behavior, but `matchCompiled()` must remain the minimal production lane.

---

## 10. Semantic parity requirements

All three matcher modes must continue to agree on:

- exact static routes;
- dynamic regex constraints;
- callable constraints;
- exact host routing;
- wildcard host routing;
- route variables;
- duplicate route rejection;
- route precedence;
- HEAD -> GET fallback;
- explicit HEAD route precedence;
- explicit OPTIONS route precedence;
- automatic OPTIONS allowed-method list;
- 405 allowed-method list;
- 404 behavior;
- extension/custom HTTP methods;
- cached versus uncached matching;
- compact compiled route indexes;
- alias/middleware metadata.

Build one shared matcher-conformance corpus so the execution strategy cannot change semantics.

---

## 11. Benchmark matrix

### External reference

Benchmark against current `nikic/FastRoute` MarkBased using equivalent route semantics where possible.

FastRoute is a performance reference/floor, not a feature-equivalence claim. Webrick does more work for host-aware and richer routing, so benchmark both:

1. a FastRoute-compatible subset with no Webrick-only features;
2. Webrick-native host/constraint workloads.

### Matcher candidates

- FastRoute MarkBased;
- Webrick Fused;
- Webrick Generated;
- Webrick Sharded.

### Route-set sizes

At minimum:

- 10;
- 100;
- 1,000;
- 5,000;
- 10,000;
- 50,000 where memory/time permits.

### Route mixes

- 100% static;
- 80/20 static/dynamic;
- 50/50 static/dynamic;
- dynamic-heavy;
- shared first prefixes;
- widely distributed prefixes;
- regex constraints;
- callable constraints isolated and mixed;
- single host;
- many exact hosts;
- wildcard host fallback.

### Hit positions

Include:

- static hit;
- early dynamic hit;
- middle dynamic hit;
- late dynamic hit;
- 404 miss;
- 405 miss;
- HEAD fallback;
- OPTIONS.

### Runtime modes

Measure separately:

- direct standalone matcher dispatch;
- compiled Webrick router/kernel dispatch;
- PHP-FPM-style short-lived worker assumptions;
- persistent-worker warm state;
- cold cache/artifact boot;
- warm OPcache/artifact boot.

### Metrics

Record:

- operations/requests per second;
- p50/p95/p99 latency;
- CPU time;
- peak/build memory;
- steady worker RSS;
- artifact size;
- cache build/compile time;
- cold boot time;
- warm boot time;
- first shard hit versus already-loaded shard;
- OPcache footprint where practical.

Use repeated runs and compare medians. Do not publish one-off microbenchmark numbers as final claims.

---

## 12. Performance gates

This phase is complete only when the matcher roles are supported by measurements.

### Fused

Fused is the default/reference Webrick matcher only if:

- its static path is effectively direct-map dispatch;
- its regex dynamic path uses compiled combined-PCRE discrimination;
- its comparable-semantics performance is close to FastRoute MarkBased, or any remaining overhead is clearly attributable to deliberate Webrick semantics;
- it has predictable one-artifact deployment and acceptable memory across ordinary route sets.

The target is not an arbitrary percentage promise before measurement. The goal is to remove avoidable architectural overhead and make any remaining delta explainable.

### Generated

Generated remains a recommended throughput specialization only if it demonstrates a meaningful repeatable advantage over revised Fused under representative production-like workloads without disproportionate artifact/OPcache/cold-start cost.

### Sharded

Sharded remains a recommended scale specialization only if it demonstrates a meaningful working-set, memory, or very-large-route-set benefit that justifies shard complexity and first-hit loading behavior.

If Fused dominates Sharded for realistic route counts, documentation must say so. If Generated does not justify itself, its role should be reconsidered before Webrick 5 release.

---

## 13. Implementation order

This is one development phase, not a new series of correctness batches.

Implement in this order:

1. define the shared compiled matcher IR and conformance expectations;
2. implement combined-PCRE chunk compilation;
3. implement MARK/capture result decoding;
4. isolate callable fallback routes;
5. switch Fused to the new shared engine;
6. switch Sharded to the exact same compiled group format/executor;
7. remove obsolete segment-by-segment hot-path machinery where no longer needed;
8. align Generated with the new IR where useful, without premature optimization;
9. add matcher conformance/regression tests;
10. add dedicated matcher microbenchmarks against FastRoute;
11. run representative end-to-end Webrick benchmarks;
12. finalize matcher recommendation docs from measured results.

Keep commits granular and reviewable. Do not squash the implementation history.

---

## 14. Boundaries

Do not use this phase to introduce unrelated router features.

Out of scope unless required by the matcher refactor:

- new route syntax;
- new middleware features;
- new request/response APIs;
- container changes;
- unrelated HTTP correctness work;
- general framework feature additions.

Any correctness bug exposed by the matcher refactor should be fixed directly and covered by regression tests, but this phase must remain matcher-focused.

---

## 15. Completion criteria

Matcher development is complete when:

- Fused and Sharded share one compiled matcher IR and one request-time route-discrimination engine;
- regex-compilable dynamic routes use combined PCRE chunks rather than per-route/per-segment userland matching;
- callable constraints are isolated to a fallback lane;
- static routing stays direct-map based;
- HEAD/OPTIONS/405/404/host semantics are identical across modes;
- persisted matcher artifacts are versioned and deterministic;
- production matching performs no route compilation or artifact writes;
- matcher conformance regression coverage is present;
- FastRoute MarkBased comparison benchmarks exist on the same representative route corpus;
- Fused/Generated/Sharded roles are decided from measured throughput, latency, memory, artifact and boot behavior;
- matcher documentation is updated to reflect those measurements.

After this phase closes, Webrick 5 moves directly to the already-deferred full QA/certification pass. No additional planned development phase should be created unless QA exposes an actual defect.

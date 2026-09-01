# Webrick Matcher Optimization Plan

Status: active development plan — Phase 1 complete  
Branch: `webrick-5/batch-1-correctness`

## Objective

Push Webrick routing beyond the current Fused, Sharded, and Generated matcher trade-offs without adding a fourth public matcher unless benchmarks prove that a separate execution mode is materially useful.

The preferred end-state is:

- **Fused**: fully resident adaptive compiled IR.
- **Sharded**: the same adaptive compiled IR with bounded/lazy working-set residency.
- **Generated**: generated-PHP specialization retained where code generation wins.

The work must improve throughput/latency without weakening routing precedence, host fallback, constraint semantics, HEAD fallback, 404/405 behavior, automatic OPTIONS, cache integrity, deterministic route IDs, or production artifact behavior.

## Current baseline

### Fused

- Uses `CanonicalMatcherIndex` at build time.
- Compiles to the compact matcher IR via `CompiledMatcherIndexCompiler` and `CompiledMatcherIrCompactor`.
- Uses `CompiledMatcherFastEngine` for scalar-ID production dispatch.
- Keeps the complete compiled matcher resident.

### Sharded

- Uses the same compact IR/executor as Fused.
- Changes cache/residency behavior by partitioning route groups into host/prefix shards.
- Optimizes cold boot and loaded working-set size at the cost of shard lookup/loading machinery.

### Generated

- Compiles canonical routes into generated PHP control flow.
- Uses host, segment-count, prefix, route-condition, and verb dispatch switches/branches.
- Can win for small/simple route sets but code/opcache footprint grows with route count.

## Main opportunity

Evolve the shared compact matcher compiler into an **adaptive decision engine**. The compiler should select the cheapest representation for each route family instead of forcing all dynamic routes through one strategy.

Candidate execution forms:

1. exact static hash lookup;
2. segment-count discrimination;
3. literal-segment decision nodes;
4. specialized built-in constraint predicates;
5. combined MARK-based PCRE leaves;
6. custom-regex/callable fallback leaves;
7. compact method terminals carrying route IDs and allowed-method metadata.

PCRE remains an accelerator for route families where PCRE2 is the best execution engine. The goal is not to replace PCRE with a PHP trie.

## Design principles

1. **No speculative public API.** Do not add `MatcherModeEnum::ADAPTIVE` until a benchmark gate demonstrates a workload where it deserves to remain independent.
2. **One canonical semantic model.** Fused and Sharded must continue to compile from the same canonical route index and share route precedence semantics.
3. **Scalar hot path.** Matching returns deterministic scalar route IDs and positional parameters. Rich `CompiledRoute` materialization remains outside compact dispatch.
4. **Build-time complexity is preferable to request-time complexity** when the artifact remains deterministic, cacheable, and auditable.
5. **No per-request work that can be compiled once.** Method metadata, literal discriminators, constraint kinds, route parameter positions, and safe PCRE grouping belong in the artifact.
6. **Bounded memory.** Any adaptive metadata must justify its footprint at 1k, 5k, 10k, and larger route sets.
7. **Preserve exact HTTP semantics.** HEAD, 405, OPTIONS, host fallback, registration precedence, and overlapping dynamic patterns are correctness gates, not benchmark trade-offs.

# Work phases

## Phase 1 — Hot executor cleanup

Goal: remove avoidable request-time allocations/branches before changing the IR so later benchmarks measure the algorithm rather than incidental executor overhead.

**Status: complete (2026-09-01).**

Completion record:

- `CompiledMatcherFastEngine` now reuses one `CompiledMatcherDynamicEngine`; request dispatch no longer constructs that executor repeatedly.
- Single-host dynamic dispatch no longer creates a temporary host/wildcard pair array.
- `CompiledMatcherDynamicEngine` directly checks exact-prefix then wildcard buckets instead of building temporary candidate arrays.
- Fast PCRE dispatch no longer creates a temporary step-wrapper array.
- Added `MatcherOutcomeBench` coverage for static/dynamic hits, static/dynamic 404, HEAD fallback, 405, automatic OPTIONS, and domain-dynamic hits across all three matcher modes.
- Added `MatcherPathInspectionBench` as the Phase 2 baseline for path shape, segment materialization, and positional parameter capture.
- A fixed-candidate Sharded compact-dispatch prototype was tested, but intentionally not retained in Phase 1: it pushed `ShardedMatcher` beyond the repository's cognitive-complexity limits without a measured before/after gain sufficient to justify the extra branch surface. Shard-candidate representation should be revisited only as part of the adaptive IR/residency work.
- PHP 8.4 validation passed the complete PHPForge code test suite: **419 tests / 1089 assertions**.

Measured PHP 8.4.25 baseline (GitHub runner, opcache disabled):

| Case | Fused | Generated | Sharded |
| --- | ---: | ---: | ---: |
| static hit | 0.371 µs | 0.282 µs | 0.459 µs |
| dynamic hit | 1.807 µs | 0.608 µs | 1.884 µs |
| dynamic 404 | 1.532 µs | 0.692 µs | 1.620 µs |
| dynamic 405 | 3.106 µs | 1.202 µs | 3.260 µs |
| automatic OPTIONS | 3.174 µs | 1.107 µs | 3.322 µs |
| domain dynamic hit | 2.176 µs | 0.616 µs | 2.225 µs |

Path-inspection baseline:

- current path-shape extraction: roughly **0.084–0.166 µs** across root through deep/extra-slash paths;
- deep-path segment materialization: **0.287 µs**;
- positional three-parameter capture: **0.290 µs**.

These numbers set a deliberately high acceptance bar for Phase 2: a PHP-level scanner must beat the existing C-backed string primitives in end-to-end matcher dispatch, not merely provide a cleaner abstraction.

### 1.1 Dynamic-engine lifetime

- Stop constructing `CompiledMatcherDynamicEngine` inside each dynamic/miss dispatch.
- Reuse one stateless engine per `CompiledMatcherFastEngine` instance or convert truly stateless helpers to static operations where that is cleaner.

### 1.2 Temporary collection removal

Review hot paths for temporary arrays that exist only to iterate one/two candidates, including:

- host + wildcard group pairing;
- prefix + wildcard bucket pairing;
- fast-dispatch step wrapper arrays;
- avoidable array merges/copies in compact dispatch.

Prefer explicit two-candidate branches on the request path when they reduce allocation and remain readable.

### 1.3 Path inspection baseline

Instrument/benchmark the current sequence of:

- path shape calculation;
- first-prefix extraction;
- segment materialization;
- parameter capture.

Do not force `explode()` for static hits or PCRE-only dynamic hits unless measurement proves it is cheaper.

## Phase 2 — Path scanner / request path view

Goal: inspect a dynamic request path once and reuse the result.

Prototype a compact scanner representation that can provide:

- segment count;
- first segment;
- selected segment values;
- segment start/end offsets;
- lazy parameter materialization.

Benchmark at least two implementations:

1. current string helpers (`trim`, `strpos`, `substr_count`, optional `explode`);
2. one-pass offset scanner.

Reject the scanner if PHP-level scanning is slower than the current C-backed string primitives on representative route sets.

## Phase 3 — Built-in constraint opcodes

Goal: remove callable dispatch from common route constraints where Webrick fully owns the semantics.

Introduce internal matcher constraint kinds for candidates such as:

- ANY;
- DIGITS / INT;
- ALPHA;
- ALNUM;
- NUMERIC;
- BOOL;
- UUID;
- ULID;
- HEX;
- SLUG;
- IPV4;
- other built-ins only when specialized evaluation is measurably beneficial.

Rules:

- Arbitrary user callables remain fallback constraints.
- Arbitrary registered regexes remain segment-local unless explicitly proven safe for composition.
- Specialized predicates must be byte-for-byte semantically equivalent to existing constraint behavior.
- Do not replace PCRE-backed constraints merely for architectural neatness; benchmark them.

## Phase 4 — Recursive adaptive discriminator

Goal: generalize today's single literal `fast_dispatch` optimization into a shallow build-time decision structure.

### Compiler

For each dynamic route family, calculate candidate discriminators and estimated cost:

- route count;
- distinct literals per segment position;
- average candidate count after partition;
- PCRE-safe percentage;
- callable/fallback barriers;
- generated metadata size.

Select a discriminator only when it materially reduces expected candidates.

Allow recursive partitioning with a strict maximum depth to prevent oversized nested PHP arrays and pointer-heavy trie behavior.

### Runtime

Use compact decision nodes to narrow a bucket before invoking:

- a direct specialized predicate;
- a small straight-line route sequence;
- a combined PCRE chunk;
- a fallback constraint sequence.

The runtime representation should be flat/compact where possible. Avoid object-per-node designs.

## Phase 5 — Method-independent path terminals

Goal: match the route path shape once and make method handling terminal metadata rather than a second matching concern when safe.

Prototype terminal metadata containing:

- route ID per registered method;
- implicit HEAD-from-GET behavior;
- compact allowed-method representation;
- parameter capture metadata.

Evaluate a compact bit mask or indexed method table for common HTTP methods, with a safe extension path for arbitrary methods.

Expected benefit:

- cheaper 405 responses;
- cheaper automatic OPTIONS;
- reduced cross-method re-evaluation on dynamic misses.

Do not adopt this representation if it complicates custom-method correctness or materially increases common-hit memory.

## Phase 6 — Adaptive PCRE policy

Goal: make PCRE chunking/discrimination route-family aware rather than controlled primarily by one global chunk target.

Benchmark:

- current balanced target (`48`);
- smaller/larger chunk families;
- chunk size selected by route family size;
- literal discriminator before PCRE;
- specialized constraint before PCRE;
- one large PCRE versus multiple smaller chunks.

The compiler may choose different strategies for different buckets, but generated artifacts must remain deterministic.

## Phase 7 — Fused integration

Once the adaptive IR beats the current shared IR across the acceptance matrix:

- make it the Fused runtime representation;
- update cache version;
- keep cache validation/hash guarantees;
- keep deterministic route IDs and alias/middleware metadata;
- retain the existing compact result contract.

Do not keep a parallel old/new Fused implementation after the migration gate unless measurements justify a fallback mode.

## Phase 8 — Sharded integration

Apply the same adaptive group IR to Sharded.

Validate that:

- shard boundaries remain a storage/residency concern only;
- decision nodes do not cross shard boundaries in ways that require unrelated shard loading;
- first-hit and warm-hit performance remain competitive;
- generation publication and validation remain atomic.

## Phase 9 — Generated matcher decision

After adaptive Fused/Sharded stabilize, benchmark Generated again.

Possible outcomes:

1. Generated still wins materially for small route sets: retain it and document its winning envelope.
2. Adaptive Fused matches/beats Generated broadly: consider removing Generated in a future major release, not as part of an unmeasured cleanup.
3. Generated can reuse adaptive compiler decisions: share analysis metadata without coupling runtime representations.

# Benchmark matrix

Every significant matcher change must be measured across route counts:

- 10
- 50
- 100
- 250
- 500
- 1,000
- 5,000
- 10,000

Add larger stress cases when useful (25k/50k) for Sharded memory/residency behavior.

## Required request shapes

- static early hit;
- static late/representative hit;
- static miss;
- simple dynamic `{value}` hit;
- `{id:int}` hit/miss;
- regex-safe built-in hit/miss;
- deep dynamic path;
- same-prefix/high-overlap family;
- highly discriminated literal family;
- callable constraint family;
- custom regex family;
- host-specific hit;
- wildcard-host fallback;
- HEAD exact/fallback;
- 405;
- automatic OPTIONS;
- 404 dynamic miss.

## Metrics

Record separately:

- warm ns/op;
- first-hit latency;
- cold boot time;
- build time;
- artifact size;
- resident memory after boot;
- memory after first shard hit;
- opcache/code footprint for Generated where observable.

Use medians/distributions where practical; do not decide from one microbenchmark number.

# Acceptance gates

A change may replace the current Fused shared engine only when all of the following hold:

1. no routing correctness regression;
2. no cache/artifact determinism regression;
3. no material static-hit regression;
4. clear dynamic-hit improvement in at least the common route families;
5. misses/405/OPTIONS are neutral or faster;
6. memory growth is justified by measured latency/throughput gain;
7. 5k/10k route scaling does not collapse;
8. Sharded can consume the same IR without defeating working-set isolation.

A new public matcher mode is allowed only when it has a clear, stable workload envelope that cannot be represented cleanly as Fused/Sharded storage over the shared adaptive engine.

# Correctness checklist

For each IR/compiler milestone verify:

- registration precedence;
- overlapping dynamic patterns;
- host-specific before wildcard host;
- static before dynamic where current semantics require it;
- custom constraint semantics;
- slash behavior;
- nested capture-group safety;
- parameter names/order;
- deterministic route index;
- GET -> HEAD fallback;
- explicit HEAD precedence;
- 405 Allow content;
- automatic OPTIONS;
- alias lookup;
- middleware requirement metadata;
- cache corruption/stale-version rejection;
- cache boot without route registration when supported.

# Initial implementation batch

Start with Phase 1 only. These changes are intentionally low-risk and should land before the adaptive compiler work:

1. reuse the dynamic executor instead of allocating it during each dynamic dispatch;
2. remove temporary host/wildcard iteration arrays from the compact hot path where possible;
3. remove temporary PCRE-step wrapper arrays in fast dispatch;
4. establish/extend benchmark cases for dynamic hit, dynamic miss, 405, and OPTIONS before changing the IR.

After Phase 1 measurements, proceed to the path-scanner experiment, then built-in constraint opcodes, then recursive adaptive discrimination.

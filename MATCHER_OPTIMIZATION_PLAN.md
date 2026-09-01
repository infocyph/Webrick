# Webrick Matcher Optimization Plan

Status: complete — Phases 1–9 closed (2026-09-01)
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
- The complete PHPForge CI gate passed after the final retained-code formatting: PHPProbe, Pest, Pint, PHPCS, Deptrac, PHPStan, Psalm, Rector, and Composer Normalize.

Final retained-code PHP 8.4.25 baseline (GitHub runner, opcache disabled):

| Case | Fused | Generated | Sharded |
| --- | ---: | ---: | ---: |
| static hit | 0.389 µs | 0.294 µs | 0.481 µs |
| dynamic hit | 1.875 µs | 0.696 µs | 1.949 µs |
| dynamic 404 | 1.564 µs | 0.743 µs | 1.737 µs |
| dynamic 405 | 3.252 µs | 1.166 µs | 3.333 µs |
| automatic OPTIONS | 3.220 µs | 1.188 µs | 3.312 µs |
| domain dynamic hit | 2.250 µs | 0.687 µs | 2.294 µs |

Path-inspection baseline:

- current path-shape extraction: roughly **0.077–0.172 µs** across root through deep/extra-slash paths;
- deep-path segment materialization: **0.269 µs**;
- positional three-parameter capture: **0.291 µs**.

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

**Status: complete (2026-09-01) — scanner rejected by benchmark.**

Decision record:

- The current C-backed string strategy remains the production design: `trim()`, `strpos()`, `substr_count()`, and lazy `explode()` only when segment materialization is actually required.
- A PHP one-pass scanner was benchmarked for segment count + first-prefix extraction and for a materialized request-path view. It was slower for every representative path shape.
- A second candidate that avoided `explode()` by walking to one selected segment with repeated `strpos()` calls was also slower for every tested segment position, including the first segment.
- No `PathView` object/array is introduced. Allocating a per-request path representation would add cost to PCRE-only dynamic hits that currently never need segment materialization.
- `MatcherPathInspectionBench` now permanently retains the current-vs-one-pass comparison so this rejected direction remains reproducible.
- This phase intentionally makes no production matcher change: the acceptance rule required a measured win, and none was present.

PHP 8.4.25 experiment results (GitHub runner, opcache disabled):

| Inspection case | Current | Candidate | Result |
| --- | ---: | ---: | --- |
| shape, shallow path | 0.285 µs | 0.529 µs one-pass | current ~46% lower latency |
| shape, medium path | 0.288 µs | 0.672 µs one-pass | current ~57% lower latency |
| shape, deep path | 0.296 µs | 1.264 µs one-pass | current ~77% lower latency |
| shape + segments, shallow | 0.491 µs | 0.659 µs one-pass view | current ~25% lower latency |
| shape + segments, medium | 0.518 µs | 0.855 µs one-pass view | current ~39% lower latency |
| shape + segments, deep | 0.591 µs | 1.533 µs one-pass view | current ~61% lower latency |
| selected segment, shallow | 0.250 µs explode/index | 0.416 µs offset walk | current ~40% lower latency |
| selected segment, deep middle | 0.352 µs explode/index | 0.577 µs offset walk | current ~39% lower latency |

Phase 3 therefore starts from the existing lazy path materialization model. Constraint specialization must improve matcher work without depending on a PHP-level request-path scanner.

## Phase 3 — Built-in constraint opcodes

**Status: complete (2026-09-01).**

Completion record:

- Fixed compact discriminator validation for PHP numeric-string array keys; numeric literal route families now scale through Fused/Sharded validation.
- Specialized matcher-only opcodes cover the built-in callable constraints `int`/`digit`, `numeric`/`float`, `alpha`, `alnum`, `bool`, `json`, and `ipv6`.
- Canonical route definitions and public constraint APIs remain unchanged; only compact fallback IR is specialized.
- Unknown/custom callables retain the generic callable fallback representation.
- Regex-safe built-ins remain on the combined-PCRE path.
- Fused and Sharded cache versions were advanced for the changed compact IR.
- Same-run baseline/candidate typed-constraint measurements, general outcome benchmarks, the complete scale matrix, and the full PHPForge gate passed before landing.

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

**Status: complete — candidate rejected by benchmark (2026-09-01).**

A bounded second literal discriminator was implemented and measured on a 16×16 literal family, then reverted because it did not clear the required performance gate. The one-level `fast_dispatch` remains the production design. Metrics: fused-hit: 2.259 -> 2.260 us (1.000x); fused-miss: 2.686 -> 2.665 us (0.992x); sharded-hit: 2.304 -> 2.333 us (1.013x); sharded-miss: 2.805 -> 2.790 us (0.995x); average ratio: 1.000x.

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

**Status: complete — retained single-shape method terminal (2026-09-01).**

When a dynamic count/prefix bucket contains one canonical PCRE-safe route shape, its complete method set is now compiled beside one path predicate. 405 and automatic OPTIONS therefore evaluate that path once instead of re-running the dynamic matcher once per registered method. Requested-method hits remain on the existing method-first path. Same-run A/B metrics: fused-hit: 1.749 -> 1.769 us (1.011x); fused-options: 7.583 -> 2.328 us (0.307x); fused-405: 7.364 -> 2.336 us (0.317x); fused-404: 4.196 -> 1.820 us (0.434x); sharded-hit: 1.816 -> 1.845 us (1.016x); sharded-options: 7.432 -> 2.356 us (0.317x); sharded-405: 7.502 -> 2.355 us (0.314x); sharded-404: 4.218 -> 1.904 us (0.451x); miss/options average ratio: 0.357x.

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

**Status: complete — retained the 48-route PCRE chunk target (2026-09-01).**

Chunk targets 24, 48, and 96 were measured on the same runner using a 243-route all-PCRE family whose literal cardinality deliberately stays below the `fast_dispatch` threshold. The global target changes only when a candidate improves the aggregate by at least 5% without making a hit more than 3% slower. No candidate cleared that gate, so the existing target remains 48. Metrics: chunk 24: average 4.311 us;   fused-early-hit: 1.841 us;   fused-late-hit: 5.335 us;   fused-miss: 5.754 us;   sharded-early-hit: 1.898 us;   sharded-late-hit: 5.267 us;   sharded-miss: 5.769 us; chunk 48: average 3.663 us;   fused-early-hit: 1.864 us;   fused-late-hit: 4.317 us;   fused-miss: 4.668 us;   sharded-early-hit: 1.963 us;   sharded-late-hit: 4.364 us;   sharded-miss: 4.800 us; chunk 96: average 3.426 us;   fused-early-hit: 1.967 us;   fused-late-hit: 3.886 us;   fused-miss: 4.342 us;   sharded-early-hit: 2.062 us;   sharded-late-hit: 3.944 us;   sharded-miss: 4.353 us; chosen chunk: 48.

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

**Status: complete (2026-09-01) — the retained shared compact IR was already the Fused production representation.**

Completion record:

- No parallel migration layer was needed. `FusedMatcher` already compiles `CanonicalMatcherIndex` through `CompiledMatcherIndexCompiler`, compacts with `CompiledMatcherIrCompactor`, validates with `CompactMatcherIndexValidator`, and dispatches through the shared compact engines.
- The Phase 3 constraint opcodes and Phase 5 single-shape method terminals therefore became Fused behavior at their original acceptance commits rather than waiting for a separate Phase 7 copy/integration step.
- Cache version `16`, deterministic route IDs, alias/middleware metadata, compact result contracts, cache hashes, and stale/corrupt artifact rejection remain intact. Phase 7 itself changed no persisted representation and therefore required no additional cache-version bump.
- The permanent `benchmark/matcher_envelope.php` profiler now records build time, artifact size, cold boot, first hit, warm hit, boot memory, first-hit memory, and build peak for all matcher modes in isolated processes.
- Full PHPForge validation passed before the integration envelope was accepted.

Representative PHP 8.4.25 cached-envelope results (opcache disabled):

| Routes | Build | Artifact | Cold boot | First hit | Warm hit | Boot memory |
| ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| 1,000 | 70.54 ms | 2.00 MB | 12.69 ms | 29.95 µs | 1.560 µs | 4.47 MB |
| 5,000 | 378.32 ms | 9.76 MB | 69.78 ms | 33.65 µs | 1.708 µs | 21.74 MB |
| 10,000 | 1,046.32 ms | 24.05 MB | 173.51 ms | 43.13 µs | 1.866 µs | 52.40 MB |

The complete scale benchmark remains effectively flat: the final post-integration 10,000-route representative hit was about **2.04 µs**. Fused is therefore the broad low-latency default: it pays full cache boot/resident-memory cost to keep request-time latency nearly independent of route-table size.

Once the adaptive IR beats the current shared IR across the acceptance matrix:

- make it the Fused runtime representation;
- update cache version;
- keep cache validation/hash guarantees;
- keep deterministic route IDs and alias/middleware metadata;
- retain the existing compact result contract.

Do not keep a parallel old/new Fused implementation after the migration gate unless measurements justify a fallback mode.

## Phase 8 — Sharded integration

**Status: complete (2026-09-01) — shared IR retained and cached candidate-group memoization landed.**

Completion record:

- Sharded persists and validates the same compact matcher group IR used by Fused; shard boundaries remain a host/prefix storage and working-set concern only.
- Generation publication, manifest selection, per-shard validation/hash checks, and atomic cache-generation behavior remain unchanged.
- Integration profiling exposed one real cached hot-path cost: an already-loaded shard still rebuilt/sanitized shard file paths and reconstructed its candidate-group list on every request.
- `ShardedMatcher` now memoizes the resolved candidate-group list per host/prefix. File-name sanitation and candidate-array construction therefore occur on first access to a shard rather than every warm request.
- The memoization changes only in-memory residency metadata, not persisted cache format, so cache version `16` remains correct.
- The candidate cleared a same-run gate requiring at least 20% warm improvement at 1k/5k/10k without more than 15% first-hit regression, then passed the complete PHPForge gate and outcome/scale regressions.

Same-run cached Sharded A/B medians:

| Routes | Warm baseline | Warm retained | Ratio | First baseline | First retained | Ratio |
| ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| 1,000 | 5.277 µs | 2.082 µs | 0.395x | 166.4 µs | 169.5 µs | 1.019x |
| 5,000 | 5.348 µs | 2.237 µs | 0.418x | 458.1 µs | 431.3 µs | 0.941x |
| 10,000 | 5.633 µs | 2.405 µs | 0.427x | 983.3 µs | 988.8 µs | 1.006x |

The retained change cuts cached warm latency by roughly **57–61%** while first-hit latency stays neutral. Tested first-hit memory grows by only about **1 KB** for the memoized candidate list. A final isolated 10,000-route cache profile measured **57.23 µs cold boot**, **985.98 µs first hit**, **2.489 µs warm hit**, only **776 bytes boot-memory delta**, and **361,440 bytes first-hit memory**.

This preserves Sharded's intended envelope: dramatically cheaper cache boot and bounded loaded working set than Fused, with warm dispatch now much closer to the shared in-memory engine.

Apply the same adaptive group IR to Sharded.

Validate that:

- shard boundaries remain a storage/residency concern only;
- decision nodes do not cross shard boundaries in ways that require unrelated shard loading;
- first-hit and warm-hit performance remain competitive;
- generation publication and validation remain atomic.

## Phase 9 — Generated matcher decision

**Status: complete (2026-09-01) — retain Generated as an explicit small-route specialization.**

Decision record:

- Generated still earns a separate public mode for small/simple route tables. It is materially faster than Fused in the low-thousands while accepting larger generated-code, cache-artifact, boot, and resident-memory costs.
- The measured advantage is clear through about **1,500 routes** in the synthetic cache envelope. Around **1,750–2,000 routes** it reaches near-parity, and from **2,250 routes onward Fused is generally the safer performance choice**.
- Generated is deliberately **not** auto-selected by route count. The generated PHP control-flow shape produces non-monotonic results (for example, isolated near-wins around 3.5k/4.5k in this synthetic family), so route count alone is not a stable selection heuristic.
- At 5,000 routes the generated representation crosses a severe code-size/execution cliff: median cached warm latency was **69.001 µs** versus Fused **1.745 µs** — about **39.6x slower**. The broader scale suite likewise places Generated around 100 µs at 5k/10k while Fused remains about 2 µs.
- Generated's artifact footprint is also much larger: at 5,000 routes about **26.04 MB** versus Fused **9.76 MB**; at 10,000 routes the earlier envelope measured about **52.11 MB** versus **24.05 MB**. Its build/cold-boot/resident-memory envelope grows correspondingly faster.
- No Generated removal is justified. Likewise, there is no benchmark justification for a fourth `MatcherModeEnum::ADAPTIVE` mode: the retained adaptive decisions belong inside the shared Fused/Sharded compiler/runtime.
- Generated may reuse compiler analysis metadata in a future measured optimization, but its generated-PHP runtime should remain decoupled from the compact IR unless that reuse demonstrates a concrete gain.

Crossover study, median cached warm latency from three isolated PHP 8.4.25 runs per point (opcache disabled):

| Routes | Fused | Generated | Generated / Fused |
| ---: | ---: | ---: | ---: |
| 1,000 | 1.606 µs | 0.772 µs | 0.481x |
| 1,250 | 1.607 µs | 1.038 µs | 0.646x |
| 1,500 | 1.738 µs | 0.979 µs | 0.563x |
| 1,750 | 1.567 µs | 1.507 µs | 0.962x |
| 2,000 | 1.623 µs | 1.575 µs | 0.970x |
| 2,250 | 1.626 µs | 1.776 µs | 1.092x |
| 2,500 | 1.673 µs | 1.920 µs | 1.147x |
| 2,750 | 1.624 µs | 1.965 µs | 1.210x |
| 3,000 | 1.662 µs | 2.175 µs | 1.309x |
| 3,500 | 1.680 µs | 1.609 µs | 0.958x |
| 4,000 | 1.762 µs | 2.016 µs | 1.144x |
| 4,500 | 1.742 µs | 1.740 µs | 0.999x |
| 5,000 | 1.745 µs | 69.001 µs | 39.550x |

Final matcher-mode guidance:

- **Generated** — explicit specialization for small/simple route sets where lowest warm-hit latency is worth larger generated artifacts and boot/memory cost.
- **Fused** — broad production default when consistently low request latency across medium/large route tables matters most.
- **Sharded** — same matching semantics/IR with lazy cache residency; prefer when cold boot and bounded loaded working set matter enough to accept a higher first-shard hit.

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

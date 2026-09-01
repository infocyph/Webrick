# Webrick 5 — Matcher FastRoute Optimization Plan

## Status

This is the final matcher-performance optimization program before consolidated QA/certification.

It is not a feature-cutting exercise. Required Webrick routing capabilities remain supported. The program removes redundant runtime machinery, compiles away unused capabilities, and lets the build plane select cheaper request-time structures from the actual route graph.

## Objective

Close the remaining matcher-only gap against FastRoute MarkBased without sacrificing Webrick's required production capabilities.

Current reference after the balanced approximate 48-route combined-PCRE target:

| Scenario | FastRoute MarkBased | Webrick Fused | Gap |
| --- | ---: | ---: | ---: |
| Static | ~241 ns | ~378 ns | ~1.57x |
| Dynamic middle | ~3.01 us | ~6.31 us | ~2.10x |
| Dynamic last | ~5.29 us | ~10.28 us | ~1.94x |
| 404 | ~4.85 us | ~8.35 us | ~1.72x |
| 405 | ~5.57 us | ~11.74 us | ~2.11x |

The governing rule is:

> Keep required behavior and public capability. Remove redundant runtime generality. Move intelligence to compilation. Specialize the request path when the compiled graph proves a feature is unused.

## Required capabilities to preserve

- static and dynamic routing
- route parameters
- built-in/typed constraints
- arbitrary regex constraints where supported
- callable/custom constraints through bounded ordered fallback islands
- deterministic route precedence
- standard and extension HTTP methods
- HEAD fallback
- automatic OPTIONS
- correct 404/405/Allow semantics
- exact and wildcard host routing
- named routes and aliases
- middleware/execution-plan integration
- route metadata including CORS and Produces
- signed/temporary URL support
- closure, class and function handlers where supported
- deterministic production artifacts
- persistent-worker safety
- FPM/Apache, Swoole/OpenSwoole, RoadRunner and Workerman support
- native Request/Response and PSR interop boundaries
- Fused/Sharded semantic parity

Performance work may change internal representation but not these observable capabilities.

## What may be removed

The major release may remove implementation machinery that no longer has public or measured value:

- generic `CompiledRoute`/payload inspection from production compact matching
- runtime extraction of route IDs already known at build time
- `$compact` branches shared by rich and compact match paths
- request-time route materialization on compact dispatch
- intermediary arrays used only to feed another matcher layer
- validation repeated after a validated immutable artifact has loaded
- repeated method/host normalization after the trust boundary
- named PCRE captures if positional captures are faster
- classification dimensions that cost more than they reduce candidates
- host-routing branches when the compiled application has no host-specific routes
- a matcher mode that no longer wins any meaningful measured workload

Removing a redundant matcher implementation is acceptable. Removing required routing capability is not.

## Stage 1 — Compact scalar production IR

1. Store deterministic scalar route IDs directly in the production matcher IR.
2. Add a dedicated compact executor instead of carrying a `$compact` flag through the rich matcher engine.
3. Remove route materialization/payload interpretation from successful compact matching.
4. Keep rich `MatchOutcome` matching for development/public matcher semantics.
5. Re-run semantic sanity and FastRoute smoke.

Primary target: static dispatch; stretch target is <=1.15x FastRoute without dynamic regression.

## Stage 2 — Positional PCRE captures

1. A/B test branch-reset/positional captures against current unique named captures.
2. Store compact ordered parameter-name metadata per MARK.
3. Preserve callable/unsafe regex routes as ordered fallback islands.
4. Compare regex size, build time, memory and dynamic/miss medians.
5. Keep only a repeatable win.
6. Re-run chunk-size sweep because the optimum may change.

## Stage 3 — Adaptive route discrimination

1. Analyze maximal literal prefix before the first variable and route-family structure at build time.
2. Measure candidate density/entropy instead of forcing one classifier on all buckets.
3. Allow the compiler to choose among:
   - direct combined PCRE
   - literal-prefix hash/discriminator
   - segment-count + prefix
   - compact decision tree/trie when justified
   - bounded fallback islands
4. Avoid benchmark-specific route-name special cases.
5. Benchmark distinct-prefix, heavily shared-prefix, realistic REST and mixed route corpora at 100/1k/5k/10k routes.

This stage is Webrick's main opportunity to beat FastRoute by doing less PCRE work from richer compile-time knowledge.

## Stage 4 — Compiled miss/405/OPTIONS metadata

1. Build a method-independent route-shape index.
2. Compile standard-method masks and preserve custom methods through a string fallback.
3. On requested-method miss, perform one path-shape lookup to distinguish 404 from 405/OPTIONS instead of broadly re-running other method matchers.
4. Compile GET/HEAD relationship and automatic OPTIONS/Allow information.
5. Benchmark 404, 405, OPTIONS and HEAD at 1k/5k/10k routes.

Stretch target: beat FastRoute 404/405 at large route counts.

## Stage 5 — Remove unnecessary path/host work

1. Avoid eager `trim`, `substr_count`, `strpos`, `substr`, `explode` and temporary strings unless the selected compiled strategy needs them.
2. If classification is required, derive multiple facts in one cheap scan where useful.
3. Compile domain-routing capability flags.
4. Select a host-free matcher path at boot when the artifact has no domain-specific or wildcard-host routing requirements.
5. Add single-group/single-host executor specialization when the topology proves it safe.

## Stage 6 — Compact MARK/tables and fallback islands

1. Benchmark numeric/dense MARK identifiers and packed route tables.
2. Compact parameter-name/method metadata where it produces repeatable gains.
3. Keep exotic callable/unsafe regex behavior in narrow ordered fallback islands.
4. Never deoptimize a whole ordinary bucket because one route needs fallback behavior.
5. Surface fallback islands in build diagnostics where practical.

## Stage 7 — Matcher portfolio decision and scale certification

### Fused

Remain the canonical/default production matcher unless evidence changes materially.

### Sharded

Keep while it provides a measured very-large-route/cold-boot/working-set advantage. Loaded shards must use the same optimized compact IR/executor as Fused.

### Generated

Re-evaluate only after the canonical compiled IR improvements land. Retain it only if it has a repeatable meaningful workload advantage. Remove it before Webrick 5 if it loses/ties useful workloads while maintaining substantial duplicate complexity.

### Final benchmark matrix

Run semantic sanity plus repeated median benchmarks for:

- 100 / 1,000 / 5,000 / 10,000 routes
- static early/middle/late where relevant
- dynamic early/middle/late
- one and multiple params
- 404
- 405
- HEAD
- OPTIONS
- exact host
- wildcard host
- custom method
- callable fallback
- distinct-prefix and shared-prefix corpora

Also measure build time, artifact size, cold boot, first hit, warm hit and memory.

## Acceptance policy

Every optimization is classified:

- **KEEP** — repeatable gain, semantic parity, acceptable complexity.
- **CONDITIONAL** — useful only for topology the compiler can detect; keep as an adaptive compiled strategy.
- **REJECT** — no repeatable gain, excessive cost/complexity, or semantic risk; remove the experiment.

Do not retain speculative optimizations.

## Engineering targets

These are engineering goals, not release promises:

- static: <=1.15x FastRoute initially; stretch parity/faster
- ordinary dynamic: <=1.25x FastRoute initially; stretch faster on structured/large corpora
- 404/405: <=1.25x initially; stretch faster at scale via compiled shape/method metadata

## Correctness boundaries

Performance must never weaken:

- route precedence
- duplicate detection
- method correctness
- HEAD/OPTIONS behavior
- 405 Allow generation
- host/wildcard-host matching
- parameter values
- encoded-path semantics
- constraint semantics
- deterministic cache artifacts
- persistent-worker isolation

Any affected behavior needs matcher regression coverage before an optimization is accepted.

## Definition of done

This program is complete when:

- Fused has a genuinely compact scalar production matching lane
- unnecessary generic/result/materialization work is removed from that lane
- dynamic matching pays classifier cost only when it reduces candidate work
- structured route sets exploit richer Webrick compile-time discrimination
- 404/405/OPTIONS use compiled route-shape/method knowledge where beneficial
- host routing costs nothing when an application does not use it
- custom constraints remain available without deoptimizing ordinary routes
- Sharded retains a distinct measured startup/working-set purpose
- Generated is either justified by measurements or removed
- every retained optimization has benchmark evidence
- no required Webrick routing feature is dropped

Final rule:

> Do not remove useful Webrick capability to beat FastRoute. Remove unnecessary runtime generality, compile away unused capability, and use Webrick's richer route graph to do less request-time work wherever possible.

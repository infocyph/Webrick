# Webrick WB-5 — Benchmark-Driven Pre-Routing Gate Plan

## Status and ownership

- Status: planned; implementation has not started.
- Owner: Webrick.
- Primary consumer: Foundation 3.
- Scope: compiled production runtime first; development/embedded parity is required before release.
- Release intent: additive Webrick minor capability unless implementation proves entirely internal.

WB-5 belongs in Webrick because Webrick owns `RoutingInput`, compiled matching, request materialization, request-scope entry, runtime adapters, and response writing. Foundation may configure a gate and provide maintenance state, but it must not duplicate Webrick's routing or runtime abstractions.

## 1. Decision evidence

Foundation 3 Phase 6 measured the compiled persistent-runtime path with 25,000 operations per sample, 2,500 warmups, and five repetitions on PHP 8.4.25:

| Scenario | Median | Full `Request` materializations |
|---|---:|---:|
| Maintenance integration not configured | 7,637.1 ns/op | 0 |
| Maintenance integration configured, service serving normally | 57,319.1 ns/op | 127,500 |
| Difference | 650.54% | 127,500 |

The configured maintenance middleware becomes global middleware. Consequently, the compiled kernel must materialize a full `Request` and enter middleware dispatch even when `MaintenanceStateInterface::message()` returns `null`.

This microbenchmark identifies the hot operation and crosses Foundation's fixed 5% WB-5 review threshold. It justifies a Webrick design and benchmark pass; it does not by itself prove an application-level RPM improvement.

## 2. Objective

Provide one optional, boot-selected pre-routing decision point that can:

1. inspect already-normalized lightweight routing data;
2. continue without materializing a `Request`;
3. or return a complete Webrick `Response` before matching, request construction, middleware resolution, handler resolution, or request-scope entry.

The normal no-gate path must remain behaviorally identical and practically equivalent in sustained throughput. The first supported use case is maintenance mode while the service is operating normally or intentionally returning `503 Service Unavailable`.

## 3. Non-goals

- Do not create a second middleware system.
- Do not support a chain, priority system, tags, discovery, reflection, or per-request container lookup.
- Do not replace gateway hardening, request limits, authentication, authorization, throttling, CORS, telemetry, or route middleware.
- Do not expose request bodies, parsed input, cookies, sessions, route parameters, matched-route metadata, controllers, or request-scoped services.
- Do not permit a pre-routing decision to rewrite method, host, or path.
- Do not serialize runtime services or closures into route artifacts.
- Do not add a Foundation-owned fallback or compatibility implementation.
- Do not change native response-emission ownership.
- Do not claim production throughput improvement from the component benchmark alone.

## 4. Required invariants

### 4.1 HTTP and routing

- `RoutingInput` remains the single canonical method/host/path input.
- Method override, host validation, and path normalization occur before the gate.
- A pass decision preserves existing matching, automatic `OPTIONS`, `HEAD`, `404`, `405`, `Allow`, exception-rendering, and middleware behavior.
- A short-circuit response is a normal Webrick `Response`; only the selected runtime adapter writes it.
- `HEAD` never emits a body, including maintenance responses.
- Gate evaluation never performs routing or invents route parameters.

### 4.2 Requestless execution

- A passing gate must not call `RuntimeRequestContext::request()`.
- A short-circuiting gate must not call `RuntimeRequestContext::request()`.
- Neither outcome may enter `webrick.request` scope.
- No configured gate may force `ExecutionPlan::requiresRequest()` or `requiresScope()` to change.

### 4.3 Lifecycle and concurrency

- The gate is selected once during kernel/process boot, not discovered or resolved per request.
- The gate and its attached state must be immutable or explicitly concurrency-safe.
- Request-, user-, tenant-, native-handle-, and route-specific state must not be retained across executions.
- Fiber, coroutine, and persistent-worker executions must not cross-contaminate gate state.
- File-backed state may use a bounded worker-local refresh interval; it must not perform filesystem I/O for every request when the interval has not expired.

### 4.4 Artifacts and compatibility

- Compiled route and release artifact formats remain unchanged unless measurements prove an artifact field is required.
- The gate is runtime composition, not route metadata.
- Existing applications that configure no gate retain their current source and runtime behavior.
- New constructor/factory parameters, if required, are optional and appended to preserve named and positional callers.
- Development, embedded, compiled, and prevalidated entry points execute the same gate semantics.

## 5. Contract selection rules

Do not freeze a public API before Phase 2 measurement. Prototype the smallest candidates behind tests, then retain only the measured winner.

### Candidate A — single callable slot

An optional boot-held callable with the conceptual shape:

```text
(RoutingInput) -> ?Response
```

`null` means continue; `Response` means short-circuit. This is the minimum call-hop design.

### Candidate B — single interface slot

An optional cohesive contract with the conceptual shape:

```text
PreRoutingGateInterface::evaluate(RoutingInput): ?Response
```

Retain this only if the explicit public substitution boundary improves integration and its sustained throughput is practically equivalent to Candidate A.

### Candidate C — runtime-context argument

Passing `RuntimeRequestContext` is not the default because it exposes lazy request materialization and native handles. Consider it only if a demonstrated Webrick-owned use case requires runtime capabilities and cannot be served by `RoutingInput` without a new allocation. It must still prevent access to a full `Request` on the maintenance path.

### Rejected shapes

- A list or iterable of gates.
- A `next` closure resembling middleware.
- A mutable decision/event object.
- An enum plus payload wrapper when `null|Response` is sufficient.
- Container tags or runtime service discovery.
- A new context DTO that merely copies `RoutingInput`.

## 6. Intended execution placement

```text
runtime adapter creates RuntimeRequestContext
  -> canonical RoutingInput is available
  -> optional pre-routing gate evaluates once
       -> Response: normalize HEAD semantics and return
       -> null: continue unchanged
  -> compiled matcher
  -> execution-plan selection
  -> Request materialization only when required
  -> request scope only when required
  -> dispatch
  -> runtime adapter writes exactly once
```

The implementation should have one central evaluation method used by both `CompiledRouterKernel::handle()` and `CompiledRouterKernel::handleRuntime()`. `RuntimeServer` remains responsible only for context creation, kernel invocation, and adapter-owned response writing.

Gate exceptions follow the existing application-exception rendering path. Routing-control rendering remains reserved for matching outcomes and is not used for gate failures.

## 7. Maintenance integration design

The maintenance adapter should reuse the existing `MaintenanceStateInterface` contract and response policy rather than duplicating sentinel parsing.

Required behavior:

- `message() === null`: pass immediately.
- Non-null message: return the same `503` payload, `Retry-After`, and content type semantics as `MaintenanceModeMiddleware`.
- Emit `Cache-Control: no-store` if current Webrick maintenance-response policy requires it; establish parity in tests before changing existing behavior.
- Preserve the default non-empty fallback maintenance message.
- Reject negative retry intervals and empty content types at construction.
- Allow health/metrics bypass policy to be decided from normalized `RoutingInput` without matching routes or constructing `Request`.
- Keep bypass configuration immutable, explicit, and bounded. Do not introduce glob/regex policy unless an existing Webrick path matcher can be reused without turning the gate into a second router.

After WB-5 is accepted, `MaintenanceModeMiddleware` remains supported for ordinary middleware use. The pre-routing maintenance adapter is an opt-in production optimization, not an implicit behavior change.

## 8. Implementation phases

### Phase 0 — Freeze evidence and semantics

- [ ] Save the Foundation Phase 6 JSON result and exact benchmark command as a fixture/reference artifact.
- [ ] Record PHP version, extensions, non-default INI values, OPcache mode, CPU, memory, operating system, runtime adapter, operation counts, warmups, repetitions, and variance.
- [ ] Add parity fixtures for serving normally, maintenance active, custom message, retry header, content type, bypass, `GET`, `HEAD`, `OPTIONS`, malformed host, and gate exception.
- [ ] Count `Request` materializations, request-scope entries, state reads, response writes, failures, and invalid responses.
- [ ] Set acceptance budgets before candidate measurement.

Exit: the existing behavior and performance evidence are reproducible without WB-5 code.

### Phase 1 — Add focused instrumentation

- [ ] Extend opt-in runtime-stage profiling with `pre_routing_gate` timing.
- [ ] Keep profiling absent from the normal hot path unless a profiler is explicitly supplied.
- [ ] Add test-only counters through fixtures rather than production globals or static state.
- [ ] Verify instrumentation does not alter response or lifecycle semantics.

Exit: gate, match, request materialization, scope, dispatch, and write costs can be separated.

### Phase 2 — Prototype and select the contract

- [ ] Prototype Candidate A and Candidate B without publishing either contract.
- [ ] Benchmark no gate, pass, and short-circuit decisions with identical validated output.
- [ ] Measure cold first call and warm repeated calls.
- [ ] Measure a direct zero-argument route and a route that genuinely requires `Request`.
- [ ] Retain the simplest candidate with the highest median sustained throughput.
- [ ] Remove the losing prototype completely.
- [ ] Document why a public interface is or is not justified.

Exit: one contract is selected from evidence, with no parallel implementation left behind.

### Phase 3 — Integrate the compiled kernel

- [ ] Add one optional boot-selected gate to compiled and prevalidated kernel construction.
- [ ] Evaluate it after canonical routing input and before matching.
- [ ] Share evaluation logic between embedded and native-runtime entry points.
- [ ] Preserve the exact no-gate control flow except for the cheapest unavoidable null check.
- [ ] Ensure pass and short-circuit outcomes materialize no `Request` and enter no request scope.
- [ ] Preserve `HEAD` and native adapter write semantics.
- [ ] Mark the optional profiler stage only when profiling is enabled.

Exit: compiled runtime supports one gate with requestless pass and short-circuit paths.

### Phase 4 — Add the Webrick maintenance adapter

- [ ] Reuse `MaintenanceStateInterface` and a single shared response-construction policy.
- [ ] Prevent semantic drift between middleware and pre-routing maintenance responses.
- [ ] Support `FileMaintenanceState` bounded refresh and explicit in-memory/control-plane state.
- [ ] Add explicit bounded bypass support required for health and metrics endpoints.
- [ ] Prove concurrent and persistent-runtime isolation.
- [ ] Do not add configuration parsing to Webrick core.

Exit: Foundation can compose maintenance state into WB-5 without a Foundation routing abstraction.

### Phase 5 — Correctness and compatibility matrix

- [ ] Test no gate, pass, short-circuit, and exception paths.
- [ ] Test direct zero-argument, route-argument, compiled-invoke, and middleware execution plans.
- [ ] Test domain and non-domain routing.
- [ ] Test `GET`, `POST`, method override, `HEAD`, automatic `OPTIONS`, `404`, and `405` behavior.
- [ ] Test custom application exception rendering and default routing-control rendering.
- [ ] Test SAPI, RoadRunner, Workerman, and Swoole adapters where their runtime dependencies are available.
- [ ] Always run Fiber interleaving isolation tests; record unavailable native runtimes as conditional rather than silently passing them.
- [ ] Assert exactly one response write per execution.
- [ ] Assert no artifact captures the gate or live state object.
- [ ] Assert no-gate construction remains source compatible.

Exit: WB-5 changes only the explicitly configured pre-routing decision and preserves all other HTTP semantics.

### Phase 6 — Component benchmarks

- [ ] Add a stable PHPBench benchmark beside `KernelDispatchBench` covering:
  - no gate;
  - passing callable/interface candidate;
  - maintenance configured while serving normally;
  - maintenance active and short-circuiting;
  - equivalent existing global maintenance middleware;
  - direct requestless route;
  - request-requiring route.
- [ ] Validate status, headers, body, and write count outside timed loops.
- [ ] Run each scenario with isolated peak-memory accounting.
- [ ] Report median time, throughput, variance, allocations/materializations, state reads, and peak/steady memory.
- [ ] Reject candidate differences within measurement noise.

Exit: the selected gate materially reduces configured-but-inactive maintenance cost and does not materially regress the no-gate path.

### Phase 7 — Representative throughput validation

- [ ] Integrate the Webrick candidate into Foundation without a compatibility wrapper.
- [ ] Run the unchanged representative Apache/Nginx + PHP-FPM + OPcache workloads.
- [ ] Run persistent-worker workloads supported by the benchmark environment.
- [ ] Measure several concurrency levels through saturation and queue growth.
- [ ] Record successful RPS/RPM, failures, timeouts, response-validation failures, p50/p95/p99, CPU, peak/steady memory, worker utilization, queue depth, and downstream activity.
- [ ] Compare no maintenance integration, configured/inactive maintenance, and active maintenance.
- [ ] Use at least five steady-state repetitions and compare medians and variance.
- [ ] Confirm the load generator and network path are not limiting throughput.

Exit: production-equivalent evidence shows a sustained successful-RPM improvement for the target workload without correctness or stability loss.

### Phase 8 — Documentation and release

- [ ] Document the contract, lifecycle, thread/coroutine safety, bypass behavior, and middleware distinction.
- [ ] Add a migration example showing Foundation runtime composition.
- [ ] State clearly that request-dependent policies remain middleware.
- [ ] Run `composer ic:process`.
- [ ] Run `composer ic:tests:details`.
- [ ] Run `composer ic:release:guard`.
- [ ] Review the final diff for accidental artifact, middleware, or adapter API changes.
- [ ] Release only after Foundation consumes the candidate without a workaround.

Exit: implementation, evidence, documentation, Foundation integration, and all PHPForge gates are complete.

## 9. Fixed acceptance budgets

These thresholds must not be loosened to make a candidate pass. If the baseline variance makes a threshold inconclusive, improve the benchmark and rerun it.

### Correctness and lifecycle

- Zero invalid, partial, double-written, incorrectly headed, or semantically changed responses.
- Zero full `Request` materializations for a passing or short-circuiting maintenance gate on a requestless route.
- Zero `webrick.request` scope entries for those same paths.
- Exactly one maintenance-state read per gate evaluation, subject to the state's internal bounded-refresh cache.
- Exactly one native response write per request.
- Zero cross-request state leakage in interleaved execution and soak tests.

### Performance

- No configured gate: median representative successful RPM regression must be no more than 1% and must remain within observed noise.
- Configured/inactive maintenance: at least 25% lower median component-path time than equivalent global maintenance middleware.
- Configured/inactive maintenance: at least 10% higher median successful RPM in the representative workload where the earlier regression reproduces.
- Active maintenance: no lower successful response throughput than existing middleware, with identical validated `503` output.
- Memory, queue depth, error rate, timeout rate, and worker utilization remain bounded during steady state and soak runs.

Failure of any correctness, lifecycle, stability, or public-contract criterion rejects the candidate regardless of throughput.

## 10. Expected file ownership

Likely owners, subject to the measured contract selection:

- `src/Router/Kernel/CompiledRouterKernel.php` — central gate placement.
- `src/Router/Runtime/RoutingInput.php` — existing immutable input; no expansion unless correctness requires it.
- `src/Runtime/Http/RuntimeRequestContext.php` — request-materialization boundary; preferably unchanged.
- `src/Runtime/Http/RuntimeServer.php` — response-write ownership; preferably unchanged.
- `src/Middleware/Maintenance/*` — shared maintenance state/response policy.
- `src/Router/Runtime/RuntimeStageProfiler.php` — opt-in stage timing.
- `tests/Unit/HttpRuntimeRecoveryTest.php` — runtime-path parity.
- New focused unit/integration tests for gate lifecycle and runtime adapters.
- New PHPBench class beside `benchmarks/KernelDispatchBench.php`.
- `docs/middleware/maintenance-mode.rst` and runtime/performance documentation.

Do not modify matcher algorithms, execution-plan encoding, route artifact codecs, or runtime adapters unless a failing correctness test or profile demonstrates that WB-5 requires it.

## 11. Release sequencing

1. Release the independent SAPI stream-producer correction as a Webrick patch.
2. Implement and validate WB-5 on the next Webrick minor line if it introduces a public optional contract.
3. Update Foundation to consume the released Webrick capability directly.
4. Rerun Foundation's Phase 6 component benchmark and Phase 9 representative workloads.
5. Remove the global maintenance middleware from Foundation's compiled production path only after semantic and operational parity is proven.

The SAPI streaming correction and WB-5 must remain separate changes: one is a response-emission correctness fix; the other is an evidence-driven runtime optimization.

## 12. Definition of done

WB-5 is complete only when:

- one optional Webrick-owned gate exists with no alternative implementation;
- Foundation contains no pre-routing compatibility layer;
- configured inactive and active maintenance paths avoid full `Request` materialization and request-scope entry;
- no-gate, routing-control, exception, middleware, `HEAD`, and runtime-adapter semantics remain correct;
- component and production-equivalent benchmarks satisfy the fixed budgets;
- persistent/concurrent execution is isolated and soak-stable;
- documentation and migration guidance are complete;
- all PHPForge process, detailed-test, and release guards pass;
- and a released Webrick version is consumed by Foundation through normal Composer resolution.

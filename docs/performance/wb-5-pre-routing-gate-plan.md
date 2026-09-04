# Webrick WB-5 — Benchmark-Driven Pre-Routing Gate Plan

## Status and ownership

- Status: Webrick implementation complete; repository validation and Foundation/release acceptance remain pending.
- Owner: Webrick.
- Primary consumer: Foundation 3.
- Scope: compiled production runtime first; development/embedded parity is required before release.
- Release intent: additive Webrick minor capability.

WB-5 belongs in Webrick because Webrick owns `RoutingInput`, compiled matching, request materialization, request-scope entry, runtime adapters, and response writing. Foundation may configure a gate and provide maintenance state, but it must not duplicate Webrick's routing or runtime abstractions.

### 2026-09-04 implementation checkpoint

Webrick-side implementation is present on `wb5/performance-hot`.

Completed design/implementation decisions:

- Candidate B was retained as the single public contract: `PreRoutingGateInterface::evaluate(RoutingInput): ?Response`.
- An isolated PHP 8.4 + OPcache call-hop measurement favored the direct interface call over the callable candidates in the measured environment (median approximately 13.3 ns/op for the interface versus approximately 16.4–16.5 ns/op for the callable forms across five 5,000,000-call repetitions). This is contract-selection evidence only, not an application-throughput claim.
- `CompiledRouterKernel::fromCompiledArtifact()` and `fromPrevalidatedArtifact()` accept one optional boot-held gate and evaluate it after canonical `RoutingInput` creation and before matching.
- `CompiledRouterKernel::handleRuntime()` preserves requestless pass and short-circuit execution; focused integration tests assert zero lazy `Request` materializations for those paths.
- `RouterKernel` accepts the same contract for development/registrar semantic parity.
- `MaintenancePreRoutingGate` reuses `MaintenanceStateInterface`, supports bounded exact canonical path bypasses, and uses the same maintenance response policy as `MaintenanceModeMiddleware`.
- `HEAD` short-circuit responses preserve headers/content length while emitting no body.
- Fiber isolation is covered by focused tests; no request/user/tenant/native-handle state is retained by the gate.
- Route artifacts, matcher algorithms, execution-plan encoding, `RuntimeRequestContext`, `RuntimeServer`, and native runtime adapters were not changed for WB-5.
- A dedicated `PreRoutingGateBench` PHPBench fixture is present beside `KernelDispatchBench`.
- Maintenance documentation now distinguishes full-request middleware from the requestless compiled/persistent gate and documents migration/bypass/lifecycle rules.
- Webrick now requires `infocyph/intermix:^10.0.4`.

Repository validation note:

- This branch has no normal push CI workflow. The repository's `Security & Standards` workflow runs on pushes to `main`/`master`, pull requests targeting the listed integration branches, and a weekly schedule. Therefore no branch Actions run exists to treat as a PHPForge pass.
- Source files assembled for WB-5 were PHP 8.4 syntax-checked locally; the complete Composer/PHPForge suite still must run in an environment with repository dependencies before release.

Still external/pending by definition of this plan:

- run the complete PHPForge process/detail/release guards;
- record stable full component-benchmark output and memory/counter evidence;
- integrate the released/candidate Webrick capability into Foundation without a compatibility layer;
- rerun Foundation's representative PHP-FPM and persistent-worker throughput workloads against the fixed budgets;
- release Webrick only after those acceptance gates pass and consume the released version through normal Composer resolution.

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

Candidate B is the retained implementation. Candidate A was measured as the simpler callable alternative; Candidate C remains rejected because it exposes runtime context/request materialization capability unnecessarily.

### Candidate A — single callable slot

An optional boot-held callable with the conceptual shape:

```text
(RoutingInput) -> ?Response
```

`null` means continue; `Response` means short-circuit.

### Candidate B — selected single interface slot

The retained public contract is:

```text
PreRoutingGateInterface::evaluate(RoutingInput): ?Response
```

The interface provides a precise host substitution boundary and was faster than the callable forms in the isolated PHP 8.4 call-hop measurement recorded above. No parallel callable implementation remains in Webrick.

### Candidate C — rejected runtime-context argument

Passing `RuntimeRequestContext` is rejected because it exposes lazy request materialization and native handles without a demonstrated Webrick-owned requirement. Maintenance only needs `RoutingInput` plus maintenance state.

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

The implementation uses the same central gate evaluation path from both `CompiledRouterKernel::handle()` and `CompiledRouterKernel::handleRuntime()`. `RuntimeServer` remains responsible only for context creation, kernel invocation, and adapter-owned response writing.

Gate exceptions follow the existing application-exception rendering path. Routing-control rendering remains reserved for matching outcomes and is not used for gate failures.

## 7. Maintenance integration design

The maintenance adapter reuses the existing `MaintenanceStateInterface` contract and a shared `MaintenanceResponsePolicy` rather than duplicating sentinel/response behavior.

Required/implemented behavior:

- `message() === null`: pass immediately.
- Non-null message: return the same core `503`, `Retry-After`, content type, no-store, nosniff, and `Vary` response semantics as the middleware path.
- Preserve the default non-empty fallback maintenance message.
- Reject negative retry intervals and empty content types at construction.
- Allow health/readiness bypass policy to be decided from normalized `RoutingInput` without matching routes or constructing `Request`.
- Keep bypass configuration immutable, exact, canonical, and bounded to 32 entries; no glob/regex second-router behavior.

`MaintenanceModeMiddleware` remains supported for ordinary middleware use. The pre-routing maintenance adapter is an opt-in production optimization, not an implicit behavior change.

## 8. Implementation phases

### Phase 0 — Freeze evidence and semantics

- [x] Foundation Phase 6 evidence and fixed thresholds are captured in this plan.
- [ ] Preserve the exact Foundation benchmark command/result artifact and full host metadata beside the consumer benchmark evidence.
- [x] Add focused parity fixtures for inactive/active state, custom message, retry header, content type, bypass, `GET`/`HEAD`, and requestless execution.
- [x] Count full `Request` materialization in the compiled-runtime integration fixture.
- [ ] Add/record explicit request-scope-entry, state-read, response-write, failure, and invalid-response counters in the final benchmark evidence.
- [x] Acceptance budgets were fixed before WB-5 acceptance.

Exit: core semantics/evidence are frozen; full consumer benchmark metadata remains a Foundation-side evidence task.

### Phase 1 — Add focused instrumentation

- [x] Add opt-in `pre_routing_gate` stage timing through the existing profiler hook.
- [x] Keep profiling absent from the normal hot path unless a profiler is explicitly supplied.
- [x] Use fixture-local counters instead of production globals/static state.
- [x] Keep instrumentation out of response/lifecycle semantics.

Exit: gate timing can be separated when profiling is enabled without new production-global instrumentation.

### Phase 2 — Prototype and select the contract

- [x] Measure callable and interface call-hop candidates on PHP 8.4 + OPcache.
- [x] Select Candidate B from the measured result and typed integration boundary.
- [x] Remove the losing callable prototype; only `PreRoutingGateInterface` remains.
- [x] Document the selection evidence and scope of that evidence.
- [ ] Record full integrated cold/warm no-gate/pass/short-circuit and request-requiring-route benchmark output before release.

Exit: one public contract is selected; full integrated benchmark evidence remains a release gate.

### Phase 3 — Integrate the compiled kernel

- [x] Add one optional boot-selected gate to compiled and prevalidated kernel construction.
- [x] Evaluate it after canonical routing input and before matching.
- [x] Share gate evaluation between embedded and native-runtime entry points.
- [x] Preserve the no-gate control flow except for the optional null/property check.
- [x] Ensure pass and short-circuit outcomes materialize no `Request` on requestless runtime paths.
- [x] Preserve `HEAD` and native adapter write ownership.
- [x] Mark the optional profiler stage only when profiling is enabled.

Exit: compiled runtime supports one gate with requestless pass and short-circuit paths.

### Phase 4 — Add the Webrick maintenance adapter

- [x] Reuse `MaintenanceStateInterface` and a single shared response-construction policy.
- [x] Prevent semantic drift between middleware and pre-routing maintenance responses.
- [x] Support `FileMaintenanceState` bounded refresh and explicit in-memory/control-plane state.
- [x] Add explicit bounded exact bypass support required for health/readiness endpoints.
- [x] Add Fiber isolation coverage and keep request-specific state out of the gate.
- [x] Do not add configuration parsing to Webrick core.

Exit: Foundation can compose maintenance state into WB-5 without a Foundation routing abstraction.

### Phase 5 — Correctness and compatibility matrix

- [x] Test pass and short-circuit paths in the compiled runtime.
- [x] Test active response semantics, exact bypass behavior, `HEAD`, and Fiber isolation.
- [x] Preserve existing no-gate/routing-control/middleware execution code and keep new construction parameters optional/appended.
- [x] Keep gate/live state out of route artifacts.
- [ ] Run the repository's complete existing unit/integration matrix with dependencies installed.
- [ ] Record explicit gate-exception, method-override, automatic `OPTIONS`, `404`, `405`, domain-routing, request-requiring execution-plan, and custom-error-renderer cases in the final WB-5 validation run.
- [ ] Run SAPI/RoadRunner/Workerman/Swoole adapter suites where runtime dependencies are available and record unavailable runtimes as conditional.
- [ ] Record exactly-one-native-write and long-running soak evidence.

Exit: implementation preserves the architecture; the complete release validation matrix remains mandatory before tagging.

### Phase 6 — Component benchmarks

- [x] Add `benchmarks/PreRoutingGateBench.php` beside `KernelDispatchBench.php`.
- [x] Include inactive gate, active gate, and equivalent inactive maintenance middleware component subjects.
- [ ] Extend/finalize integrated benchmark subjects for no gate, requestless direct route, and request-requiring route where needed by the fixed budgets.
- [ ] Validate all benchmark outputs outside timed loops and record write/state/materialization counters.
- [ ] Run isolated peak-memory accounting.
- [ ] Record median time, throughput, variance, allocations/materializations, state reads, and peak/steady memory.
- [ ] Reject candidate differences within measurement noise.

Exit: benchmark fixture exists; measured acceptance remains a release gate, not an inferred result.

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

- [x] Document the contract, lifecycle, concurrency expectations, bypass behavior, and middleware distinction.
- [x] Add migration/runtime-composition guidance for Foundation-style compiled runtimes.
- [x] State clearly that request-dependent policies remain middleware/gateway concerns.
- [ ] Run `composer ic:process`.
- [ ] Run the repository's configured detailed PHPForge test command.
- [ ] Run `composer ic:release:guard`.
- [ ] Review the final release diff for accidental artifact, middleware, or adapter API changes.
- [ ] Release only after Foundation consumes the candidate without a workaround and the fixed acceptance budgets pass.

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

WB-5 implementation ownership:

- `src/Router/Kernel/CompiledRouterKernel.php` — central gate placement.
- `src/Router/Kernel/RouterKernel.php` — development/registrar semantic parity.
- `src/Router/Runtime/PreRoutingGateInterface.php` — selected gate contract.
- `src/Router/Runtime/RoutingInput.php` — existing immutable input; unchanged.
- `src/Runtime/Http/RuntimeRequestContext.php` — request-materialization boundary; unchanged.
- `src/Runtime/Http/RuntimeServer.php` — response-write ownership; unchanged.
- `src/Middleware/Maintenance/MaintenancePreRoutingGate.php` — requestless maintenance adapter.
- `src/Middleware/Maintenance/MaintenanceResponsePolicy.php` — shared response policy.
- `src/Middleware/MaintenanceModeMiddleware.php` — existing middleware reusing the shared policy.
- `tests/Unit/PreRoutingGateTest.php` and `tests/Integration/PreRoutingGateIntegrationTest.php` — focused lifecycle/runtime coverage.
- `benchmarks/PreRoutingGateBench.php` — WB-5 component benchmark fixture.
- `docs/middleware/maintenance-mode.rst` — lifecycle, migration, bypass, and middleware distinction.

Do not modify matcher algorithms, execution-plan encoding, route artifact codecs, or runtime adapters unless a failing correctness test or profile demonstrates that WB-5 requires it.

## 11. Release sequencing

1. Keep the independent SAPI stream-producer correction separate from WB-5.
2. Complete Webrick's full dependency-backed validation and component evidence on this branch.
3. Update Foundation to consume the Webrick capability directly without a compatibility wrapper.
4. Rerun Foundation's Phase 6 component benchmark and representative workloads against the fixed budgets.
5. Remove the global maintenance middleware from Foundation's compiled production path only after semantic and operational parity is proven.
6. Release Webrick and consume that released version through normal Composer resolution.

The SAPI streaming correction and WB-5 remain separate changes: one is a response-emission correctness fix; the other is an evidence-driven runtime optimization.

## 12. Definition of done

WB-5 is complete only when:

- [x] one optional Webrick-owned gate exists with no alternative implementation;
- [ ] Foundation contains no pre-routing compatibility layer and consumes the Webrick gate directly;
- [x] focused compiled-runtime tests prove configured inactive and active maintenance paths avoid full `Request` materialization on requestless routes;
- [ ] the complete no-gate, routing-control, exception, middleware, `HEAD`, and runtime-adapter release matrix passes;
- [ ] component and production-equivalent benchmarks satisfy the fixed budgets;
- [ ] persistent/concurrent execution is isolated and soak-stable across the supported release runtimes;
- [x] documentation and migration guidance are complete;
- [ ] all PHPForge process, detailed-test, and release guards pass;
- [ ] a released Webrick version is consumed by Foundation through normal Composer resolution.

The Webrick-owned implementation is ready for dependency-backed validation and Foundation consumption. The unchecked items above are acceptance/release evidence, not additional runtime architecture to invent.

# Webrick 5 — Runwire Runtime Adapter Progress Tracker

Canonical plan: `docs/plans/webrick-5-runwire-native-runtime-adapter-plan.md`

Branch: `webrick-5/runwire-runtime-adapter`

Pull request: #52

Released integration baselines:

- Runwire `^1.0` for optional development/integration coverage;
- InterMix `^10.1.1` as the Webrick production baseline;
- ordinary SAPI/shared-hosting Webrick remains independent of Runwire.

> **Point 15 correction:** the canonical plan's Pathwise-composition wording is invalid for Webrick. Webrick has no Pathwise integration contract or dependency. Point 15 is closed by removing that erroneous coupling; Webrick retains only its own HTTP file/range response semantics.

## Implementation tracker

- [x] **1. InterMix baseline** — raised the production dependency to `^10.1.1`.
- [x] **2. Existing adapter regression freeze** — SAPI, Workerman, Swoole/OpenSwoole and RoadRunner contracts are covered.
- [x] **3. Optional Runwire dependency** — released Runwire `^1.0` is available to development/integration coverage without becoming a Webrick production requirement.
- [x] **4. InterMix scope bridge** — `InterMixRuntime` exposes `captureScopeContext()`, `withinScopeContext()` and `resetCurrentExecutionScope()` directly from InterMix 10.1.1.
- [x] **5. Runtime-neutral request bridge** — `RuntimeRequestContext` can carry a Webrick `RuntimeRequestExecution` view for request identity, monotonic timing and live cancellation observation; `RuntimeCapabilities` exposes only the request-streaming/cancellation/drain facts required by upcoming adapters.
- [x] **6. Runwire runtime adapter** — `RunwireRuntimeAdapter` maps released Runwire `HttpRequest` / `ResponseWriterInterface` contracts onto Webrick's existing runtime seam, keeps full request/body materialization lazy, preserves header multiplicity and request metadata, observes native cancellation live, owns response completion exactly once, and detects writer pressure without introducing a second output queue.
- [x] **7. Runwire application bridge** — `RunwireRuntimeApplication` and `RunwireRuntimeApplicationFactory` compose Webrick request handling with Runwire's released `RuntimeApplicationInterface` / `RuntimeApplicationFactoryInterface` contracts while delegating lifecycle state, admission, cancellation, metrics, resetters and shutdown ordering to Runwire.
- [x] **8. HTTP/1.1 parity** — full compiled-kernel acceptance proves request/response normalization, routing controls, application errors, streaming and exactly-once completion through the Runwire application bridge.
- [x] **9. HTTP/2 isolation** — full application acceptance proves normalization, concurrent/interleaved request isolation and per-request cancellation without Webrick-owned stream state.
- [x] **10. HTTP/3 isolation** — full application acceptance proves HTTP/3 request/stream isolation at the Runwire application boundary and treats native QUIC availability as an optional runtime capability rather than a skipped test.
- [x] **11. Bounded streaming/backpressure** — request bodies remain incrementally readable and `RunwireResponseContinuation` now suspends Webrick response production on writer pressure until Runwire's `onDrain()` resumes it; no duplicate output queue is introduced.
- [x] **12. Application body-production lifetime** — runtime response writing now occurs through the compiled-kernel response consumer while the owning request scope is still active, so lazy/streamed bodies retain request-scoped services only until production finishes.
- [x] **13. Structured child propagation** — explicit InterMix `ScopeContext` propagation is proven through Runwire structured task locals while unpropagated child work remains isolated and zero-scope routes retain their no-scope path.
- [x] **14. Exactly-once cleanup** — success, application error, transport cancellation and deadline expiry all prove one request-scope leave, one Runwire cleanup/reset callback, completed Runwire request context, zero active-request residue and a clean subsequent request.
- [x] **15. Invalid Pathwise coupling removed** — no Pathwise dependency, responder, test or runtime contract remains in Webrick. Filesystem trust is outside this integration; Webrick continues to own only its existing HTTP file/range response semantics.
- [ ] **16. Deployment acceptance** — cover Runwire portable/prefork and host drivers while preserving generic SAPI/shared-hosting behavior.
- [ ] **17. Foundation bridge fixture** — validate the Framework integration contract.
- [ ] **18. Infbyte end-to-end acceptance** — validate request-scoped and persistent-runtime behavior in the application.
- [ ] **19. Performance validation** — measure adapter/scope/streaming overhead and tune only demonstrated regressions.
- [ ] **20. Release documentation and handoff** — finalize Webrick 5 documentation and Foundation integration guidance.

## Points 11–12 acceptance

- `RunwireResponseContinuation` is deliberately narrow and Runwire-specific: it only manages response-production Fibers used by the optional Runwire bridge and is not a general Webrick scheduler.
- `RunwireRuntimeAdapter` treats accepted-but-pressured `start()` / `write()` results as a suspension point and resumes only from Runwire's released `onDrain()` callback.
- No second Webrick buffering/output queue is introduced; Runwire remains authoritative for transport pressure and drain signaling.
- Request-body adaptation remains incremental. Focused coverage reads only the requested body prefix and proves the remaining body stays unread/buffered in the Runwire body implementation.
- Runtime response consumption moved inside the compiled kernel's request-scope lifetime. This is required because a lazy/streamed response may resolve scoped services after the route handler itself has returned.
- Response-write failures are carried out of the kernel without being re-rendered as a second application response, so the adapter never recursively writes an error after partial response output.
- A scoped streaming response remains inside the request scope while suspended on transport pressure, resumes in the same scope, completes production, then leaves the scope exactly once.
- A zero-scope streaming route still does not open/capture an InterMix request scope.

## Point 13 acceptance

- The Webrick InterMix wrapper continues to delegate logical scope ownership entirely to InterMix.
- Explicit `ScopeContext` propagation through Runwire `TaskLocal` inheritance allows structured child work to resolve the same scoped instance as its owning request only when attachment is explicit.
- Two attached structured children share the intended owning scope identity.
- An unpropagated child cannot capture the parent request scope and remains isolated by default.
- Child failures detach propagated context in `finally`, allowing later children to attach the same live owner context correctly.
- Independent Fiber scopes remain isolated without explicit propagation.
- `resetCurrentExecutionScope()` remains an idempotent carrier-local defensive reset and does not become a request-ID keyed Webrick scope store.

## Point 14 acceptance

- `RunwireCleanupIsolationTest` composes the real `RunwireRuntimeApplication` → `RuntimeServer` → compiled kernel path with the same InterMix container used for request dispatch and cleanup.
- Normal success leaves the Webrick request scope once, executes the Runwire request cleanup callback once, completes the Runwire request context and leaves no current-carrier InterMix scope.
- Application exceptions still perform the same one-time scope leave and cleanup/reset, then leave the next request clean.
- Transport cancellation during response production stops further output, completes lifecycle cleanup, leaves the scope once and does not call response `end()` after cancellation.
- Deadline expiry is observed through Runwire's authoritative request context; it stops response production and executes the same deterministic cleanup path.
- A subsequent successful request after all failure modes proves no scoped state, cancellation state or active-request accounting leaks forward.
- `resetCurrentExecutionScope()` is invoked from Runwire's existing request-cleanup slot rather than by adding another Webrick lifecycle engine.

## Point 15 correction

The original canonical-plan wording incorrectly said Webrick should compose a trusted static/public-asset boundary with Pathwise. That is not an actual Webrick architecture or dependency relationship.

Correction applied:

- `infocyph/pathwise` is **not** a Webrick dependency or dev dependency;
- Webrick exposes no Pathwise-specific responder or adapter;
- no Pathwise API/type is referenced by Webrick runtime code or tests;
- Webrick's existing `Response::rangedFile()` / `Response::rangedDownload()` and runtime writer behavior remain the Webrick-owned HTTP file semantics;
- filesystem/public-root trust policy belongs to the consuming application/framework/host layer that selects an already-authorized file path, not to a new Webrick↔Pathwise integration contract.

This tracker correction supersedes the stale Pathwise-specific wording in Point 15 of the large canonical plan for this branch. The canonical plan can be mechanically cleaned during the final documentation pass without reintroducing any code/dependency coupling.

## Earlier acceptance summary

### Point 6

- Runwire-specific production types are confined to the optional adapter/body bridge and are not loaded by ordinary SAPI/shared-hosting use.
- `RunwireRequestBodyStream` is read-only and non-seekable, delegates incremental reads to Runwire, and does not force body buffering during routing or request creation.
- Request normalization covers target/path/query, host, protocol version, encrypted state, peer/local endpoint metadata, cookies, duplicate headers, request identity, deadline metadata and live cancellation observation.
- Response normalization reuses `ResponseWriterSupport` for Webrick body/header semantics, filters HTTP/2+ connection-specific headers, preserves duplicate response fields, and uses `start()` / `write()` / `end()` without duplicating protocol logic.

### Point 7

- `RunwireRuntimeApplication` delegates to Runwire's own host `RuntimeApplication` / `ApplicationLifecycle`; Webrick does not add another lifecycle state machine.
- Host drivers may request `completeResponse: true`, but the Webrick bridge neutralizes that flag so Webrick remains the only response-completion owner.
- Boot, warmup, request cleanup, drain, shutdown hooks, legacy shutdown and runtime metrics flow through Runwire lifecycle primitives.

### Point 8

- `RunwireHttp1ParityTest` exercises `RunwireRuntimeApplication` → `RuntimeServer` → `RunwireRuntimeAdapter` → `CompiledRouterKernel` → Runwire `ResponseWriterInterface`.
- Sequential HTTP/1.1 requests prove request normalization, response semantics, HEAD, redirect, 404, 405, exception rendering, streaming order and exactly-once completion without state leakage.

### Points 9–10

- HTTP/2 and HTTP/3 acceptance use the same composed application path with genuinely interleaved Fiber execution.
- Request-local state remains isolated across multiplexed requests and stream completion order.
- Per-request H2 cancellation does not affect a sibling request.
- Webrick introduces no H2/H3 stream registry or QUIC implementation.
- Native QUIC is treated as an optional Runwire capability; absence of ext-quic is explicitly accepted rather than hidden behind a skipped test.

## QA resolution

The Points 11–15 completion pass also resolved all defects exposed by the current PR quality matrix rather than suppressing checks:

- corrected `RunwireResponseContinuation` WeakMap static-analysis typing while retaining runtime Fiber-only registration;
- corrected the scoped streaming fixture so its `Request`-seed assertion is backed by explicit `Request` injection rather than assuming a requestless route creates one;
- removed the unintended Pathwise code/dependency/test after re-validating the actual Webrick architecture.

Corrected implementation head `b5dbc6a862fece9219e57069b0c0a7fe50c343d3` passed **Security & Standards #898** completely:

- clean production `--no-dev` install/autoload: passed;
- PHP 8.4 analysis: passed;
- PHP 8.5 analysis: passed;
- PHP 8.4 prefer-lowest QA: passed;
- PHP 8.4 prefer-stable QA: passed;
- PHP 8.5 prefer-lowest QA: passed;
- PHP 8.5 prefer-stable QA: passed;
- exact PHPForge detail diagnostics: passed;
- PHP 8.4 benchmark job: passed;
- PHP 8.5 benchmark job: passed.

## Preserved invariants

- Runwire remains optional for ordinary Webrick consumers.
- Existing direct runtime adapters remain first-class and are not proxied through Runwire.
- The compiled zero-scope route path does not materialize `Request`, create an InterMix scope, or allocate runtime execution metadata unless required.
- Runwire remains authoritative for lower-runtime cancellation/deadlines; Webrick only exposes a read-only observation view.
- Runwire remains authoritative for host/application lifecycle state; Webrick only composes its request handler into that lifecycle.
- Runwire remains authoritative for HTTP/2/HTTP/3 stream scheduling and QUIC transport capability; Webrick carries no duplicate protocol stream registry.
- InterMix remains authoritative for logical DI scope identity and cleanup.
- Webrick has no Pathwise coupling.
- Webrick does not introduce a scheduler, socket server, process supervisor, protocol stack, duplicate output queue, duplicate lifecycle engine, or filesystem trust subsystem.

## Current next item

**Point 16 — Runwire native portable/prefork and host-driver deployment acceptance while preserving generic SAPI/shared-hosting behavior.**

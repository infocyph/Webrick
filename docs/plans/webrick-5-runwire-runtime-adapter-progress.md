# Webrick 5 — Runwire Runtime Adapter Progress Tracker

Canonical plan: `docs/plans/webrick-5-runwire-native-runtime-adapter-plan.md`

Branch: `webrick-5/runwire-runtime-adapter`

Pull request: #52

Released integration baselines:

- Runwire `^1.0` for optional development/integration coverage;
- InterMix `^10.1.1` as the Webrick production baseline;
- ordinary SAPI/shared-hosting Webrick remains independent of Runwire.

## Implementation tracker

- [x] **1. InterMix baseline** — raised the production dependency to `^10.1.1`.
- [x] **2. Existing adapter regression freeze** — SAPI, Workerman, Swoole/OpenSwoole and RoadRunner contracts are covered.
- [x] **3. Optional Runwire dependency** — released Runwire `^1.0` is available to development/integration coverage without becoming a Webrick production requirement.
- [x] **4. InterMix scope bridge** — `InterMixRuntime` exposes `captureScopeContext()`, `withinScopeContext()` and `resetCurrentExecutionScope()` directly from InterMix 10.1.1.
- [x] **5. Runtime-neutral request bridge** — `RuntimeRequestContext` can carry a Webrick `RuntimeRequestExecution` view for request identity, monotonic timing and live cancellation observation; `RuntimeCapabilities` exposes only the request-streaming/cancellation/drain facts required by upcoming adapters.
- [x] **6. Runwire runtime adapter** — `RunwireRuntimeAdapter` now maps released Runwire `HttpRequest` / `ResponseWriterInterface` contracts onto Webrick's existing runtime seam, keeps full request/body materialization lazy, preserves header multiplicity and request metadata, observes native cancellation live, owns response completion exactly once, and detects writer pressure without introducing a second output queue.
- [x] **7. Runwire application bridge** — `RunwireRuntimeApplication` and `RunwireRuntimeApplicationFactory` compose Webrick request handling with Runwire's released `RuntimeApplicationInterface` / `RuntimeApplicationFactoryInterface` contracts while delegating lifecycle state, admission, cancellation, metrics, resetters and shutdown ordering to Runwire.
- [x] **8. HTTP/1.1 parity** — full compiled-kernel acceptance proves request/response normalization, routing controls, application errors, streaming and exactly-once completion through the Runwire application bridge.
- [x] **9. HTTP/2 isolation** — full application acceptance proves normalization, concurrent/interleaved request isolation and per-request cancellation without Webrick-owned stream state.
- [x] **10. HTTP/3 isolation** — full application acceptance proves HTTP/3 request/stream isolation at the Runwire application boundary and treats native QUIC availability as an optional runtime capability rather than a skipped test.
- [ ] **11. Bounded streaming/backpressure** — preserve incremental request bodies and replace Point 6's explicit pressure stop with proper writer-drain continuation without unbounded buffering.
- [ ] **12. Application body-production lifetime** — keep request scope alive only while lazy/streaming application production still needs it.
- [ ] **13. Structured child propagation** — integrate explicit InterMix `ScopeContext` propagation with Runwire structured work while retaining zero-scope routes.
- [ ] **14. Exactly-once cleanup** — prove success/error/cancellation/deadline cleanup and carrier reset.
- [ ] **15. Trusted static/public assets** — compose Webrick HTTP file semantics with Pathwise trust boundaries.
- [ ] **16. Deployment acceptance** — cover Runwire portable/prefork and host drivers while preserving generic SAPI/shared-hosting behavior.
- [ ] **17. Foundation bridge fixture** — validate the Framework integration contract.
- [ ] **18. Infbyte end-to-end acceptance** — validate request-scoped and persistent-runtime behavior in the application.
- [ ] **19. Performance validation** — measure adapter/scope/streaming overhead and tune only demonstrated regressions.
- [ ] **20. Release documentation and handoff** — finalize Webrick 5 documentation and Foundation integration guidance.

## Point 6 acceptance

- Runwire-specific production types are confined to the optional adapter/body bridge and are not loaded by ordinary SAPI/shared-hosting use.
- `RunwireRequestBodyStream` is read-only and non-seekable, delegates incremental reads to Runwire, and does not force body buffering during routing or request creation.
- Request normalization covers target/path/query, host, protocol version, encrypted state, peer/local endpoint metadata, cookies, duplicate headers, request identity, deadline metadata and live cancellation observation.
- Response normalization reuses `ResponseWriterSupport` for Webrick body/header semantics, filters HTTP/2+ connection-specific headers, preserves duplicate response fields, and uses `start()` / `write()` / `end()` without duplicating protocol logic.
- Webrick owns completion in this adapter; a later `ApplicationLifecycle` bridge must invoke Runwire handling with automatic completion disabled so there is never a second completion owner.
- `PRESSURED` writes are detected and stop application output rather than overrunning the writer. Asynchronous drain continuation remains intentionally assigned to Point 11.
- Point 6 implementation code passed Security & Standards workflow **#853** across clean production install, PHP 8.4/8.5 analysis, all stable/lowest QA matrices, exact PHPForge diagnostics and both benchmark jobs before this tracker update.

## Point 7 acceptance

- `RunwireRuntimeApplication` implements the released Runwire `RuntimeApplicationInterface` by delegating to Runwire's own `Runtime\Host\RuntimeApplication`, which in turn owns the released `ApplicationLifecycle`; Webrick does not add another lifecycle state machine.
- `RunwireRuntimeApplicationFactory` implements `RuntimeApplicationFactoryInterface`, so Webrick can be supplied directly to Runwire `Runtime::serveApplication()` with the runtime-selected `RuntimeContext`.
- Runwire remains authoritative for start, admission, active-request tracking, cancellation, metrics, lifecycle resetters, drain and shutdown sequencing. The bridge only supplies Webrick request handling plus optional released Runwire policies/hooks.
- Host drivers may request `completeResponse: true`, but the Webrick bridge explicitly neutralizes that flag and delegates internally with automatic completion disabled. `RunwireRuntimeAdapter` / the Webrick request handler therefore remains the one and only response-completion owner.
- Focused tests prove that a host completion request does not produce a second `end()`, while a Webrick-style handler that completes the writer still completes it exactly once.
- Focused factory/lifecycle coverage proves boot, warmup, request cleanup, drain, shutdown hooks, legacy shutdown and runtime metrics flow through Runwire's lifecycle rather than through Webrick-owned state.
- The clean `--no-dev` production install remains green, so these optional Runwire bridge classes do not make Runwire a production requirement for ordinary SAPI/shared-hosting consumers.
- Point 14 still owns exhaustive success/error/cancellation/deadline cleanup and carrier-reset acceptance; Point 7 establishes composition and ownership only.
- Point 7 implementation code passed Security & Standards workflow **#859** across clean production install, PHP 8.4/8.5 analysis, all stable/lowest QA matrices, exact PHPForge diagnostics and both benchmark jobs before this tracker update.

## Point 8 acceptance

- `RunwireHttp1ParityTest` exercises the actual composed path: `RunwireRuntimeApplication` → `RuntimeServer` → `RunwireRuntimeAdapter` → `CompiledRouterKernel` → Runwire `ResponseWriterInterface`.
- One started Runwire application serves seven sequential HTTP/1.1 requests, proving the persistent application bridge does not leak response state between requests.
- Request parity covers method, path/route arguments, query values, host, protocol version, peer/local endpoint metadata, duplicate request headers, cookies and lazy request-body consumption.
- Response parity covers status, content type/length, duplicate `Set-Cookie` fields and preservation of the HTTP/1.1 `Connection` header; the HTTP/2+ connection-field filtering rule therefore does not bleed into H1.
- Routing/application controls are verified end to end for `HEAD`, redirect, 404, 405 and application exceptions. The deterministic error renderer receives the routed request path and the original throwable message through the full bridge.
- Streaming response chunks preserve application order through the runtime adapter.
- Every accepted response starts exactly once and ends exactly once even when the Runwire host-facing call requests `completeResponse: true`, proving Point 7's completion-ownership rule under real Webrick dispatch rather than an isolated handler fixture.
- Runwire lifecycle metrics report seven total requests and zero active requests after the sequence.
- No production Webrick runtime change was required for Point 8; the Point 6 transport adapter and Point 7 application bridge compose correctly as designed.
- The first Point 8 CI pass already executed the full Pest acceptance successfully; it exposed only unused-parameter PHPCS warnings in the deterministic error fixture. The fixture was strengthened to assert the request path and throwable message rather than suppressing the warnings.
- Point 8 implementation code passed Security & Standards workflow **#866** across clean production install, PHP 8.4/8.5 analysis, all stable/lowest QA matrices, exact PHPForge diagnostics and both benchmark jobs before this tracker update.

## Points 9–10 acceptance

- `RunwireMultiplexedProtocolIsolationTest` exercises the same composed application path for both `ProtocolVersion::HTTP_2` and `ProtocolVersion::HTTP_3`; Webrick does not duplicate Runwire's wire-protocol parser, scheduler or stream registry.
- Each protocol test holds two real application requests active at the same time in independent PHP Fibers. Distinct path parameters, query values, request bodies, cookies, duplicate headers, hosts and peer addresses remain bound to the originating request before and after interleaved suspension/resumption.
- The HTTP/2 acceptance cancels only one request's released Runwire `RequestContext` with `TRANSPORT_CANCELLED`. The adjacent request remains uncancelled and completes normally; the cancelled response stops after its already-written first chunk, does not call `end()`, and Runwire lifecycle cleanup still returns the active-request count to zero.
- Runwire lifecycle metrics prove actual overlap: two requests are active concurrently, then one, then zero. No Webrick-global request/stream state is introduced to obtain that isolation.
- HTTP/2 and HTTP/3 responses both remove the HTTP/1.1-only `Connection` field while preserving duplicate `Set-Cookie` fields and request-local response metadata.
- HTTP/3 interleaving completes the two streams in reverse order and still preserves each stream's normalized request snapshot and response sequence.
- Native QUIC is treated as a portable optional capability. When `PhpQuicApi::available()` is true, its released availability assertion must succeed; when it is false, the test explicitly verifies the released `RuntimeUnavailableException` contract containing `HTTP/3 requires ext-quic`. No test is skipped merely because ext-quic is absent.
- The HTTP/3 application-boundary acceptance remains meaningful without ext-quic because Runwire owns QUIC transport/protocol implementation while Webrick consumes the normalized released `HttpRequest` / `ResponseWriterInterface` contract.
- No production Webrick runtime change was required for Points 9–10. The existing adapter/application bridge already preserves concurrent request isolation, live cancellation visibility and protocol-neutral response semantics.
- The first combined Points 9–10 CI pass exposed only a fixture serialization mistake (`array` concatenated into a stream chunk). The fixture was corrected by JSON-encoding the request-local snapshot before suspension; no runtime behavior was changed or suppressed.
- Combined Points 9–10 implementation code passed Security & Standards workflow **#870** across clean production install, PHP 8.4/8.5 analysis, all stable/lowest QA matrices, exact PHPForge diagnostics and both benchmark jobs before this tracker update.

## Preserved invariants

- Runwire remains optional for ordinary Webrick consumers.
- Existing direct runtime adapters remain first-class and are not proxied through Runwire.
- The compiled zero-scope route path does not materialize `Request`, create an InterMix scope, or allocate runtime execution metadata unless required.
- Runwire remains authoritative for lower-runtime cancellation/deadlines; Webrick only exposes a read-only observation view.
- Runwire remains authoritative for host/application lifecycle state; Webrick only composes its request handler into that lifecycle.
- Runwire remains authoritative for HTTP/2/HTTP/3 stream scheduling and QUIC transport capability; Webrick carries no duplicate protocol stream registry.
- InterMix remains authoritative for logical DI scope identity and cleanup.
- Webrick does not introduce a scheduler, socket server, process supervisor, protocol stack, duplicate output queue, or duplicate lifecycle engine.

## Current next item

**Point 11 — preserve bounded request-body streaming and implement proper Runwire writer-drain continuation without unbounded buffering.**

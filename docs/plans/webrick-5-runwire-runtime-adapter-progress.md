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
- [ ] **7. Runwire application bridge** — compose `RuntimeApplicationInterface` with Runwire `ApplicationLifecycle` without duplicating lifecycle logic.
- [ ] **8. HTTP/1.1 parity** — prove request/response parity and exactly-once response completion.
- [ ] **9. HTTP/2 isolation** — prove normalization, interleaved request isolation and cancellation.
- [ ] **10. HTTP/3 isolation** — prove normalization/stream isolation where QUIC is available and keep QUIC absence non-fatal.
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

## Preserved invariants

- Runwire remains optional for ordinary Webrick consumers.
- Existing direct runtime adapters remain first-class and are not proxied through Runwire.
- The compiled zero-scope route path does not materialize `Request`, create an InterMix scope, or allocate runtime execution metadata unless required.
- Runwire remains authoritative for lower-runtime cancellation/deadlines; Webrick only exposes a read-only observation view.
- InterMix remains authoritative for logical DI scope identity and cleanup.
- Webrick does not introduce a scheduler, socket server, process supervisor, protocol stack, duplicate output queue, or duplicate lifecycle engine.

## Current next item

**Point 7 — compose Webrick with Runwire `RuntimeApplicationInterface` / `ApplicationLifecycle` while preserving Runwire lifecycle ownership and the adapter's exactly-once response-completion rule.**

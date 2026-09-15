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
- [ ] **6. Runwire runtime adapter** — implement the smallest `RunwireRuntimeAdapter` against released `HttpRequest` and `ResponseWriterInterface`.
- [ ] **7. Runwire application bridge** — compose `RuntimeApplicationInterface` with Runwire `ApplicationLifecycle` without duplicating lifecycle logic.
- [ ] **8. HTTP/1.1 parity** — prove request/response parity and exactly-once response completion.
- [ ] **9. HTTP/2 isolation** — prove normalization, interleaved request isolation and cancellation.
- [ ] **10. HTTP/3 isolation** — prove normalization/stream isolation where QUIC is available and keep QUIC absence non-fatal.
- [ ] **11. Bounded streaming/backpressure** — preserve incremental request bodies and response writer pressure handling.
- [ ] **12. Application body-production lifetime** — keep request scope alive only while lazy/streaming application production still needs it.
- [ ] **13. Structured child propagation** — integrate explicit InterMix `ScopeContext` propagation with Runwire structured work while retaining zero-scope routes.
- [ ] **14. Exactly-once cleanup** — prove success/error/cancellation/deadline cleanup and carrier reset.
- [ ] **15. Trusted static/public assets** — compose Webrick HTTP file semantics with Pathwise trust boundaries.
- [ ] **16. Deployment acceptance** — cover Runwire portable/prefork and host drivers while preserving generic SAPI/shared-hosting behavior.
- [ ] **17. Foundation bridge fixture** — validate the Framework integration contract.
- [ ] **18. Infbyte end-to-end acceptance** — validate request-scoped and persistent-runtime behavior in the application.
- [ ] **19. Performance validation** — measure adapter/scope/streaming overhead and tune only demonstrated regressions.
- [ ] **20. Release documentation and handoff** — finalize Webrick 5 documentation and Foundation integration guidance.

## Preserved invariants

- Runwire remains optional for ordinary Webrick consumers.
- Existing direct runtime adapters remain first-class and are not proxied through Runwire.
- The compiled zero-scope route path does not materialize `Request`, create an InterMix scope, or allocate runtime execution metadata unless required.
- Runwire remains authoritative for lower-runtime cancellation/deadlines; Webrick only exposes a read-only observation view.
- InterMix remains authoritative for logical DI scope identity and cleanup.
- Webrick does not introduce a scheduler, socket server, process supervisor, protocol stack, or duplicate lifecycle engine.

## Current next item

**Point 6 — implement the smallest `RunwireRuntimeAdapter` against the released Runwire 1.0 `HttpRequest` / `ResponseWriterInterface` contracts.**

# Webrick 5.0 — Major Release Engineering Plan

This file is the **live implementation tracker** for Webrick 5.0.

The complete engineering design, rationale, architecture, file-by-file disposition, benchmark gates, and detailed requirements are preserved in [`webrick-5-major-release-plan-details.md`](./webrick-5-major-release-plan-details.md).

## Governing workflow

Follow `vendor/infocyph/phpforge/resources/engineering-principles.md` throughout the release.

For every phase:

1. complete the entire phase before publishing its implementation commit;
2. make one phase-completion commit;
3. run the full PHPForge/test/analysis matrix only after that commit;
4. resolve every functional, security, quality, static-analysis, and compatibility finding;
5. make a separate validation-fix commit;
6. mark the phase complete here only after validation is green;
7. start the next phase only after the previous phase is fully validated.

All Webrick 5 implementation work stays on `webrick-5/batch-1-correctness` unless explicitly changed.

---

## Phase 1 — P0 correctness and security blockers

**Implementation commit:** `025019e490471753930534e5f43854cc25e1df97`

- [x] **3.1 OPTIONS safety** — implicit OPTIONS cannot execute application handlers; explicit OPTIONS remains executable. Canonical typed matcher outcomes remain part of the Phase 2 compiler/runtime contract rewrite.
- [x] **3.2 Conditional requests** — RFC precondition precedence; unsafe matching `If-None-Match` returns 412.
- [x] **3.3 Swoole request locality** — native response/transport state is request-local and not stored as reusable emitter state.
- [x] **3.4 Request context** — process-global current-request telemetry state replaced by explicit `RequestContext` attached to the request.
- [x] **3.5 Stateless middleware** — reusable middleware no longer stores mutable current `EndUser` or equivalent request state.
- [x] **3.6 Error boundary** — request rendering no longer pushes/pops PHP error handlers; process-level bridge is separate; debug defaults false.
- [x] **3.7 CSRF** — injected token storage; header/body proof; cookie is not proof; query proof is opt-in.
- [x] **3.8 CORS** — disabled/deny-by-default; credentialed wildcard rejected; real preflight detection; security policy split.
- [x] **3.9 Trusted proxies** — centralized trust-boundary normalization; trusted chain stripped right-to-left; vendor client-IP headers explicit.
- [x] **3.10 CIDR** — strict IPv4/IPv6 family and prefix validation.
- [x] **3.11 Byte ranges** — bounded immutable start/length with request-local relative position.
- [x] **3.12 Range/cache correctness** — malformed vs unsatisfiable ranges distinguished; metadata ETags weak; generic immutable file caching removed.
- [x] **3.13 Header combination** — field-specific combination; HTTP method tokens preserve required case.
- [x] **3.14 Produces metadata** — route media metadata survives registration, compilation, cache payloads, and dispatch.
- [x] **3.15 Authorization** — no pseudo credential headers; only actual `Authorization` is exposed.
- [x] **3.16 Cookie identity/security** — identity includes name/domain/path; `__Host-`, `__Secure-`, SameSite=None, and Partitioned invariants enforced.
- [x] **Phase 1 implementation complete**
- [ ] **Phase 1 PHPForge/full CI validation green**
- [ ] **Phase 1 complete**

### Current validation findings

The implementation commit intentionally triggered validation only after the whole phase was published. The first matrix identified test migrations to explicit `RequestContext`, a trusted-proxy chain edge case, explicit CORS max-age test configuration, PHPForge Pint/Rector/comment drift, and PHPStan findings. These are being resolved in the validation stage before Phase 2 begins.

---

## Remaining phases

- [ ] **Phase 2 — compiler/runtime foundation**
  - InterMix `^10.0.2`
  - application-owned composition root
  - explicit development vs production runtime delegation
  - coordinated compiled artifacts
  - canonical typed matcher outcomes
  - frozen production registries/configuration
- [ ] **Phase 3 — direct dispatch path**
- [ ] **Phase 4 — matcher rewrite**
- [ ] **Phase 5 — HTTP representation**
- [ ] **Phase 6 — persistent runtimes**
- [ ] **Phase 7 — middleware optimization**
- [ ] **Phase 8 — final benchmark/deletion pass**

---

## Release gates

- [ ] Webrick compiled static endpoint reaches at least 80% of FastRoute sustainable stable RPM in the same run.
- [ ] Stretch target: at least 85% of FastRoute.
- [ ] No unaccepted >5% sustainable regression on representative feature-heavy routes.
- [ ] No request/coroutine state leaks under persistent-worker concurrency tests.
- [ ] Persistent-worker memory plateaus after warm caches.
- [ ] Disabled diagnostics have near-zero request-path overhead.
- [ ] Compiled production boot is materially cheaper than registrar/reflection boot.
- [ ] Development and production runtimes execute the same supported application graph semantics.

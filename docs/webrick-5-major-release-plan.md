# Webrick 5.0 — Major Release Engineering Plan

This file is the **live implementation tracker** for Webrick 5.0.

The complete engineering design, rationale, architecture, file-by-file disposition, benchmark gates, and detailed requirements are preserved in [`webrick-5-major-release-plan-details.md`](./webrick-5-major-release-plan-details.md).

## Governing workflow

Follow `vendor/infocyph/phpforge/resources/engineering-principles.md` throughout the release.

For the remaining feature phases:

1. finish a coherent feature slice before committing it;
2. when a phase is large, commit **2–3 complete checklist points at a time** instead of carrying an oversized unpublished tree;
3. keep all implementation on `webrick-5/batch-1-correctness` unless explicitly changed;
4. use only lightweight implementation hygiene while feature work is active; do **not** block phase progress on the full PHPForge/CI matrix;
5. mark implementation/feature completion separately from validation certification;
6. after all feature phases are implemented, run one consolidated PHPForge/test/static-analysis/concurrency/benchmark stabilization pass and resolve cross-phase findings together.

Full validation therefore remains intentionally pending for completed feature phases until the consolidated stabilization pass.

---

## Phase 1 — P0 correctness and security blockers

**Implementation commit:** `025019e490471753930534e5f43854cc25e1df97`  
**Validation-fix work already captured:** `5f9184a2f37f72132e75a7e3c1818d12c4d6059b`

- [x] **3.1 OPTIONS safety** — implicit OPTIONS cannot execute application handlers; explicit OPTIONS remains executable.
- [x] **3.2 Conditional requests** — RFC precondition precedence; unsafe matching `If-None-Match` returns 412.
- [x] **3.3 Swoole request locality** — native response/transport state is request-local.
- [x] **3.4 Request context** — process-global current-request telemetry state removed in favor of explicit request context.
- [x] **3.5 Stateless middleware** — reusable middleware does not retain mutable current-request/end-user state.
- [x] **3.6 Error boundary** — request rendering no longer installs/removes process error handlers; debug defaults false.
- [x] **3.7 CSRF** — injected token storage; header/body proof; cookie is not proof; query proof opt-in.
- [x] **3.8 CORS** — deny/disabled by default; credentialed wildcard rejected; real preflight detection.
- [x] **3.9 Trusted proxies** — trusted chain normalized from the trusted peer inward; vendor headers explicit.
- [x] **3.10 CIDR** — strict IPv4/IPv6 family and prefix validation.
- [x] **3.11 Byte ranges** — bounded immutable start/length with request-local position.
- [x] **3.12 Range/cache correctness** — malformed vs unsatisfiable ranges distinguished; weak metadata ETags.
- [x] **3.13 Header combination** — field-specific combination and correct method-token casing.
- [x] **3.14 Produces metadata** — survives registration, compilation, cache payloads, and dispatch.
- [x] **3.15 Authorization** — no pseudo credential headers.
- [x] **3.16 Cookie identity/security** — full identity and prefix/SameSite/Partitioned invariants.
- [x] **Phase 1 implementation complete**
- [x] **Phase 1 feature complete**
- [ ] **Phase 1 consolidated test/PHPForge certification** — deferred to final stabilization.

---

## Phase 2 — compiler/runtime foundation

### Composition root and InterMix 10

- [x] Require **InterMix `^10.0.2`** and directly own the closure serializer dependency used by Webrick artifacts.
- [x] Application/host owns the single `ContainerBuilder`; Webrick contributes providers via `Webrick::contributeTo()`.
- [x] Environment selection remains host-owned; Webrick does not infer production runtime from `APP_ENV` or call `setEnvironment()` behind the host.
- [x] Explicit standalone-development convenience exists without creating a production runtime implicitly.
- [x] Runtime selection is resolved once at boot through `InterMixRuntime`; request execution does not branch between development/production containers.

**Commit:** `e15c83fe83784bedc420658f4fe0fe82c15d07e6`

### Build-plane route IR

- [x] Registration, handler inspection, middleware-alias resolution, route capability discovery, and execution classification happen in the build plane.
- [x] Deterministic route identity is derived from canonical `(method, domain, path)` instead of registration-order indexes.
- [x] Compile the five execution kinds: `DIRECT_ZERO_ARG`, `DIRECT_ROUTE_ARGS`, `DIRECT_REQUEST`, `COMPILED_INVOKE`, `MIDDLEWARE_PIPELINE`.
- [x] Compile route capability masks for Request, scope, middleware, domain, CORS, Produces, and route arguments.
- [x] Non-static controller methods remain InterMix-backed `COMPILED_INVOKE`; direct execution is emitted only for genuinely callable targets.
- [x] Middleware aliases are resolved before traffic and unknown descriptors fail at the build boundary.

**Commit:** `5202c23ff421e4c1cf3d8d560007f9e9604e65ed`

### Coordinated production artifacts

- [x] Versioned Webrick artifact contains route data, execution plans, aliases, global middleware descriptors/tags, domain-routing capability, environment, and configuration fingerprint.
- [x] Normal production loading verifies SHA-256 plus metadata/payload/config/environment alignment.
- [x] Trusted-prevalidated loading accepts an externally trusted SHA only for an immutable deployment boundary and skips duplicate file hashing.
- [x] `ReleaseCompiler` coordinates the Webrick artifact with the InterMix generated artifact and release manifest without duplicating InterMix's compiler or artifact format.
- [x] InterMix dynamic/fallback islands remain owned by InterMix `ProductionContainer::resolveNow()` rather than Webrick inventing a second DI fallback model.

**Commit:** `4427b7543130f76f68b60cafa2496b2ec98864d7`

### Typed execution runtime

- [x] Existing matcher backends are normalized through one typed outcome boundary: `FOUND`, `AUTO_OPTIONS`, `METHOD_NOT_ALLOWED`, `NOT_FOUND`.
- [x] HEAD→GET fallback is represented explicitly in the match outcome rather than inferred through a synthetic route.
- [x] Middleware plans retain the terminal execution kind, so middleware does not force every terminal handler through InterMix.
- [x] Compiled runtime dispatcher executes direct handlers directly and delegates only DI-backed handlers/middleware to the selected InterMix runtime.
- [x] Cached middleware pipelines read the current request's canonical `route_params` bag and never capture the first request's dynamic variables.
- [x] Production execution does not perform route-handler reflection, class discovery, or middleware-alias parsing per request.

**Commit:** `33dd045ca4d42f7a1a26e44cd059876eca88c1db`

### Strict production bootstrap and freeze

- [x] `CompiledRouterKernel` requires a host-selected `ProductionContainer`; it cannot construct a competing container or import providers.
- [x] Production boot consumes only verified/prevalidated Webrick artifacts; missing/stale artifacts fail instead of running the registrar or compiling on first request.
- [x] Request scope is conditional; `withinScope()` is used only for plans/global middleware that require scoped runtime behavior.
- [x] Request is seeded into the scope only when Request-aware execution/middleware requires it.
- [x] URL aliases are bound from the compiled artifact; signing secrets/runtime URL configuration remain outside the artifact.
- [x] Middleware-alias, URL-generator, and route-constraint registries freeze before production traffic.
- [x] Domain host normalization is skipped entirely when the compiled artifact proves there are no domain routes.
- [x] HEAD response bodies are suppressed while preserving representation headers.

**Commit:** `a7fa49e59c69750bd15a28b4e8f4bf5b4f86ef75`

### Final Phase 2 invariants

- [x] Direct-handler callability is validated at build/artifact-load time rather than probed on every request.
- [x] Compiled production runtime has no registrar/reflection/DI-runtime fallback owned by Webrick.
- [x] Legacy `Response::view()` representation-helper cleanup is explicitly owned by **Phase 5**; the compiled production kernel does not create or depend on its legacy global container path.
- [x] **Phase 2 implementation complete**
- [x] **Phase 2 feature complete**
- [ ] **Phase 2 consolidated test/PHPForge certification** — deferred to final stabilization.

---

## Phase 3 — direct dispatch path

### Routing preflight and lazy promotion

- [x] Minimal immutable `RoutingInput` is created from native globals or an already supplied Request before full Request materialization.
- [x] Domain host detection/normalization is skipped when the compiled artifact proves there are no domain routes.
- [x] Method override semantics and normalized request path are preserved in the routing preflight.
- [x] Match, automatic OPTIONS, and direct handler selection happen before `Request::fromGlobals()`.
- [x] `DIRECT_ZERO_ARG` and `DIRECT_ROUTE_ARGS` bypass Request, InterMix resolution, request scope, and middleware machinery when their capabilities allow it.
- [x] DI-backed plans that do not require Request may execute inside an InterMix scope without constructing or seeding Request.
- [x] Errors lazily promote to Request only for rendering; invalid-global Request construction has a minimal error-render fallback.
- [x] A materialized Request receives one canonical `route_params` bag only; duplicate route-param aliases are not reintroduced.

**Commit:** `efeb09b654f4bf354ae93b40c48cfc0a76557a2e`

### Remaining Phase 3 work

- [ ] Specialize `DIRECT_REQUEST` promotion so the callable executes directly with no InterMix resolution/scope unless middleware requires scope.
- [ ] Remove avoidable per-request closures/branching from the direct no-Request path after route selection.
- [ ] Ensure direct static execution can use the compiled plan without unnecessary route/dispatcher lookups beyond the Phase 4 matcher boundary.
- [ ] Keep HEAD suppression and error semantics identical across direct and promoted paths.
- [ ] **Phase 3 implementation complete**
- [ ] **Phase 3 feature complete**
- [ ] **Phase 3 consolidated test/PHPForge certification** — deferred to final stabilization.

---

## Remaining phases

- [ ] **Phase 4 — matcher rewrite**
- [ ] **Phase 5 — HTTP representation**
  - includes removal of the legacy `Response::view()` global container lookup in favor of the selected runtime/view-factory boundary
- [ ] **Phase 6 — persistent runtimes**
- [ ] **Phase 7 — middleware optimization**
- [ ] **Phase 8 — final benchmark/deletion pass**
- [ ] **Consolidated stabilization** — PHPUnit/Pest, PHPStan, Psalm, PHPForge QA, persistent-runtime concurrency/soak tests, Composer matrices, benchmarks, and cross-phase fixes.

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

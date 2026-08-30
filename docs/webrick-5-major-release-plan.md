# Webrick 5.0 — Major Release Implementation Tracker

This file is the **live TODO/checklist** for Webrick 5.0.

The full engineering design, rationale, detailed requirements, file disposition, benchmark matrix, and release gates remain in [`webrick-5-major-release-plan-details.md`](./webrick-5-major-release-plan-details.md).

## Governing workflow

Follow `vendor/infocyph/phpforge/resources/engineering-principles.md` throughout the release.

- [x] Keep all feature work on `webrick-5/batch-1-correctness` unless explicitly changed.
- [x] Finish coherent feature slices before committing.
- [x] Split large phases into roughly 2–3 complete points per commit when useful.
- [x] Keep lightweight implementation hygiene during feature work.
- [x] Defer the full PHPUnit/Pest/PHPStan/Psalm/PHPForge/Composer/concurrency/benchmark matrix until all feature phases are implemented.
- [ ] Run one consolidated stabilization/certification pass after Phase 8.

---

## Phase 1 — P0 correctness/security

- [x] Safe automatic OPTIONS and explicit OPTIONS behavior.
- [x] RFC conditional-request precedence and unsafe `If-None-Match` handling.
- [x] Request/coroutine-local transport and telemetry state.
- [x] Stateless reusable middleware and process-level error bridge.
- [x] CSRF proof/storage hardening.
- [x] Conservative CORS and real preflight detection.
- [x] Trusted-proxy chain selection and strict CIDR validation.
- [x] Correct range parsing/streaming/cache interaction.
- [x] Field-specific header combination and Produces propagation.
- [x] Authorization cleanup and cookie identity/security invariants.
- [x] **Phase 1 implementation complete.**
- [x] **Phase 1 feature complete.**
- [ ] **Phase 1 consolidated certification** — deferred.

Primary implementation: `025019e490471753930534e5f43854cc25e1df97`  
Early validation fixes: `5f9184a2f37f72132e75a7e3c1818d12c4d6059b`

---

## Phase 2 — compiler/runtime foundation

- [x] InterMix `^10.0.2` and caller-owned application `ContainerBuilder`.
- [x] Explicit development/production runtime selection at boot; no `APP_ENV` inference.
- [x] Deterministic route identity and five compiled execution kinds.
- [x] Route capability masks and build-time handler/middleware classification.
- [x] Versioned Webrick production artifact + metadata/config/environment alignment.
- [x] Normal SHA-256 and trusted-prevalidated artifact loading.
- [x] Coordinated Webrick/InterMix release compilation without duplicating InterMix format.
- [x] Typed routing outcomes and explicit HEAD fallback.
- [x] Runtime dispatcher preserves terminal execution kind through middleware.
- [x] Conditional request scopes and Request seeding.
- [x] Strict compiled production kernel; no registrar/reflection/runtime-compilation fallback.
- [x] Production aliases/constraints/middleware registries freeze before traffic.
- [x] **Phase 2 implementation complete.**
- [x] **Phase 2 feature complete.**
- [ ] **Phase 2 consolidated certification** — deferred.

Commits: `e15c83f`, `5202c23`, `4427b75`, `33dd045`, `a7fa49e`, `8555348`

---

## Phase 3 — direct dispatch path

- [x] Minimal immutable `RoutingInput` before full Request materialization.
- [x] Skip host work when compiled artifact has no domain routes.
- [x] Match/OPTIONS/plan selection before `Request::fromGlobals()`.
- [x] `DIRECT_ZERO_ARG` bypasses Request, InterMix, scope, and middleware when permitted.
- [x] `DIRECT_ROUTE_ARGS` uses ordered compiled route args without Request.
- [x] DI-only plans may scope without constructing/seeding Request.
- [x] `DIRECT_REQUEST` promotes Request only after matching and invokes directly.
- [x] Error rendering promotes Request lazily.
- [x] One canonical `route_params` bag only.
- [x] Global middleware decision cached at boot.
- [x] Kernel-selected `ExecutionPlan` reused by dispatcher; no duplicate plan lookup.
- [x] HEAD/error semantics shared across direct and promoted paths.
- [x] **Phase 3 implementation complete.**
- [x] **Phase 3 feature complete.**
- [ ] **Phase 3 consolidated certification** — deferred.

Commits: `efeb09b`, `737554b`, `f9d48ee`, `a272420`

---

## Phase 4 — matcher rewrite

- [x] One canonical matcher IR: exact static map + segment-count/literal-prefix dynamic buckets.
- [x] Exact static lookup occurs before path trim/split/count/dynamic allocation.
- [x] Fused migrated from recursive trie to canonical engine.
- [x] Sharded migrated to canonical IR.
- [x] Dedicated `__dynamic` shard preserves first-segment-variable routes such as `/{id}`.
- [x] Generated matcher emitted from canonical IR with static switches before segmentation.
- [x] Callable constraints validated at build/cache-load and directly invoked at runtime.
- [x] `MatcherInterface::matchOutcome()` natively owns FOUND/404/405/OPTIONS/HEAD semantics.
- [x] Compiled kernel calls matcher directly; `MatcherOutcomeAdapter` removed.
- [x] Legacy trie/helper physical deletion deferred to measured Phase 8 cleanup.
- [x] Matcher-mode retention/removal deferred to Phase 8 benchmark evidence.
- [x] **Phase 4 implementation complete.**
- [x] **Phase 4 feature complete.**
- [ ] **Phase 4 consolidated certification** — deferred.

Commits: `5f7964e`, `b044e44`, `3c534a1`, `0c27cb8`, `d5d3773`, `03f003a`

---

## Phase 5 — HTTP representation

### Native response/body representation

- [x] Ordinary text/HTML/eager JSON remains a native PHP string inside `Response`.
- [x] `StringBody` supplies stream-compatible semantics only when an interop caller explicitly requests a body stream.
- [x] `Response::getBodySize()`, `getStringBody()`, and `isStringBody()` expose the cheap native representation.
- [x] Lazy JSON encodes once into `StringBody`; it no longer creates a second temp stream.
- [x] Unchanged immutable response clones share `HeaderBag`; headers are validated/normalized at the boundary rather than cloned repeatedly.
- [x] Resource-backed `Stream::__toString()` handles non-seekable streams without attempting an impossible rewind.

### Output path

- [x] Classic SAPI `DefaultEmitter` writes string-backed responses directly without `getBody()` or stream allocation.
- [x] Existing Swoole emitter sends native string bodies directly.
- [x] Existing RoadRunner bridge keeps native strings native.
- [x] Existing Workerman bridge keeps native strings native.
- [x] Full runtime-adapter replacement remains correctly owned by Phase 6.

### Native Request representation

- [x] Public Request input access (`query`, `post`, `cookie`, `server`) is array/scalar-backed rather than Collection-backed.
- [x] JSON/XML parsing has explicit `NOT_APPLICABLE`, `NOT_PARSED`, `PARSED`, and `INVALID` states.
- [x] Valid empty JSON/XML remains a successful parsed payload and never falls through to POST data.
- [x] Invalid JSON/XML has an explicit failure policy instead of being treated as an empty form payload.
- [x] Request locale metadata consumes only the canonical `route_params` bag.
- [x] Native `Request\Core\ServerRequest` replaces the pseudo-PSR message implementation in the Request inheritance chain.
- [x] Full Request materialization builds URI state from validated transport components rather than constructing a complete URL solely to `parse_url()` it again.
- [x] Uploaded-file hydration remains lazy.

### Dependency / interoperability boundaries

- [x] Mandatory `infocyph/arraykit` dependency removed from Webrick core.
- [x] Native Webrick `HttpFactory` moved out of the misleading `Psr7` namespace.
- [x] Legacy pseudo-PSR `ServerRequest` and `HttpFactory` removed.
- [x] `Interop\Psr7\PsrBridge` provides an explicit optional PSR-7/PSR-17 adaptation boundary.
- [x] `psr/http-message` and `psr/http-factory` remain optional Composer suggestions, not hot-path dependencies.
- [x] `Response::view()` no longer reaches a global InterMix container.
- [x] Injected `ViewResponder` + `ViewFactoryInterface` owns view rendering; `Response` remains a value/output type.
- [x] Remaining old `Request\Psr7` normalizer filenames are non-message compatibility utilities only; physical relocation/deletion is reserved for Phase 8 cleanup.
- [x] **Phase 5 implementation complete.**
- [x] **Phase 5 feature complete.**
- [ ] **Phase 5 consolidated certification** — deferred.

Phase 5 commits include: `f46c994`, `75c7030`, `2e928e9`, `1077c27`, `015fec7`, `ed90ffb`, `8ad30e1`, `3ca65a0`, `68ab9c4`, `ef34bf7`, `bbd1d40`, `affa34f`, `d0902e9`, `b8e0d57`, `567a6fe`, `20de187`, `3e40fd1`, `c6b9fb9`, `c08d6fa`, `70394db`, `13a0207`

---

## Phase 6 — persistent runtimes

### Boot-selected runtime contract

- [x] One `RuntimeAdapterInterface` is selected at process/worker bootstrap; production request handling performs no engine discovery.
- [x] `RuntimeServer` owns the boot-selected adapter and delegates routing/execution to `CompiledRouterKernel`.
- [x] Immutable `RuntimeCapabilities` exposes persistence, concurrency, native streaming/file support, and transport-owned compression.
- [x] `RuntimeRequestContext` owns request-local native transport handles, lazy Request materialization, and unique InterMix scope identity.
- [x] `CompiledRouterKernel::handleRuntime()` consumes runtime context and never falls back to async superglobal state.
- [x] Compiled-artifact domain capability is passed into runtime preflight so host normalization remains conditional.

### Native request promotion and response writing

- [x] SAPI/FPM routing reads globals minimally and materializes full Request only after route capability selection.
- [x] Swoole/OpenSwoole reads native request data without copying it into `$_SERVER`/other process globals.
- [x] RoadRunner keeps the incoming PSR request at the interoperability boundary and wraps body/upload streams without full-body copying.
- [x] Workerman uses its native Request/Connection API with compatibility resolved once at adapter construction.
- [x] Form POST data is touched during routing preflight only when POST `_method` override is enabled and observable.
- [x] Promoted async request bodies can remain `BodyStream`/`StringBody`; no mandatory `php://temp` conversion.
- [x] URI materialization uses transport components directly through `Uri::fromComponents()`.
- [x] HEAD suppresses transport bytes while preserving representation `Content-Length`.
- [x] Informational/204/205/304 body semantics are shared centrally across runtime writers.

### Streaming, files and persistent-worker safety

- [x] `FileBody` preserves path, offset, and length for native transport handoff, including byte ranges.
- [x] Swoole/OpenSwoole uses native `end()`, checked `write()`, and `sendfile(path, offset, length)`.
- [x] RoadRunner uses a boot-injected native responder with string/Generator output and optional configured sendfile delegation.
- [x] Workerman uses native `Response::withFile()` for whole files and explicit chunk streaming otherwise.
- [x] Native writer failures are surfaced rather than silently ignored.
- [x] Runtime transport-compression ownership is represented in capabilities for Phase 7 compression bypass.
- [x] InterMix production runtime remains host-owned and worker-shared; Webrick opens explicit request scopes only when compiled capabilities require them.
- [x] Request/native transport state is never stored in static current-request/current-response fields or reusable middleware.
- [x] Persistent-runtime isolation coverage includes one-time materialization, unique native handles/scope IDs, interleaved Fibers, weak-reference release, and FileBody/range metadata.
- [x] Legacy emitter discovery remains compatibility-only and is reserved for measured Phase 8 deletion; compiled production runtime uses the adapter path.
- [x] **Phase 6 implementation complete.**
- [x] **Phase 6 feature complete.**
- [ ] **Phase 6 consolidated certification / long-worker soak validation** — deferred.

---

## Remaining feature phases

- [ ] **Phase 7 — middleware optimization**
- [ ] **Phase 8 — benchmark-driven deletion/final optimization**
- [ ] **Consolidated stabilization and release certification**

---

## Release gates

- [ ] Compiled static endpoint reaches **>=80% FastRoute** sustainable stable RPM in the same run.
- [ ] Stretch target: **>=85% FastRoute**.
- [ ] No unaccepted >5% sustainable regression on representative feature-heavy routes.
- [ ] No request/coroutine state leaks under persistent-worker concurrency tests.
- [ ] Persistent-worker memory plateaus after warm caches.
- [ ] Disabled diagnostics have near-zero request-path overhead.
- [ ] Compiled production boot is materially cheaper than registrar/reflection boot.
- [ ] Development and production runtimes execute the same supported application graph semantics.

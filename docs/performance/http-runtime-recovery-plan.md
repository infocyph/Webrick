# Webrick 5.x — Real-HTTP Performance Recovery Plan

## Objective

Webrick 5's matcher work is complete unless profiling proves otherwise. The remaining Apache + OPcache gap is in the production request/runtime path, so this pass moves deterministic work out of requests and consumes the finalized InterMix 10.0.3 runtime contract.

The production request should consume already-finalized Webrick + InterMix artifacts rather than partially reconstructing them.

## Guardrails

- Preserve public APIs and routing semantics.
- Preserve middleware, domain, URL-generation, method, HEAD/OPTIONS, 404/405, error and DI behavior.
- Do not weaken build/deployment validation or artifact integrity.
- Do not add benchmark-only fast paths.
- Do not redesign InterMix or add a new InterMix loader API.
- Do not rewrite matcher algorithms unless later profiling identifies matcher execution as a bottleneck.
- Keep changes lightweight and measurable.

## Phase 1 — Request-path instrumentation

Add opt-in `hrtime(true)` stage timing around the production bootstrap/runtime path. Measure at least release loading, InterMix runtime loading, Webrick artifact loading, matcher initialization, matching, dispatch preparation, handler execution, response construction and emission. Record static, dynamic, 404 and 405 separately where the harness can expose them. Diagnostics must be disabled by default and removable from the normal hot path.

## Phase 2 — Truly prevalidated router-artifact loading

Split full build/deployment validation from runtime loading. `RouterArtifactLoader::loadPrevalidated()` must stop recalculating the entire payload fingerprint and stop traversing every route/plan/middleware collection on each request. Runtime checks should be limited to cheap ABI/environment/trusted-release identity checks plus loading the immutable artifact. Runtime load cost must be effectively independent of route count.

## Phase 3 — Matcher cache boot

When a matcher can boot from a valid production cache, do not add all artifact routes to it before finalization. Preserve reverse-routing and metadata semantics independently of matcher execution state. Cover Generated, Fused and Sharded matchers.

## Phase 4 — Direct 404/405 control flow

Treat NOT_FOUND and METHOD_NOT_ALLOWED as ordinary routing outcomes, not exceptions. Preserve custom 404/405 handling and `Allow` semantics, while keeping `ErrorHandler` for genuine exceptions.

## Phase 5 — Preserve requestless dispatch end-to-end

Do not eagerly create a complete Request object when the compiled route/middleware/handler path does not require one. Allow `CompiledRouterKernel::handle()` to consume globals lazily and materialize Request only when required.

## Phase 6 — Audit eager kernel initialization

Profile and remove/defer unconditional per-request work that is deterministic or unused by a simple route, including URL-generation registry setup, freezes, constraint/header policy work, runtime dispatcher/error-handler setup and other registries. Prefer compilation or lazy initialization only where measurements justify it.

## Phase 7 — Release metadata hot path

Measure per-request JSON manifest I/O/decoding. If meaningful, retain JSON for deployment/tooling and generate an OPcache-friendly PHP runtime manifest for request-time loading. Do not change this without stage-timing evidence.

## Phase 8 — Response/emitter overhead

Measure Response construction, emitter work and benchmark telemetry separately. Preserve proper Webrick HTTP semantics. Keep matcher-only, compiled-kernel and full HTTP benchmarks distinct so abstraction cost is visible rather than hidden.

## Validation loop

After each major phase:

1. Run semantic/parity tests.
2. Run the relevant microbenchmarks.
3. Run the same production-equivalent Apache + OPcache workload.
4. Record the delta before continuing.

The unchanged real-HTTP benchmark remains the final gate.

## Acceptance criteria

- Prevalidated router loading does not perform full payload fingerprint traversal per request.
- Valid matcher caches boot without route re-registration.
- Normal 404/405 requests do not throw PHP exceptions.
- Request-independent routes do not eagerly materialize a full Request.
- Any eager kernel/runtime work retained is shown to be necessary or negligible by measurement.
- Release-manifest and Response/emitter changes are evidence-driven.
- Webrick consumes InterMix 10.0.3's generic 32-character `digest` release metadata without a SHA-256 compatibility path.
- No temporary diagnostic behavior is enabled by default in production.

## Implementation order

1. Instrumentation.
2. RouterArtifactLoader hot path.
3. Matcher cache boot.
4. 404/405 control flow.
5. Requestless entry/runtime.
6. Re-profile and trim eager kernel initialization.
7. Optimize release metadata only if measurable.
8. Measure/trim response-emitter overhead without weakening semantics.

Then rerun the exact existing real-HTTP workload and compare Generated, Fused and Sharded again.
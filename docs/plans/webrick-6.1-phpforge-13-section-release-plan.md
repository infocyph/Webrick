# Webrick 6.1 — PHPForge engineering principles audit (13 independent passes)

**Audit target:** [`infocyph/Webrick` PR #59](https://github.com/infocyph/Webrick/pull/59), `feature/risk-admission`, commit `324960aec8f5e5f19da3e4ab333d827cc44e3c8a`  
**Policy:** [`infocyph/PHPForge/resources/engineering-principles.md`](https://github.com/infocyph/PHPForge/blob/main/resources/engineering-principles.md), 2,726 lines, 13 sections/98 rule groups, plus EXEC-01–03.  
**Base release:** Webrick 6.0.4.  
**Mode:** Read-only; no code, test, workflow, issue, PR or documentation mutations.

## Coverage and evidence

The review traversed the entire pinned Git tree (487 tracked blobs): 210 production PHP source files, 104 test/fixture PHP files, the documentation tree (87 entries), 26 benchmark entries, 46 `.github` workflow/certification/template entries and root files. All production source and test files were retrieved and inspected through bounded source-pattern analysis; high-risk implementations, deployment tooling and specific documentation were then read in context. Each PHPForge rule section was read in full and applied as a separate analysis lens over this same inventory. This is a full-repository *source-based assessment*, not an assertion that every line was manually proved correct. There were no local PHPForge, PHPStan, Psalm, Sphinx, unit, fuzz or benchmark executions during this audit. Previously retrieved GitHub CI logs were examined for the exact SHA.

**Finding categories:** `Confirmed` = concrete code/doc inconsistency or failing gate; `Review` = evidence-backed risk or potential improvement requiring runtime/contract verification; `Preserve` = a justified design that must not be changed merely to satisfy generic guidance. **P0** = currently blocks release; **P1** = important correctness/security/public contract issue or likely high-leverage improvement; **P2** = lower-risk measured simplification or maintenance improvement. Duplicate findings are deliberately kept in the individual policy sections; consolidation is a later step.

## 01 — Engineering scope and decision rules (CORE-01–02)

| Priority | Finding / improvement | Evidence | Disposition |
|---|---|---|---|
| P1 | Make explicit the preserved 6.0.4→6.1.0 behavior and named-argument contracts across response, matcher, route and middleware code. | `src/Response/Response.php`, `src/Router/{Build,Dispatch,Matching}/` | Review |
| P1 | Document worker-global immutable wiring versus request-scoped service lifetime and prohibit request/tenant state retention. | `RuntimeDispatcher.php`, `RuntimeTaggedMiddleware.php`, `RunwireInterMixScopeBridge.php` | Review |
| P2 | Identify duplicated HTTP-emission and routing control only where removal preserves different runtime responsibilities. | `BaseEmitter.php`, `SapiRuntimeAdapter.php`, `ResponseWriterSupport.php`, both kernels | Review |
| — | Retain optional Runwire, Pathwise, CacheLayer and host-owned risk policy. | `composer.json`, `RiskAdmissionMiddleware.php`, `PathwiseStorageBoundaryTest.php` | Preserve |

## 02 — Tooling and workflow (FLOW-01–12)

| Priority | Finding / improvement | Evidence | Disposition |
|---|---|---|---|
| P0 | SAPI and Runwire performance acceptance both fail at the pinned SHA; do not modify limits to obtain green. | [Runtime Release Certification](https://github.com/infocyph/Webrick/actions/runs/44680641511), `.github/certification/analyze-load.php` | Confirmed |
| P1 | Independently reconcile 14 documented default-deny PHPForge exceptions against analyzer findings, source hash, tests and approved removal condition. | `composer.json` `extra.phpforge.skipper_policy`, `docs/advanced/testing.rst` | Review |
| P1 | Verify release certification is required at final PR/release SHA; the workflow triggers on specific feature branch pushes and manual dispatch, not arbitrary PRs. | `.github/workflows/runtime-release-certification.yml` | Review |
| P2 | Improve discoverability of generic historical batch test names, only when a focused change justifies renaming. | `tests/Unit/DeepCorrectnessBatch{A,C,D,E,F}Test.php` | Review |
| — | Preserve PHPForge strict gates, negative certification-tool tests and justified narrow compatible suppressions. | `security-standards.yml`, `CertificationPathBoundaryTest.php` | Preserve |

## 03 — Performance objective, measurement and acceptance (PERF-01–07)

| Priority | Finding / improvement | Evidence | Disposition |
|---|---|---|---|
| P0 | SAPI file c20 throughput −7.59%, p99 +42.65%; profile file metadata, buffer/stream, SAPI writes and boot overhead separately. | Current performance logs; `FileBody.php`, `RangeResponder.php`, `SapiRuntimeAdapter.php` | Confirmed gate / root cause unknown |
| P0 | Runwire risk_allow c50 throughput −6.49% and risk_clearance c50 −3.45%; profile evaluator, scoped resolution, pipeline construction and response writer. | Current logs; `RuntimeTaggedMiddleware.php`, `CompiledMiddlewarePipeline.php` | Confirmed gate / root cause unknown |
| P0 | Other gate failures: Runwire dynamic/c20 p99 +17.13%; SAPI stream/c5 throughput −2.05% and p99 +18.33%; SAPI risk_allow/c50 p99 +17.41%. | Current logs | Confirmed |
| P1 | Benchmark cold and warm Fused/Generated/Sharded matcher boot, artifact hashes and requestless dispatch in both FPM and persistent workers. | `src/Router/Matching/`, `ReleaseArtifactLoader.php` | Review |
| P1 | Include optional compression, encryption and telemetry in measured representative request mixes. | `CompressionMiddleware.php`, `CookieEncryptionMiddleware.php`, `TelemetryMiddleware.php` | Review |
| P2 | Review benchmark reproducibility, variance, PHP extension and worker-pressure metadata. | `.github/certification/`, `benchmarks/`, `docs/advanced/testing.rst` | Review |

## 04 — Runtime compatibility and public contracts (COMPAT-01–05)

| Priority | Finding / improvement | Evidence | Disposition |
|---|---|---|---|
| P1 | `Response::stream` noniterable producer conversion changed from string-casting to raw output; downstream `producerChunks` throws for non-string chunks. Decide compatibility or documented behavior change and test it. | `src/Response/Response.php` `normalizeProducer`; `ResponseWriterSupport.php` | Confirmed behavior difference / compatibility classification pending |
| P1 | Request-limits guide advertises nonexistent `maxUploadSeconds` named argument and upload timeout enforcement; replace with actual API and clearly assign timeout enforcement to transport/proxy. | `docs/middleware/request-limits.rst` versus `src/Middleware/RequestLimitsMiddleware.php::__construct` | Confirmed |
| P1 | Review repeated optional-extension availability checks in request-time compression/cookie paths; resolve stable extension capabilities during construction. | `CookieEncryptionMiddleware.php` lines ~189,223; `CompressionMiddleware.php` | Review |
| P2 | Verify full compatibility of PHP 8.4/8.5, optional intl/brotli/zstd/redis/opcache and no-dev consumers; run deprecation checks on supported versions. | `composer.json`, `.github/workflows/security-standards.yml` | Review |
| — | Retain subclass compatibility for protected/public constant overrides unless deliberately breaking. | `composer.json` PHPForge policy, `RequestExtensionCompatibilityTest.php` | Preserve |

## 05 — Types, validation and control flow (TYPE-01–02)

| Priority | Finding / improvement | Evidence | Disposition |
|---|---|---|---|
| P1 | Confirm complete body-size and nesting validation for `NativeServerRequest::parseJson` when request-limits middleware is absent or parsing happens before it. | `NativeServerRequest.php::parseJson`, `RequestLimitsMiddleware.php` | Review; not proven bypass |
| P1 | Confirm bounded form-body parsing for optional POST `_method` override before full request promotion in Runwire. | `RoutingFormInput.php::resolve`, `RunwireRuntimeAdapter.php::context`, `RunwireRequestBodyStream.php::getContents` | Review; transport contract needed |
| P2 | Review nullable/empty/zero distinctions in middleware option parsing and router descriptor resolution, with targeted tests not broad casts. | `MiddlewareAliases.php`, `RuntimeDescriptorInvoker.php`, `RequestHeaders.php` | Review |
| — | Retain immutable `RiskDecision` validation and explicit mode/action branching. | `RiskDecision.php`, `RiskAdmissionMiddleware.php` | Preserve |

## 06 — Classes, methods, files and abstraction (STRUCT-01–17)

| Priority | Finding / improvement | Evidence | Disposition |
|---|---|---|---|
| P1 | Profile per-request `TelemetryOptions` + `OpenTelemetryHandler` construction and repeated dynamic API dispatch; consider preparing immutable handler at middleware construction only if lifecycle safe. | `TelemetryMiddleware.php::__invoke`, `OpenTelemetryHandler.php` | Review |
| P1 | Review repeated nested compiled middleware pipeline construction from tagged entries; preserve fresh per-request scoped dependencies. | `RuntimeTaggedMiddleware.php::__invoke`, `CompiledMiddlewarePipeline.php` | Review |
| P2 | Measure and justify layering of native/compiled dispatch, alias invokers and middleware pipeline types; preserve legitimate build/runtime and DI scope separation. | `Dispatcher.php`, `RuntimeDispatcher.php`, `RuntimeAliasInvoker.php`, `RuntimeDescriptorInvoker.php` | Review |
| P2 | Use PHPForge structural/cognitive-complexity diagnostics on larger cohesive components rather than splitting by line count: matcher compilers, `Request`, `ErrorHandler`. | `CompiledMatcherIndexCompiler.php`, `CompactMatcherDynamicValidator.php`, `Request.php`, `ErrorHandler.php` | Review |
| — | Treat source-file density thresholds as review triggers, not automatic defects; preserve legitimate enums, tiny exceptions and adapters. | `STRUCT-13`, source tree | Preserve |

## 07 — Requests, loading and serialization (REQUEST-01–05)

| Priority | Finding / improvement | Evidence | Disposition |
|---|---|---|---|
| P1 | Ensure optional pre-routing Runwire form parsing cannot allocate an unbounded body before route middleware limits; test large/chunked/aborted form input. | `RunwireRuntimeAdapter.php` lines ~87–110; `RoutingFormInput.php`; `RunwireRequestBodyStream.php` | Review |
| P1 | Check response streaming producer type contract with 6.0.4 (iterable strings / callable scalar coercion). | `Response.php::normalizeProducer`, `ResponseWriterSupport.php::producerChunks` | Review |
| P2 | Measure stream-length fallback that rewinds and materializes entire seekable unknown-size stream; consider seek-end/tell when semantics allow. | `src/Support/StreamUtil.php::byteLength` | Review |
| P2 | Profile XML-to-array conversion via `json_encode` then `json_decode` for documented semantics and overhead. | `NativeServerRequest.php::parseXml` | Review |
| — | Preserve single JSON parse state and native/PSR bridge only at boundary. | `NativeServerRequest.php`, `src/Interop/Psr7/PsrBridge.php` | Preserve |

## 08 — Collections, iteration and memory (MEM-01–09)

| Priority | Finding / improvement | Evidence | Disposition |
|---|---|---|---|
| P1 | Measure potentially large string accumulation when reading form bodies and unknown-size streams; make bounds explicit at trust boundary instead of replacing all arrays with SPL. | `RunwireRequestBodyStream.php::getContents`, `RequestLimitsMiddleware.php::measureUnknownBody`, `StreamUtil.php` | Review |
| P2 | Review intermediate token arrays where frequently executed: repeated maps/filters and materialization in CORS, Vary and tagged middleware; compare to one-pass loops using representative request sizes. | `CorsMiddleware.php`, `VaryAccumulatorMiddleware.php`, `RouterKernel.php::lazyTaggedMiddleware` | Review |
| P2 | Inspect repeated file-body seeks during sequential read and chunk size to determine whether output path can skip redundant positioning. | `FileBody.php::read`, `ResponseWriterSupport.php::chunks` | Review |
| — | Do not replace bounded header/token lists `array_shift()` with SPL without evidence; retain `WeakMap` for Fiber metadata and close resource handles explicitly. | `ContentNegotiator.php`, `RunwireResponseContinuation.php`, `FileBody.php` | Preserve |

## 09 — Security, identity and cryptography (SEC-01–10)

| Priority | Finding / improvement | Evidence | Disposition |
|---|---|---|---|
| P1 | Remove `uniqid()` fallback for trace, span and request IDs after `random_bytes` failure. Define explicit safe failure or a host-approved source; do not claim these are authentication tokens. | `TelemetryMiddleware.php::generateSpanId/generateTraceId`, `TelemetrySupport.php::deriveRequestId` | Confirmed weak entropy fallback |
| P1 | Validate threat boundary for executable route artifacts: standalone `RouterArtifactLoader` verifies a self-described `xxh128` digest; coordinated loader adds trusted optional SHA-256. Ensure untrusted artifact paths/metadata cannot be executed before trusted cryptographic verification. | `RouterArtifactLoader.php`, `ReleaseArtifactLoader.php`, `ArtifactValueCodec.php` | Review; immutable deployment trust assumed |
| P1 | Verify Runwire pre-routing form and host/body limit interaction before advertising early body-limit protection. | `RoutingFormInput.php`, `RunwireRuntimeAdapter.php`, request limits docs | Review |
| P1 | Reconcile historical CodeQL review threads and policy exceptions with security-alert state (thread resolution alone is not alert closure). | PR #59 GitHub review, `composer.json` | Review |
| — | Keep CSPRNG for CSRF, SHA/HMAC signatures, constant-time compare, encrypted cookies, fail-closed atomic throttle and Redis replay boundaries. Do not replace security-sensitive hashes with xxHash for speed. | `Csrf.php`, `SignedUrlConfig.php`, `UrlGenerator.php`, `ThrottleMiddleware.php`, `RiskAdmissionRedisReplayTest.php` | Preserve |

## 10 — Database, remote services and caching (DATA-01–07)

| Priority | Finding / improvement | Evidence | Disposition |
|---|---|---|---|
| P1 | Qualify failed-provider/Redis lifecycle including independent processes, retries, lost acknowledgements, expiry, failover and hosted integration timeouts; host owns guarantees outside library. | `docs/middleware/risk-admission.rst`, `RiskAdmissionRedisReplayTest.php` | Review (host-dependent) |
| P1 | Confirm read/write cache failure behavior and zero/negative TTL semantics across PSR-6 backends; maintain separate correctness rules for response cache and security-critical atomic counters. | `ResponseCacheMiddleware.php`, `CachePolicy.php`, `ThrottleMiddleware.php` | Review |
| P2 | Measure default file/PSR-6 cache overhead and compare actual provider storage where cache operations dominate RPM. | `ResponseCacheMiddleware.php`, `ThrottleMiddleware.php` | Review |
| — | Direct SQL/PDO query optimization is not a Webrick-owned subsystem. Retain optional PSR-6 and Pathwise integration; do not silently fall back to process-local state for distributed security decisions. | `composer.json`, `AtomicCounterInterface.php`, Pathwise tests | Preserve / not applicable |

## 11 — Events, logging and observability (OBS-01–04)

| Priority | Finding / improvement | Evidence | Disposition |
|---|---|---|---|
| P1 | Avoid building full access log strings and response metadata when a no-op or disabled logger will discard them. Use stable templates and bounded structured context, respecting PSR-3. | `TelemetrySupport.php::logAccess` (eager `sprintf`), default `NullLogger` in `TelemetryMiddleware` | Confirmed avoidable work; measured benefit pending |
| P1 | Bound and sanitize user-controlled log fields such as HTTP path, source identifiers and trace inputs; add test coverage for very long paths/control characters. | `TelemetrySupport.php::logAccess`, `TelemetryMiddleware.php::extractTraceContext` | Review |
| P2 | Avoid `TelemetryOptions` and `OpenTelemetryHandler` reallocation on every request; reserve tracing instrumentation for opted-in routes. | `TelemetryMiddleware.php`, `OpenTelemetryHandler.php` | Review |
| — | Keep PSR-3 injection, OpenTelemetry as optional adapter and request-local trace context. | `TelemetryOptions.php`, `TraceContext.php` | Preserve |

## 12 — Dependencies, configuration and code loading (CONFIG-01–06)

| Priority | Finding / improvement | Evidence | Disposition |
|---|---|---|---|
| P1 | Review use of moving `@main` reusable PHPForge workflow references and unpinned `dev-main@dev` quality tooling for reproducibility; record exact tested PHPForge source/version on each certification. | `.github/workflows/security-standards.yml`, `composer.json` | Review |
| P1 | Distinguish checksum/fingerprint (`xxh128`) from authenticated integrity before executing compiled PHP, and document immutable artifact ownership/manifest trust. | `RouterArtifactLoader.php`, `ReleaseArtifactLoader.php`, `ShardedMatcher.php`, `GeneratedMatcher.php` | Review |
| P1 | Fix stale documented request-limits configuration and verify other public examples against current constructors rather than leaving ‘adjust names’ caveats. | `docs/middleware/request-limits.rst` | Confirmed |
| P2 | Validate production Composer install with optimized autoloader and absent optional libraries; maintain PHP 8.4/8.5 extension capability parity. | `.github/workflows/security-standards.yml`, `composer.json` | Review |
| — | Preserve build-time route compilation, atomic publication and no-dev archive smoke tests. | `RouterArtifactCompiler.php`, `ShardedCacheGeneration.php`, security workflow | Preserve |

## 13 — Runtime processes, deployment and operations (OPS-01–12)

| Priority | Finding / improvement | Evidence | Disposition |
|---|---|---|---|
| P0 | Do not release while either platform's comparison budgets fail. Repeat full same-SHA certification and 30-minute worker soak after fixes. | [CI evidence](https://github.com/infocyph/Webrick/actions/runs/44680641511) | Confirmed |
| P1 | Document and test transport-level body/connection/time budgets for early Runwire method-override form parsing, before request-limit middleware. | `RunwireRuntimeAdapter.php`, `RoutingFormInput.php`, `RequestLimitsMiddleware.php` | Review |
| P1 | Complete Webrick 6.0.4→6.1 release migration and rollback guidance, including regenerated matcher and InterMix executable artifacts and worker drains. | `docs/guides/upgrading-5.4-to-6.0.rst`, `docs/deployments/`, `ReleaseCompiler.php` | Review |
| P1 | Verify hosted CI and deployment use immutable source identifiers, consistent PHP patch/extensions, non-dev optimized Composer and OPcache telemetry. | `.github/certification/prepared-php.sh`, `runtime-release-certification.yml` | Review |
| P2 | Compare FPM and persistent Runwire worker density, recycling, queue behavior and long-run memory on representative host workload; avoid universal worker-count assumptions. | `.github/certification/run-soak.sh`, `docs/deployments/php-fpm-tuning.rst` | Review |
| — | Preserve scoped fibers, cancellation, persistent-worker soak and separate build/release/run stages. | `RunwireResponseContinuation.php`, `ReleaseArtifactLoader.php`, `RunwireStreamingLifecycleTest.php` | Preserve |

## Evidence and limits

- The exact pinned CI run reported eight performance budget violations, including SAPI file c20 p99 +42.65%. Test jobs passing does not override the acceptance failure.
- Security code scanning workflow had passed, but the live status of historical individual alerts was **not separately verified**.
- Some review entries are conditional on external Runwire/InterMix/CacheLayer/Pathwise contracts; they are not declared exploitable defects without independent testing.
- Every improvement above needs the relevant behavior/compatibility tests and representative RPM evidence before implementation is accepted. In particular, do not loosen security, skip quality checks or discard valid subclass contracts to improve throughput.
- No cross-section TODO deduplication, prioritization across families, or implementation has been performed. That belongs to the requested later consolidation stage.

---

## Consolidated execution plan (added 2026-11-20)

This plan supersedes only the original audit's "read-only" execution state, not its findings. The 13 independent policy reviews above remain intact as historic evidence. Consolidated actions below de-duplicate recurring findings; every batch references the relevant original section(s).

**Target:** PR #59, `feature/risk-admission`, Webrick 6.1.0. **Baseline:** audit SHA `324960aec8f5e5f19da3e4ab333d827cc44e3c8a`; revalidate HEAD and gates before each new batch. **Release block:** SAPI and Runwire performance aggregations fail at baseline. **Scope:** first-party source, tests, documentation, packaging, and CI; external host behavior is separately qualified.

**Execution order override (2026-11-20, completed):** User-directed: finish functional, security, PHPStan/Psalm, documentation, portability and packaging work first (Batch 04 QA, 05, 06, 07, 11). Defer 03's long-running profiling, 08's measured hot-path optimizations, and 09–10's complete SAPI/Runwire load measurements until final readiness, immediately before Batch 12. The feature/risk-admission branch no longer triggers the expensive Runtime Release Certification on every push; `workflow_dispatch` remains available and final exact-SHA successful certification is mandatory. Do not change throughput, p99, memory acceptance limits or validation.

**Execution contract:**
- One coherent implementation batch = one commit, including its tests and appended tracker entry. Audit/plan publication is Batch 00 and its own commit.
- Synchronize this plan's tracker before ending each batch. Never claim release readiness from isolated green unit/static jobs while performance budgets fail.
- Do not merge PRs, tag, publish or relax/suppress any PHPForge/Psalm/PHPStan/CodeQL/performance gate to pass.
- Apply PHPForge engineering-principles.md and the existing project standards. No unrelated refactors or dependency churn. Use CI on exact commit SHA; where local runtime verification is unavailable, record the limitation and the remote CI evidence.
- Preserve public named-argument compatibility, HTTP semantics, persistent-worker scoping, host-owned risk policy, secure cryptography and optional CacheLayer/Pathwise/Runwire integrations.
- Every improvement with hot-path effects must show comparable median successful RPM and p99 across relevant concurrency levels, including correctness; change performance strategy only from measurements.
- A batch is **verified** only when its applicable gates are green; otherwise mark **committed—QA pending/failed**, not complete. Continue fixing same batch instead of opening unrelated refactors when it fails.

### Unique action register and ordered batches

| Batch | Actions (cross-section mapping) | Work and acceptance criteria | Priority | Status |
|---|---|---|---|---|
| **00 — audit** | AUD-00 (CORE–OPS) | Commit complete 13-section audit plus this deduplicated execution plan. Freeze baseline evidence and keep work traceable. | P1 | **Audit and plan committed; original CI cancelled; later cumulative QA green** |
| **01 — documentation contract** | DOC-01 (COMPAT-04, CONFIG-04, OPS-06), DOC-02 (CORE-01, FLOW-02) | Correct request-limits docs: remove imaginary `maxUploadSeconds`, assign transport-owned slow-body timeouts, correct status codes and runnable examples. Check updated docs links and Sphinx. | P1 | **Docs implemented and verified by strict Sphinx on 6886611 and 466e2c6; original CI cancelled** |
| **02 — telemetry identity + logging** | SEC-IDENT-01 (SEC-07, OBS-02), OBS-LOG-01 (OBS-02/04, PERF-02), OBS-LOG-02 (SEC-01) | Remove `uniqid` fallbacks, preserve 16/32-digit trace/span and 32-digit request-ID contracts, verify failure propagation; avoid unneeded no-op log formatting, add input-boundary tests and assess PSR-3 context semantics. | P1 | **Entropy and logging fixes verified on 6886611 and 466e2c6** |
| **03 — baseline diagnostics** | PERF-DIAG-01 (PERF-01/03/05, FLOW-02/10), PERF-DIAG-02 (OPS-11) | Reproduce and attribute the eight exact-SHA load gate breaches; capture valid-response checks, PHP/extension/OPcache parity, run variance and time-series bottlenecks. No relaxed thresholds or changed benchmark workload. | P0 | **Diagnostics tool verified; profiling incomplete; final runtime budgets failing** |
| **04 — API/stream compatibility** | API-01 (COMPAT-02, REQUEST-03/05), API-02 (CORE-01) | Compare 6.0.4→6.1 response producer scalar/coercion behavior, PHP named params and routing/HEAD/405 semantics; add exact compatibility fixtures and smallest justified fix. | P1 | **6.0.4 scalar-stream compatibility restored; regression QA verified on 6886611 and 466e2c6** |
| **05 — request/body boundaries** | BODY-01 (TYPE-01, REQUEST-04, SEC-01), BODY-02 (OPS-06) | Verify Runwire pre-routing POST `_method` form parse size/time limits, nonseekable/aborted bodies, JSON size/depth and application/transport enforcement; address proven gaps only, test adversarial paths. | P1 | **Early Runwire form cap and 413 regression verified; cancellation/deadline and form-size regressions QA-verified; host transport limits remain deployment-owned** |
| **06 — artifact trust + lifecycle** | ART-01 (SEC-04/06, CONFIG-04/06), ART-02 (OPS-07/09) | Prove trusted manifest validation precedes executable PHP on every supported loader path; distinguish xxh128 cache fingerprints vs SHA-256 trusted integrity. Preserve legacy compatibility or explicitly document migration; test tampering/races. | P1 | **Trusted-manifest tamper test and docs verified; SHA-256 tamper and active-symlink drift tests QA-verified; immutable deployment remains host-owned** |
| **07 — quality exceptions + workflow** | QA-01 (FLOW-01/02, COMPAT-02), QA-02 (SEC-01, CONFIG-02), QA-03 (FLOW-10) | Resolve or explicitly qualify each of 14 default-deny PHPForge exception policies; reconcile CodeQL source alerts, CI trigger/reusable workflow provenance, optional extensions and no-dev PHP 8.4/8.5 consumer smoke. | P1 | **PHP 8.4/8.5 QA and no-dev verified; 14-rule scanner green; PHP CodeQL not supported; third-party alert-state verification unavailable** |
| **08 — scoped dispatch and overhead** | STRUCT-HOT-01 (STRUCT-04/11/15, REQUEST-01), MEM-HOT-01 (MEM-01/07/09), PERF-COLD-01 (REQUEST-02) | Profile middleware/telemetry/extension availability, compiled tagged scope and matcher cold/warm boot. Optimize only demonstrated overhead, avoid caching request-scoped objects, add scope isolation parity tests. | P1 | **Empty-tag and compiled runtime no-gate hot paths optimized; functional QA and load budgets pending exact-SHA verification** |
| **09 — SAPI throughput** | PERF-SAPI-01 (PERF-01/03, OPS-01) | Address file c20, stream c5 and risk_allow c50 regressions; preserve file-range, cancellation, MIME, compression and output correctness. Re-run SAPI c1/c5/c20/c50 acceptance. | P0 | **BLOCKED: SAPI aggregate fails at 466e2c6; all SAPI load shards passed** |
| **10 — Runwire throughput** | PERF-RUNWIRE-01 (PERF-01/03, OPS-01/05), DATA-01 (DATA-03/04/06) | Address dynamic/c20 and risk_allow/risk_clearance c50 regressions. Validate real Redis replay/timeout contracts, worker scope, H1/H2/H3, 30-min soak, tail latency and full Runwire load matrix. | P0 | **BLOCKED: Runwire aggregate fails at 466e2c6; H1/H2/H3 and 30-minute soak passed** |
| **11 — operations + migration** | DOC-REL-01 (OPS-07/08/09), CONFIG-REPRO-01 (CONFIG-01/02), STRUCT-CLEAN-01 (STRUCT-13) | Publish 6.0.4→6.1 migration/rollback and production/OPcache guidance, validate executable examples, no-dev artifacts, optional integrations and justified structural candidates; no speculative massive refactor. | P1 | **Migration/rollback docs and dual-PHP no-dev archive verified by strict Sphinx and QA** |
| **12 — exact-SHA signoff** | RELEASE-01 (FLOW-10, PERF-01/07, OPS-11) | Full PHPForge strict QA, static analysis, security, tests, Sphinx, package consumer, all certification shards AND both performance aggregations green at *same final head*; update final evidence and readiness disposition. | P0 | **BLOCKED: both runtime performance aggregates failed; Security & Standards green on 466e2c6** |

### Detailed batch acceptance and dependencies

- **01** is deliberately first: a confirmed public documentation error with a small isolated fix. Do not implement a new timeout API merely to make an obsolete guide true; let the transport, web server and deployment config enforce slowloris time limits.
- **02** should be independent of the benchmark attribution but must preserve trace-ID sizes and failure behavior. Plain access logs may be specified as public operational output, so change their schema only with explicit justification.
- **03** precedes 08–10. Attribute baseline variance and actual hot spots; preserve 2% throughput, 15% p99 and 32 MiB RSS limits.
- **04–07** require explicit negative/compatibility tests and do not allow weakened scanners. A standalone untrusted PHP artifact is not safely authenticated merely by a self-authored checksum.
- **08** addresses only profiled waste; request-scoped service resolution must remain dynamic. Avoid speculative SPL conversions and unnecessary file/class reductions.
- **09–10** remain independently blocking until *both* SAPI and Runwire pass; a rerun that merely masks a deterministic regression is not a fix.
- **11–12** finalize migration evidence, security disposition, PHP 8.4/8.5 and exact final head checks; leave PR unmerged and untagged.

### Append-only batch tracker

| When (UTC) | Batch | Commit SHA | QA evidence | Decision / next |
|---|---|---|---|---|
| 2026-11-20 | 00 | 8bc3070208ee72c9cf9016039b20a232abf8a9d1 | Source audit of 487 blobs, 210 source PHP + 104 test PHP, 13 principle sections; existing failure run [44680641511](https://github.com/infocyph/Webrick/actions/runs/44680641511) | Plan authored; code untouched. Batch 01 next. |
| 2026-11-20 | 01 | `351901056e34133a6f382850fc90a562ecf4833b` | Constructor and request-limit behavior reviewed against source; Sphinx and CI not yet verified at the commit. Existing performance failures remain blocking. | Removed imaginary timeout/unknown-body flags; corrected 400/413/431 and examples; awaiting QA. |
| 2026-11-20 | 02 | `c4e523b839c70b668b22431999c691baa32dedb5` | Existing fixed-width trace and request-ID tests plus new repeated-request uniqueness/shape coverage; PHPForge and CI not yet verified on the new commit. | Removed predictable entropy fallback, skipped NullLogger formatting, escaped/bounded log path; pending QA. |
| 2026-11-20 | 03 | `f06c022d4ff93fc9ff73f10f2149966add0959e5` | Previous [44680641511](https://github.com/infocyph/Webrick/actions/runs/44680641511) shows SAPI stream c5 throughput -2.05%, p99 +18.33%; file c20 throughput -7.59%, p99 +42.65%; risk_allow c50 p99 +17.41%; Runwire dynamic c20 p99 +17.13%, risk_allow c50 throughput -6.49%, risk_clearance c50 throughput -3.45%. Baseline 6.0.4 uses separately recorded normalization; 3 trials, alternating order, dedicated CPU roles. Exact-SHA result on this commit pending. | Added lossless per-trial paired RPS/p99 evidence and baseline CV to aggregate analyzer, preserved all thresholds and validation; profiling remains for Batches 08–10. |
| 2026-11-20 | 04 | `b0e12b061c6bf23a20802feed168bd9a4aedccdf` | Baseline `main` uses `[(string) $output]` for scalar callback stream producers; candidate used `[$output]` and Runwire writer rejects non-string chunks. Exact-SHA CI pending. | Restored 6.0.4 scalar/Stringable coercion, widened documented callback type, added producer and Runwire writer compatibility tests while retaining strict iterable chunk type. |
| 2026-11-20 | 04-QA | `ede022e8204781dfcf64ab3b412ea7649c48d0dd` | [Security & Standards run 44689922032](https://github.com/infocyph/Webrick/actions/runs/44689922032): PHPStan 2 (untyped fixture offsets), Psalm 2 (possibly-null comparison, missing Override), PHPForge skipper fingerprint mismatch after diagnostic-only analyzer changes. Verified original policy fingerprint matches prior source, path sanitizer prefix unchanged, new full-file SHA-256 independently recomputed. QA on this commit pending. | Repair true analyzer diagnostics, rebind four existing exception fingerprints to reviewed source, keep all 24 scoped dispositions, defer full performance runs to final exact-SHA workflow_dispatch. |
| 2026-11-20 | 05 | `94e49312c388defe1835a92864c41b7a781bd8d9` | Runwire 3.0.1 contract confirms streaming received bytes/trailer behavior; pre-routing URL-encoded `_method` parsing formerly called unbounded `getContents()` before middleware. Added bounded-byte and short-circuit integration tests; pending exact-SHA QA. | Default positive 8-MiB form cap, bounded read, 413 pre-routing response; preserve ordinary lazy bodies and transport cancellation/deadlines. No performance workloads changed. |
| 2026-11-20 | 06 | `e9fbfde0cb373136e598afa23ccc6969f5120bb8` | Verified current `ReleaseCompiler` emits SHA-256 for InterMix and router artifacts; `ReleaseArtifactLoader` checks before production() and PHP require. Existing low-trust xxh128 paths remain compatibility-only. Added regression fixture that rehashes malicious sidecar yet cannot bypass trusted manifest SHA-256; CI pending. | Published immutable-artifact threat model and tamper-preexecution test; no unsafe fallback removal or unproved signing claim. |
| 2026-11-20 | 05-QA | `760ae230b66d9589bebf4ee07c89435efef5eb29` | [Analysis PHP 8.5 job 136137159269](https://github.com/infocyph/Webrick/actions/runs/44691191133): PHPStan complexity.functionLike flagged handleRuntime() at 13 versus 12 ceiling after new early rejection. Psalm passed. | Moved pre-routing gate/rejection choice into a small explicit helper, kept single unified HEAD handling and runtime response emission; no suppression or raised complexity cap. Exact-SHA retest pending. |
| 2026-11-20 | 11 | `57fa623ac767be2be05870e33225317fe2ded323` | Documented PHP 8.4/8.5, InterMix 11, optional CacheLayer/Pathwise/Runwire, trusted coordinated releases, Runwire form routing cap, telemetry, explicit 6.0.4 rollback and mandatory final exact-SHA full certification. Sphinx and no-dev consumer check pending. | Added 6.0.4→6.1 migration guide and linked it in guide index; kept release gated, no tag or merge. |
| 2026-11-20 | 06-QA | `57fa623ac767be2be05870e33225317fe2ded323` | [Strict Sphinx job 136138151567](https://github.com/infocyph/Webrick/actions/runs/44691496241) reported two title-underline warnings in the new artifact trust section. | Corrected the RST section adornment in the migration documentation batch, no Sphinx warning suppression. |
| 2026-11-20 | 06/05-QA | `57fa623ac767be2be05870e33225317fe2ded323` | [QA run 44691496241](https://github.com/infocyph/Webrick/actions/runs/44691496241): four PHPForge matrix QA jobs failed with one Pest fixture ContainerException (redundant Request input registered before ReleaseCompiler) and Pint ordered_class_elements in CompiledRouterKernel; Sphinx heading also failed. | Removed redundant test graph input, moved helper below public method, corrected both RST heading lengths. No skipped test or relaxed gate. Exact-SHA retest pending. |
| 2026-11-20 | 05-coverage | `2195f2415e52fb4fc6915c65ed52b3d75b90f0f1` | End-to-end Runwire 3.0.1 buffered-body adapter test in isolated PHPUnit process: enabled form override, bounded rejection with untouched bytes and 413 writer completion, accepted form override and request replay. QA pending on new SHA. | Extend request body boundary coverage without modifying performance workload, public error policy or global method-override state across test processes. |
| 2026-11-20 | 05-QA-2 | `2195f2415e52fb4fc6915c65ed52b3d75b90f0f1` | [QA run 44692026161](https://github.com/infocyph/Webrick/actions/runs/44692026161): Sphinx, no-dev, Pest, PHPStan and Psalm passed; Pint alone failed ordered_class_elements in CompiledRouterKernel. Verified exact PHPForge resources/pint.json rule orders private static before private instance and alphabetizes methods. | Repositioned earlyRuntimeResponse after dispatchWithRequest and before evaluatePreRoutingGate, retained all normal gates, extended direct Runwire form override coverage. |
| 2026-11-20 | 05-QA-3 | `43eb62495cb2e1b9c7cde20c9085a50f9620dd84` | [Run 44692468875](https://github.com/infocyph/Webrick/actions/runs/44692468875): PHPStan, Sphinx, no-dev and Pint passed; Psalm flagged four unused fixture callback arguments, PHPCS flagged unused headers; Pest separate-process fixture failed because PHPUnit\\TextUI\\Configuration\\Registry was unavailable in its spawned runner. | Replaced separate-process runner dependency with a scoped ReflectionProperty override restored in finally, observed response headers, and explicitly released remaining unused callback arguments. No skip policies or production changes. |
| 2026-11-20 | 02-correctness | `79197e9d23d761f8b03200d220514c80f1f872a9` | TelemetrySupport's NullLogger fast path used instanceof and also suppressed subclassed loggers that override log(); client_ip attribute was unescaped/unbounded in access log output. Added recording-subclass regression for both. QA pending. | Skip formatting only for exact Psr\\Log\\NullLogger, not its subclasses; escape CR/LF and bound IP field to 128 bytes. No log format change for valid IPs. |
| 2026-11-20 | 05-QA-4 / 07 | `79197e9d23d761f8b03200d220514c80f1f872a9` | [Security & Standards run 44692962130](https://github.com/infocyph/Webrick/actions/runs/44692962130): PHPStan passed but Psalm/PHPCS found unused test callback arguments (resolved in prior commit), and Pest proved getMethod() is intentionally raw POST whereas getEffectiveMethod() is PUT after form override. PHPForge scanner passed all 14 default-deny rules; PR has 452 resolved review threads (not proof that CodeQL alerts are all closed). | Corrected test to assert both raw/effective methods, deferred PHPForge benchmarks on intermediate PRs with final workflow_dispatch required, left all QA/analysis/security checks enabled. Also hardened telemetry NullLogger subclass contract and bounded log IP. Exact-SHA QA pending. |
| 2026-11-20 | 07-compat | `688661183329ac978606a83b10aa8c1e8e3d5d2c` | Previous no-dev archive consumer was verified on PHP 8.5 only. Same built archive now installed in independent clean no-dev consumers on PHP 8.5 and PHP 8.4 with optional Pathwise absence and risk-admission smoke assertions. Source compatibility test remains gated on CI. | Verify both supported PHP runtimes from one trusted distribution artifact without rerunning Sphinx/archive generation or enabling performance tests. |
| 2026-11-20 | 05-QA-5 / 07-compat | `688661183329ac978606a83b10aa8c1e8e3d5d2c` | [Security & Standards run 44693385034](https://github.com/infocyph/Webrick/actions/runs/44693385034): all 832 Pest tests passed, but PHP 8.5 emitted a strict deprecation for static ReflectionProperty::setValue(value) with one argument; PHPStan, Psalm and Sphinx passed. | Use ReflectionProperty::setValue(null, value) for both test setup and cleanup; add second clean PHP 8.4 no-dev archive consumer to existing PHP 8.5 release job, preserving exact archive. QA retest pending. |
| 2026-11-20 | QA signoff / final phase | `688661183329ac978606a83b10aa8c1e8e3d5d2c` | [Security & Standards run 44693779274](https://github.com/infocyph/Webrick/actions/runs/44693779274) **success**: PHP 8.4/8.5 prefer-lowest and prefer-stable, PHPStan, Psalm, Sphinx, clean install, and both no-dev archive consumers all green; [PR run 44693774231](https://github.com/infocyph/Webrick/actions/runs/44693774231) **success**. All 452 PR inline threads resolved; direct CodeQL alert query unavailable. | Resume full performance certification at new exact head. Keep 2% throughput, 15% p99 and 32 MiB RSS budgets, all response validation, H1/H2/H3 and soak. Known prior [44689911850](https://github.com/infocyph/Webrick/actions/runs/44689911850) both aggregators failed with shifted concurrency/workloads; use paired trial diagnostics to locate root cause. |

| 2026-11-20 | Verified CI audit (batches 00–12) | `466e2c674d6e48400084ae0679c80ff08eba75ae` | At code SHA `466e2c6`, [full benchmark Security & Standards 44694283765](https://github.com/infocyph/Webrick/actions/runs/44694283765) and [PR Security & Standards 44694294175](https://github.com/infocyph/Webrick/actions/runs/44694294175) succeeded, including PHPForge 8.4/8.5 benchmarks; [runtime 44694283456](https://github.com/infocyph/Webrick/actions/runs/44694283456) failed only its two SAPI/Runwire aggregate budget jobs. All protocol, load shard and 30-minute soak jobs succeeded. Per-commit runs and historical failures inventoried in ledger below. | Preserve historical cancelled/failed outcomes; mark batches 01/02/04/11 cumulatively green, 05/06 verified tests with conditional operational follow-up, 07 CodeQL alert-state unverified, 03/08/09/10/12 blocking. |

| 2026-11-20 | 05 cancellation/abort | See commit for this entry | Adds active Runwire 3.0.1 request-context tests for transport cancellation and deadline-exceeded during URL-encoded routing form override; asserts no buffered bytes consumed, no writer started, no runtime context produced, and static override restored in finally. Source implementation already uses cancellation token on read; unit/QA verification required. | Closes test-level cancellation contract; host transport/body/timeout enforcement remains an explicit deployment responsibility, not an invented Webrick timer. |

| 2026-11-20 | 05 cancellation QA correction | See commit for this entry | [Run 44745877998](https://github.com/infocyph/Webrick/actions/runs/44745877998) failed PHPStan/Psalm/Pest for a malformed `@throws` type, direct call to internal Runwire context factory, and omitted CallbackResponseWriter maxBodyBytes. Existing 833 other tests passed. | Use HttpRequest's own native context (public contract), correct single-backslash Throwable PHPDoc, pass required writer budget. No production code changes or skips. Recheck exact-SHA QA. |

| 2026-11-20 | 06 symlink preflight | See batch-06 commit | Adds coordinated-release regression which switches the active InterMix symlink to modified PHP after compilation and confirms trusted-manifest SHA-256 rejects it before executable PHP runs; restores original symlink before router tamper check. QA pending. | Authenticated pre-load integrity verified; immutable artifact ownership and any post-verification TOCTOU protection remain host-owned deployment controls. |

| 2026-11-20 | 08 empty-tag dispatch fast path | See commit for this batch | Source audit verified that every empty InterMix tag resolution constructed a new CompiledMiddlewarePipeline, RuntimeDescriptorInvoker, and terminal closure, even for no middleware entries. Existing CompiledTaggedMiddlewareTest already covers empty tags, request scope, fiber interleaving and tagged lifetimes; new negative-result parity test added. CI and representative SAPI/Runwire load comparison pending. | Skip only the empty descriptor-pipeline allocation while retaining the exact Response validation and exception message. Non-empty scoped middleware continues to resolve freshly per request, never cached. No certification budgets changed. |

| 2026-11-20 | 05 QA acceptance | `3119d1ce40917c3eea197de64f7a771de0f43a7d` | [PR Security & Standards 44746406816](https://github.com/infocyph/Webrick/actions/runs/44746406816) **success**: all four PHP 8.4/8.5 QA variants, two PHPStan/Psalm analysis jobs, strict Sphinx and no-dev consumers. Both cancellation reasons and bounded-form regression test passed. | Close application-level Batch 05; transport-enforced time/size budget must still be verified by each deployment host. |
| 2026-11-20 | 06 QA acceptance | `dee1b3115714edf6dc1dd0563db2efa7fcb95af5` | [PR Security & Standards 44746861869](https://github.com/infocyph/Webrick/actions/runs/44746861869) **success**: full PHP 8.4/8.5 static QA, Pest including switched InterMix symlink attack, Sphinx and no-dev consumers. | Close Webrick-level Batch 06; symlink swap **after** digest verification cannot be ruled out without immutable, trusted host deployment. |
| 2026-11-20 | 07 applicability review | `dee1b3115714edf6dc1dd0563db2efa7fcb95af5` | GitHub Docs [CodeQL language support](https://docs.github.com/en/code-security/concepts/code-scanning/codeql/codeql-code-scanning) explicitly excludes PHP. Webrick strict PHPForge + Psalm taint + PHPStan checks pass. All 452 PR review threads resolved, but GitHub connector does not expose code-scanning alert API; SARIF/AI Scan alerts cannot be declared closed without source access. | Treat PHP CodeQL as not applicable, not as a missing scan. Maintain third-party repository alert-state qualification and avoid inventing success. |
| 2026-11-20 | 08 candidate optimization | See this batch commit | Empty InterMix tagged pipelines were allocating RuntimeDescriptorInvoker and CompiledMiddlewarePipeline on every request; new fast path skips the allocation only when no descriptors exist, while preserving exact Response validation. Existing per-request tag lifetime/fiber-isolation tests and new negative-contract test cover semantics. | Must compare full SAPI/Runwire metrics at the new SHA; **no performance improvement claimed before measurements**. |

| 2026-11-20 | 08 QA correction | See commit for this tracker entry | [S&S 44747370462](https://github.com/infocyph/Webrick/actions/runs/44747370462) static Psalm flagged direct new of internal RuntimeTaggedMiddleware (InternalClass/InternalMethod) and deprecated PHPUnit expectExceptionMessage; phpstan had no issues. | Test now invokes internal object through ReflectionClass (as existing project internal tests do), and checks explicit exception message without deprecated PHPUnit API. No production source changed or QA rules suppressed. |

| 2026-11-20 | 08 QA public API correction | See commit for this tracker entry | [S&S 44747839476](https://github.com/infocyph/Webrick/actions/runs/44747839476) rejected ReflectionClass on an @internal runtime class despite source analysis otherwise passing. | Replaced the reflection test with a public CompiledRouterKernel empty-tag test validating exact response body and WeakReference request release across two requests. All scope/response invariants preserved; no suppression or production source change. |

| 2026-11-20 | 08/09/10 hot-path follow-up | See commit introducing this row | [Runtime certification 44748314007](https://github.com/infocyph/Webrick/actions/runs/44748314007) failed both aggregates despite green H1/H2/H3/soak and load-shard checks; SAPI file/c20 throughput -7.38%, p99 +61.35%; Runwire stream/c1 p99 +36.98%. Trial-level data varied widely between paired samples; do not attribute every breach to one function or weaken 2%/15%/32-MiB budgets. Baseline `main` skips pre-gate work when no gate; candidate added mandatory `earlyRuntimeResponse()` call for every request. Local PHP 8.4 microbenchmark comparing equivalent no-gate branches estimated ~74–76 ns versus ~63–67 ns per invocation (NOT full-stack throughput evidence). | Eliminate extra per-request gate helper call, preserve pre-routing 413 precedence and maintenance gate semantics; move rare exception rendering out of hot path to retain PHPForge complexity budget. Add HEAD + 413 + no-materialization regression test. Full SAPI/Runwire verification required before performance claims. |

| 2026-11-20 | 08/09/10 QA correction | See GitHub commit at this tracker line | [Security & Standards 44754720082](https://github.com/infocyph/Webrick/actions/runs/44754720082) PHP 8.4 Psalm failed four MissingThrowsDocblock annotations on new consumeRuntimeFailure; PHPStan passed. | Add exact InvalidArgumentException, RuntimeException and UnexpectedValueException throws contracts to the rare-path helper; no suppression, benchmark threshold or runtime semantics changed. Pending exact-SHA CI. |

**Tracker update rules:**

### Verified batch CI ledger — 2026-11-20

The **original-SHA** column is historical, not an assertion that every intermediate
commit passed. Red/cancelled runs are preserved as failed/cancelled; later full
QA on source SHA `688661183329ac978606a83b10aa8c1e8e3d5d2c` and on
`466e2c674d6e48400084ae0679c80ff08eba75ae` provides **cumulative**
verification of the corrected implementation. All QA is attributed to the
specific SHA that actually ran it. A PR workflow success alone is not equivalent
to a complete PHPForge or runtime release certificate.

| Batch / correction | Source SHA | CI at source SHA | Verified disposition |
|---|---|---|---|
| 00 audit | `8bc3070` | [S&S 44688059453](https://github.com/infocyph/Webrick/actions/runs/44688059453) cancelled; [PR green](https://github.com/infocyph/Webrick/actions/runs/44688056608) | Superseded; audit/plan carried forward |
| 01 request-limit docs | `3519010` | [S&S 44688259836](https://github.com/infocyph/Webrick/actions/runs/44688259836) cancelled; PR green | Verified later by strict Sphinx at 6886611/466e2c6 |
| 02 entropy/logging | `c4e523b` | [S&S 44688388581](https://github.com/infocyph/Webrick/actions/runs/44688388581) **success** | Follow-up logger fixes in 79197e9, cumulatively green at 6886611/466e2c6 |
| 03 analyzer diagnostics | `f06c022` | No GitHub Actions run found for exact SHA | Changes carried into 466e2c6; analyzer QA green, runtime attribution open |
| 04 API compatibility | `b0e12b0` | [S&S 44689922032](https://github.com/infocyph/Webrick/actions/runs/44689922032) failed; [runtime 44689911850](https://github.com/infocyph/Webrick/actions/runs/44689911850) failed | Analyzer corrections later passed at ede022e; performance not resolved |
| 04 QA recovery | `ede022e` | [S&S 44690727301](https://github.com/infocyph/Webrick/actions/runs/44690727301) **success** | PHPStan/Psalm and scoped skip-policy fingerprints corrected |
| 05 pre-routing form bounds | `94e4931` | [S&S 44691191133](https://github.com/infocyph/Webrick/actions/runs/44691191133) cancelled | Follow-up source/test fixes carried forward, verified on 6886611/466e2c6 |
| 06 artifact trust | `e9fbfde` | [S&S 44691396111](https://github.com/infocyph/Webrick/actions/runs/44691396111) cancelled | Tamper test correction carried forward, verified on 6886611/466e2c6 |
| 05 complexity rework | `760ae23` | [S&S 44691496241](https://github.com/infocyph/Webrick/actions/runs/44691496241) failed | Reworked and verified in later green SHA |
| 11 migration and Sphinx | `57fa623` | [S&S 44692026161](https://github.com/infocyph/Webrick/actions/runs/44692026161) cancelled | Strict Sphinx and both no-dev consumers verified at 6886611/466e2c6 |
| 05 form integration test | `2195f24` | [S&S 44692468875](https://github.com/infocyph/Webrick/actions/runs/44692468875) failed | Test harness/Pint/Psalm feedback corrected in later batches |
| 05 test harness rework | `43eb624` | [S&S 44692962130](https://github.com/infocyph/Webrick/actions/runs/44692962130) failed | Test expectation corrected; later QA green |
| 02/07 logger and workflow | `79197e9` | [S&S 44693385034](https://github.com/infocyph/Webrick/actions/runs/44693385034) failed | Strict PHP 8.5 deprecation fixed on 6886611 |
| 05/07 QA acceptance | `6886611` | [S&S 44693779274](https://github.com/infocyph/Webrick/actions/runs/44693779274) **success**; [PR 44693774231](https://github.com/infocyph/Webrick/actions/runs/44693774231) **success** | Four PHP 8.4/8.5 QA variants, dedicated Psalm/PHPStan, Sphinx and both no-dev archive consumers green |
| Final acceptance gate | `466e2c6` | [S&S full 44694283765](https://github.com/infocyph/Webrick/actions/runs/44694283765) **success**; [S&S PR 44694294175](https://github.com/infocyph/Webrick/actions/runs/44694294175) **success**; [Runtime 44694283456](https://github.com/infocyph/Webrick/actions/runs/44694283456) **failure** | Both PHPForge benchmark jobs green, runtime protocol/soak green, **both performance budget aggregates red** |

**Latest complete code SHA:**
`466e2c674d6e48400084ae0679c80ff08eba75ae`
([PR #59](https://github.com/infocyph/Webrick/pull/59)).
At that exact SHA, [full Security & Standards 44694283765](https://github.com/infocyph/Webrick/actions/runs/44694283765)
passed: PHP 8.4/8.5 dedicated Psalm and PHPStan analysis, all four QA matrix
combinations, clean install, and PHPForge benchmarks on PHP 8.4 and 8.5.
[PR Security & Standards 44694294175](https://github.com/infocyph/Webrick/actions/runs/44694294175)
also passed strict Sphinx and two no-dev archive consumers (PHP 8.4 and
8.5). [PR #59 dynamic run 44694291025](https://github.com/infocyph/Webrick/actions/runs/44694291025)
passed. All 452 reviewed inline PR threads were resolved as observed during
this verification, but CodeQL alert closure remains **unverified**, not
implicitly green.

**Latest runtime-release verdict:** [44694283456](https://github.com/infocyph/Webrick/actions/runs/44694283456)
**failed** at the same exact SHA. All eight SAPI/Runwire load shards,
Runwire HTTP/1–HTTP/3 protocol jobs and the 30-minute persistent-worker
soak **passed**. Nevertheless, **both** aggregate load budget jobs failed,
which is release-blocking. With acceptance budgets unchanged at maximum
**2% throughput regression**, **15% p99 increase** and **32 MiB RSS increase**,
the observed failures were:

- **SAPI:** stream/c5 p99 **+19.54%**; file/c5 throughput **-7.53%** and
  p99 **+53.08%**; range/c20 p99 **+24.85%**; risk_counter/c5 p99 **+17.88%**.
- **Runwire:** throughput static/c5 **-3.11%**, dynamic/c5 **-2.61%**,
  upload/c5 **-2.10%**, file/c5 **-11.72%**, range/c5 **-10.72%**,
  risk_allow/c5 **-8.58%**, risk_allow/c50 **-5.35%**,
  risk_clearance/c1 **-3.59%**; slow/c50 p99 **+29.86%**.

The performance matrix compares against the 6.0.4 baseline using three
trials. Moving failures between historical runs **does not** authorize
repeated retries as a remedy, weaker tolerances, ignoring workload validation
or release signoff. Profile and address reproducible causes; record
paired-trial evidence and rerun both full aggregate gates on the final source
SHA. Runtime certification artifacts: [SAPI evidence](https://github.com/infocyph/Webrick/actions/runs/44694283456/artifacts/14611437039)
and [Runwire evidence](https://github.com/infocyph/Webrick/actions/runs/44694283456/artifacts/14611496481).

**Open, not declared complete:** batch 03 attribution, batch 05
host/abort/timeout edge cases beyond the verified bounded-form regression,
batch 06 trusted deployment/activation race controls, batch 07
independent CodeQL-alert reconciliation, batch 08 profiling, batch 09
SAPI budgets, batch 10 Runwire budgets and batch 12 exact-SHA release
acceptance. Batch 11 migration guidance and package verification have
passed. No merge, release or tag is authorized.

> This ledger verifies the code at source SHA `466e2c6`. If the present
> tracker-only commit advances `feature/risk-admission`, that new commit
> must not inherit an *exact-SHA* green claim; CI on the documentation-only
> revision must be evaluated independently before final release approval.
 Append a row for each batch commit; record check-run URLs and actual statuses (pass/fail/pending), relevant failure explanations, evidence SHA, and any regression rework. Preserve prior entries. A failed batch remains open until its applicable gates are demonstrably green.

### CI trigger deduplication and runtime failure evidence — 2026-11-20

**Batch CI-FLOW (workflow-only; pending new-commit QA):** The branch `feature/risk-admission` was mistakenly listed under both the Security & Standards push trigger and the PR targeting `main`. This launched two overlapping PHPForge/static QA runs per source SHA (the push run additionally did PHPForge benchmarks; the PR run additionally did strict Sphinx and no-dev archive checks). The Security & Standards **push branch list is now restricted to `main` and `master`**, while tag pushes, Sunday schedule, workflow_dispatch, and PR events are preserved. PR QA is the single routine feature-branch QA path; release-specific full PHPForge benchmarks can still be requested via workflow_dispatch.

Runtime Release Certification on `feature/runwire` / `feature/risk-admission` continues to run for **runtime-relevant source, tests, Composer, and certification harness changes**. Docs-only, tracker-only, and unrelated CI changes no longer spend 30+ minutes on automatically launched certification. workflow_dispatch with all original durations, trials, security/protocol gates and **unchanged** throughput (2%), p99 (15%) and RSS (32 MiB) limits remains available for final exact-SHA signoff. Any final-SHA workflow dispatch is required even if an earlier source-only push passed; a skipped runtime run on a documentation-only SHA is not a green release certificate.

**Latest pre-change exact SHA:** `b42ab48d207b19bc6e91c11e0b38f3aca1bac42c`.
[PR Security & Standards 44755185458](https://github.com/infocyph/Webrick/actions/runs/44755185458) and
[push Security & Standards 44755175694](https://github.com/infocyph/Webrick/actions/runs/44755175694) both **passed** PHP 8.4/8.5 static QA at that SHA. All 454 PR inline review threads were marked resolved; code-scanning alert closure remains unverified.

[Runtime Release Certification 44755175833](https://github.com/infocyph/Webrick/actions/runs/44755175833) **failed** after all eight shard jobs, H1/H2, H3 and the 30-minute worker soak **passed**. Both aggregate budgets remain **RED** with 384 validated rows in each runtime:
- SAPI: `upload/c5` throughput **−4.50%**, p99 **+43.02%**; `upload/c50` p99 **+15.82%**.
- Runwire: 16 budget breaches, including `range/c50` throughput **−8.33%**, `not_found/c20` p99 **+50.35%**, `risk_provider_slow/c50` p99 **+45.60%**. Full official failure list: aggregate jobs #136368329254 and #136368329335.
- Compared with earlier full runs #44694283456 and #44748314007, the failure distribution changes materially. This does **not** prove failures are noise; a source-identical A/A control, CPU/runtime evidence, and attribution are needed before production optimization or final acceptance.

**Still open:** Batch 03 profiling/reproducibility, 08 measured benefit, 09 SAPI full aggregate, 10 Runwire full aggregate, 07 external security alert reconciliation, and 12 final exact-SHA acceptance. This CI cleanup does not declare any performance regression fixed and is **not** release signoff. PR #59 stays draft, unmerged and untagged.

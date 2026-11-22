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

### Final performance and release-readiness batches (P1–P4)

**Purpose:** Close the outstanding audit Batches 03, 07–10 and 12 through evidence-backed, ordered changes. This register is the actionable continuation of the original 13-section audit and the historical batch tracker. Do not erase or reinterpret historical QA evidence. All dates, source SHAs and run outcomes below are snapshots; review the live checks before updating status.

**Baseline and acceptance:** Webrick 6.0.4 trusted `main` with its documented, strictly pinned Runwire cancellation correction versus the Webrick 6.1 release candidate. Normal certification accepts at most **2% regression in successful RPM**, **15% increase in p99**, and **32 MiB increase in RSS** per workload/concurrency cell. Do not relax thresholds, modify fixtures to hide failures, drop result rows, turn off negative/correctness checks or pass a release by repeated lucky reruns. A/A is diagnostic only; it must not substitute for A/B release certification.

| Batch | Scope and predecessor | Current state | Required gate |
| --- | --- | --- | --- |
| **P1 — Measurement reliability** | Original 03; precedes P2 optimization | **Implementation committed** `b36c725`; source-identity preflight and [Security & Standards #44767415631](https://github.com/infocyph/Webrick/actions/runs/44767415631) green; [A/A #44767401254](https://github.com/infocyph/Webrick/actions/runs/44767401254) **running** at last verification | Valid and interpretable identical-source results, provenance and variance report; diagnostic reproducibility verdict explicitly recorded |
| **P2 — Profile and fix measured regressions** | Original 08, 09 and 10; depends on P1 interpretation | **NOT STARTED**; historical SAPI and Runwire budgets red | Profiler-backed changes with behavior tests; both full SAPI and Runwire aggregates pass on measured candidate SHA |
| **P3 — Release gate and security reconciliation** | Original 07 plus CI consistency; parallel investigation possible, closure after P2 | **PARTIAL**: duplicate push/PR QA removed in `24dbd0e`, [PR QA #44767415631](https://github.com/infocyph/Webrick/actions/runs/44767415631) **success**; security-alert state unverified | Gate and branch-protection check plus all security exception/alert dispositions with evidence |
| **P4 — Final exact-SHA release acceptance** | Original 12; depends on P1–P3 | **BLOCKED** by unverified A/A and red historical release performance | Every required release, package, static, security, protocol, soak and performance check green at final source SHA; no merging/tagging |

#### P1 — Reproducibility and control

- [x] **P1.1 Implement an identical-source A/A mode** using two distinct checkouts of the same commit, identical Composer lock and runtime dependencies, identical middleware admission configuration and unchanged production response validation. Implemented by `b36c725` using `http-aa-control.yml`, `load-source-control.sh`, `test-load-source-control.sh` and the existing certification workflow.
- [x] **P1.2 Verify fail-closed setup tests** for different source SHAs, modified tracked source, different lock, invalid mode and identical checkout paths. Shared dependency/preflight job passed in [A/A #44767401254](https://github.com/infocyph/Webrick/actions/runs/44767401254); this proves harness initialization only.
- [x] **P1.3 Evaluate both A/A aggregate jobs**, all eight shards and the original complete response-validation/negative-evidence matrix; capture per-trial baseline/candidate RPM, p99, RSS, variability, CPU role allocation, PHP/extension versions and sample order from the actual artifacts. Record the exact run, SHA and offending cells. A/A controls may fail the strict budgets without falsifying the release result.
- [x] **P1.4 Decide and document reproducibility:** if source-identical A/A repeatedly exceeds limits, investigate runner/CPU isolation, workload ordering, 10-second trial length and other environment effects before claiming a production regression. If A/A is reasonably stable, profile A/B. Any changed measurement methodology needs its own explicit rationale and equivalence/negative-control tests; **never** silently alter release acceptance budgets.
- [ ] **P1 acceptance:** Include a reproducibility verdict and links to the full A/A artifact/aggregate evidence. A green setup or a single accidental A/A pass is insufficient; retain baseline-to-candidate performance as independently release-blocking until verified.

**P1 source references:** `.github/workflows/http-aa-control.yml`, `.github/workflows/runtime-release-certification.yml`, `.github/certification/{load-benchmark.sh,load-source-control.sh,test-load-source-control.sh,analyze-load.php,merge-load-shards.py}`.

#### P2 — Profile and correct real throughput and latency losses

- [ ] **P2.1 Capture controlled A/B comparisons** against the pinned 6.0.4 reference, including all 16 workloads and c1/c5/c20/c50, three or more comparable paired trials, source/runtime provenance and correctness gates. Prefer data from [normal certification #44767401000](https://github.com/infocyph/Webrick/actions/runs/44767401000) when complete; earlier [#44755175833](https://github.com/infocyph/Webrick/actions/runs/44755175833) failed both aggregates.
- [ ] **P2.2 Attribute SAPI losses** using measured profiles of upload and request-body materialization, file/range streaming, emitted bytes, request/response creation, PHP-FPM/NGINX buffering and middleware invocation. Historical worst cells include `upload/c5` throughput **−4.50%**, p99 **+43.02%**, and `file/c20` throughput **−7.38%**, p99 **+61.35%** on different SHAs; do not presume a shared cause.
- [ ] **P2.3 Attribute Runwire losses** under persistent workers: compiled route dispatch, scoped/tagged middleware, request promotion, response-writing/backpressure, Redis/risk decision path and event-loop scheduling. Keep cancellation, fiber scope, HTTP/1–3, request limits and replay semantics unchanged. Historical worst cells vary between certification runs.
- [ ] **P2.4 Implement only independently justified hot-path fixes**, each as one coherent code+test+tracker commit. Use PHPForge engineering principles. Preserve optional extension behavior, public named arguments, error behavior and scoped DI lifetime. Regression tests must cover the fixed issue; no speculative broad refactor.
- [ ] **P2.5 Verify full SAPI and Runwire aggregates** with unchanged 2%/15%/32-MiB budgets, correct response accounting and all workloads. A passing load shard is **not** a passing aggregate. If P2 cannot pass after credible profiling, leave P2 RED and document the limiting evidence rather than granting an exception.
- [ ] **P2 acceptance:** Demonstrated measured improvement or defensible stability across reproductions, **both** aggregates green on the relevant source SHA, and tests green. Link exact commits and jobs.

**P2 sources:** `src/Router/Kernel/CompiledRouterKernel.php`, `src/Router/Dispatch/{RuntimeDispatcher,CompiledMiddlewarePipeline,RuntimeTaggedMiddleware}.php`, `src/Runtime/Http/{SapiRuntimeAdapter,RunwireRuntimeAdapter}.php`, `src/Response/Body/FileBody.php` and the certified workload handlers.

#### P3 — Gate correctness, provenance and security closure

- [x] **P3.1 Remove redundant feature-branch QA:** Security & Standards runs on the PR; branch push runs remain for `main`/`master`, tags, schedule and manually triggered cases. Commit `24dbd0e`; [PR QA #44767415631](https://github.com/infocyph/Webrick/actions/runs/44767415631) succeeded on `b36c725`.
- [ ] **P3.2 Verify required-check configuration and paths:** code-relevant changes start normal runtime certification, A/A is explicitly diagnostic, documentation-only updates do not claim release certification, and the final exact-SHA manual workflow remains mandatory. Review branch protections/required checks where permissions allow; note any unavailable policy evidence.
- [ ] **P3.3 Reconcile PHPForge security exceptions, Psalm taints and third-party scanning alerts** against source/test coverage. GitHub CodeQL itself does not support PHP; resolved inline review threads do not establish that third-party code-scanning findings are closed. Obtain actual alert disposition or state the remaining externally unverifiable blocker.
- [ ] **P3.4 Re-run full PHP 8.4/8.5 PHPForge QA**, strict Psalm/PHPStan, deprecations, security, Sphinx, archive and no-dev consumers after any P2 code change; record exact SHA and check URLs.
- [ ] **P3 acceptance:** Single authoritative normal QA path, documented required-release gate, independently reconciled security disposition, and no suppressed/relaxed requirements.

#### P4 — Release acceptance at one final source SHA

- [ ] **P4.1 Pin the final SHA** after all implementation/rework and tracker commits. Record installed versions, trusted baseline SHA, performance harness provenance, PHP 8.4/8.5 extension matrix and all required workflow identifiers.
- [ ] **P4.2 Run full Security & Standards/PHPForge certification**, including static analysis, tests, applicable security checks, full PHPForge benchmark matrix, strict Sphinx, distributable archive and both clean no-dev consumer versions. A PR's abbreviated QA check does not replace release-only benchmark jobs.
- [ ] **P4.3 Run complete Runtime Release Certification** at exactly the pinned source SHA: H1/H2/H3, 30-minute persistent-worker soak, eight load shards and both SAPI/Runwire **aggregate** performance checks, all successful. Optional self-hosted deployment H3 remains explicitly conditional.
- [x] **P4.4 Reconcile remaining original audit qualifications** (host-controlled timeouts/abort behavior, immutable artifact activation, external security findings) and document whether they are resolved, deployment-owned or genuinely release-blocking. Do not silently interpret missing evidence as a pass.
- [ ] **P4.5 Update the append-only tracker with exact SHA, commit list and links** and publish an explicit READY or BLOCKED disposition. Any subsequent source or documentation commit advances HEAD and requires final-SHA verification again.
- [ ] **P4 acceptance:** Full evidence set green **at the exact final SHA** with no missing mandatory check. Keep PR #59 draft and do not merge, tag or publish unless separately authorized.

#### Decision and execution contract for P1–P4

| Observation | Next action | What must remain blocked |
| --- | --- | --- |
| A/A has reproducible budget failures | Fix or isolate measurement setup; do not mistake A/A noise for a production regression | P2 release acceptance, P4 |
| A/A reproducible, A/B failing | Profile and fix the actual source regression; retest with valid response/latency data | P4 |
| A/A reproducible and A/B passing | Complete P3 evidence and exact-SHA P4 certification | Merge/tag until P4 |
| Results mixed or inconclusive | Preserve artifacts; add controlled trials/attribution and record uncertainty | Release-ready claim |

**Batch discipline:** One coherent batch = one commit including tests and appended tracker entry. Sync check-run links before calling a batch verified. A batch failing CI remains open until repaired and verified. Continue P3 security work in parallel only when it does not interfere with P1/P2 measurements. All performance acceptance gates remain unchanged; do not trade correctness for benchmarks.

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

### Batch P1 — source-identical A/A diagnostic harness (2026-11-20)

**Implementation committed, A/A results pending (not a release signoff).** The existing Runtime Release Certification workflow is now reusable with an explicit `workflow_call.inputs.aa_control` boolean (default false). Its regular `push` and `workflow_dispatch` paths keep the original `main` baseline and native-versus-middleware admission modes; 2% throughput, 15% p99 and 32-MiB RSS budgets remain unchanged. In A/A mode, the same current SHA is checked out twice, both sides use middleware admission, both Composer locks and tracked source are verified identical, and separate process roots/ports are required. All 16 workloads, four concurrencies, three paired trials, correction/negative-evidence tests and full analyzer still run unchanged. Only unrelated Runwire H1/H2/H3 and soak jobs are skipped in the diagnostic mode; those remain required in normal release certification.

The separate `.github/workflows/http-aa-control.yml` wrapper runs once on these diagnostic/tooling changes or can be dispatched; it reuses the existing dependency, load and aggregate jobs instead of duplicating the 787-line release workflow. `load-source-control.sh` contains fail-closed selection; `test-load-source-control.sh` checks release defaults, positive A/A identity, invalid control values, modified source, mismatched Composer lock, same checkout path, and divergent revisions. New A/A provenance includes `aa-identity.txt` and `environment.txt`. The full A/A run is allowed to be **red** if the source-identical measurements exceed current budgets; such a result diagnoses reproducibility and does not make a release pass. A/A passing is also not proof that candidate-versus-6.0.4 budgets pass.

**P1 acceptance:** Confirm the source-identical control actually ran at the new SHA, test job and per-runtime complete aggregate verdicts, then inspect all trial-level evidence. If A/A fails, investigate CPU/runner and time-order bias before changing production code. If reliably clean, proceed to P2 targeted regression profiling. Record exact job URLs and do not mark P1 verified until current-SHA CI evidence exists. PR #59 remains draft, unmerged and untagged.

### Batch P1-REWORK — eliminate known three-trial first-position bias (2026-11-21)

**Evidence at source `b36c725`:** [A/A #44767401254](https://github.com/infocyph/Webrick/actions/runs/44767401254) completed with all eight load shards successful, but **both A/A aggregates failed** on 384 correct rows each: SAPI 4 workload/concurrency budget breaches (maximum throughput **−3.80%**), Runwire 17 (worst throughput **−9.79%**; worst p99 **+44.98%**). This is a *false regression against identical code*, not proof that all A/B problems are noise. [A/B release #44767401000](https://github.com/infocyph/Webrick/actions/runs/44767401000) passed SAPI aggregate and all H1/H2/H3/30-minute soak checks, but Runwire `risk_clearance/c5` still failed (**4.69% throughput regression**).

**Concrete source of bias:** Original `load-benchmark.sh` used three trials and alternating pair order baseline→candidate, candidate→baseline, baseline→candidate. This gives the baseline **two first-position samples** and candidate **one**. On source-identical Runwire `upload/c50`, baseline/candidate RPS samples were 6596/5866 (trial 1), 5893/6456 (trial 2), and 6482/5826 (trial 3): **the first measured side won in all three trials**, indicating a systematic order effect. In addition, baseline was **always started/warmed first** even in candidate-first trials. This explains why taking a median from just three trials can report a false multi-percent regression; it does not determine the complete residual noise distribution.

**Corrective work:** Require at least four **even** paired trials, default release and A/A jobs to four, fail closed on odd/invalid counts, and alternate **server startup/warmup order as well as the measurement order**. Store exact `pair-order.txt` in every concurrency shard. Add `test-load-pairing.sh` negative/balanced-order tests to mandatory pre-load QA. Keep workload fixtures, per-response checks, concurrency levels, 2% RPM, 15% p99 and 32-MiB RSS acceptance limits unchanged. Do not call this an A/A pass until the next complete certification actually passes or its remaining variability is separately diagnosed.

**P2 in parallel:** `risk_clearance/c5` is the only failure in the contemporaneous A/B result. Because the *same* source violates budgets under A/A, isolate order and environmental variance before attributing that 4.69% figure to `RiskAdmissionMiddleware`; meanwhile review and profile its handler, Redis I/O and dispatch without speculative behavior changes. This P1-REWORK commit is not final release acceptance.

**P3 evidence:** [PR Security & Standards #44769662191](https://github.com/infocyph/Webrick/actions/runs/44769662191) passed at `2b25383` and [PR #59 dynamic check](https://github.com/infocyph/Webrick/pull/59) was green. GitHub REST `/rulesets` returned an empty list; `/branches/main/protection` returned **403 Resource not accessible by integration**. Therefore required-check/branch-protection enforcement cannot be certified through this connection, and external code-scanning alert closure remains unverified. These are explicit P3 qualifications, not silently closed findings.

### Batch P1-PORT — crossed runner/port confounder control (2026-11-21)

**Observed on prior P1 correction SHA `7c08669`:** [balanced four-trial A/A #44860227430](https://github.com/infocyph/Webrick/actions/runs/44860227430) **failed** despite 512/512 rows and successful shard jobs. It breached the unchanged strict budget in SAPI **3** cells (worst throughput `not_found/c50` **−5.36%**, p99 **+20.18%**) and Runwire **26** cells (worst throughput `file/c50` **−10.82%**, `risk_login_invalid/c20` p99 **+89.17%**). Thus equalizing first/second position alone was insufficient. From the full A/A Runwire evidence, `file/c50` RPS baseline/candidate pairs were **6268.6/5467.6**, **6178.4/5516.4**, **5494.5/6195.0**, **6150.0/5477.7** across trials 1–4: the same source code is materially sensitive to benchmark session/role. Do not call this a product regression or ignore its significance.

**Independent A/B evidence:** [Runtime #44860227479](https://github.com/infocyph/Webrick/actions/runs/44860227479) completed all eight shards and protocol/soak checks successfully but both aggregates failed on four-trial evidence. SAPI `static/c20` p99 **+19.61%**; Runwire 16 breaches including `file/c50` throughput **−10.07%**, `range/c50` **−9.19%**, and `risk_provider_slow/c5` p99 **+37.69%**. Strong overlap with false A/A `file`/`range` bias means production-function attribution is not yet justified. P2 remains open, with profiling explicitly preferred once controlled evidence is obtained.

**P1-PORT change:** Force each group of four trials to cross **two independent variables**, (1) baseline-first vs candidate-first, and (2) baseline on lower vs higher loopback port. Trial 1 baseline-first/lower-port, 2 candidate-first/lower-port, 3 candidate-first/higher-port, 4 baseline-first/higher-port (repeat per group of four); both startup/warmup and load order follow the defined position. Restrict the accepted trial counts to **4, 8 or 12**, recording both ports in the per-trial `pair-order.txt` artifact. Extend executable negative controls in `test-load-pairing.sh` and A/A wrapper path filters. Keep separate checkouts, pinned dependencies, CPU-role isolation, 16 workloads, all response/negative-fixture tests, original 2%/15%/32 MiB budgets, and exact-SHA gate untouched.

**Acceptance remains open:** Run source-identical A/A and normal A/B on this new source SHA; compare residual role/port bias and trial variability. If A/A still fails, measure host/runner effects (CPU pressure, process metrics and source-root effects) and do not mistake rerun luck for a fix. SAPI, Runwire and P4 acceptance remain blocked until their actual aggregate jobs are green. P3: GitHub `main` reports `protected=true`, but integration 403 prevents seeing required contexts or external scanning alert states; all 454 reviewed threads resolved and SHA `7c08669` strict PHP 8.4/8.5 QA green.

### P1–P3 final-worker evidence and measurement decision — 2026-11-21

**Disposition: implementation/static QA passes; performance and runtime reliability are NOT certified.** All workers reviewed at exact PR HEAD `c2f2da781668f2343ee9594e958e30a3e2e0049f`. This section supersedes historical "running"/"pending" states; it does not rewrite the earlier audit ledger.

| Source and test | Exact run | Result | Valid measurements / exceptions |
| --- | --- | --- | --- |
| main 6.0.4 source-identical SAPI control | [#44863919923](https://github.com/infocyph/Webrick/actions/runs/44863919923), isolated branch `perf/main-6.0.4-aa-20261121`, measured SHA `981d095` | **PASS** | Complete 320/320 rows, original 10-workload main harness, strict 2%/15%/32-MiB checks |
| main 6.0.4 source-identical Runwire control | same run | **INVALID/FAILED TRANSPORT, NOT A BUDGET VERDICT** | **304/320** rows; `curl: (56) Recv failure: Connection reset by peer` while reading baseline port 18082 telemetry. Unexpected Runwire worker `application_fatal` exit **70** with restarts appears on both identical-source sides in archived supervisor events, multiple trials. Runwire `StreamWriter.php` logs numerous broken-pipe notices. The gate never reached the final comparison. Preserve this as a hard reliability investigation, not a statistical outlier or a 6.1 source regression |
| 6.1 source-identical A/A SAPI | [#44863460405](https://github.com/infocyph/Webrick/actions/runs/44863460405), `c2f2da7` | **PASS** | 512/512 valid rows, 0/64 comparison cells exceeding any budget |
| 6.1 source-identical A/A Runwire | same run | **FAIL** | 512/512 valid rows, **24/64** cells breach budgets (21 throughput and 3 p99). Worst throughput `file/c50` **11.20%**; `risk_login/c20` p99 **+89.06%** against identical source. Median across cells is approximately zero and 32/64 cells favor each side, indicating no broad directional regression but materially unreliable per-cell results |
| 6.0.4→6.1 SAPI A/B | [#44863460372](https://github.com/infocyph/Webrick/actions/runs/44863460372), `c2f2da7` | **FAIL** | 512/512 rows, **2/64** cells: `static/c50` throughput **−2.62%** and p99 **+22.44%**, `stream/c50` throughput **−2.97%** |
| 6.0.4→6.1 Runwire A/B | same run | **FAIL** | 512/512 rows, **7/64** cells: throughput `static/c5` **−2.07%**, `file/c5` **−2.41%**, `range/c5` **−7.33%**; p99 `risk_login/c5` **+29.66%**, `risk_login_invalid/c20` **+30.53%**, `risk_provider_slow/c5` **+77.26%**, `risk_provider_slow/c20` **+87.67%**. Candidate improves median throughput **~1.44%** across cells and is faster in **55/64**, but this is not an all-cell non-regression proof |
| PR Security & Standards | [#44863466561](https://github.com/infocyph/Webrick/actions/runs/44863466561), `c2f2da7` | **PASS** | PHP 8.4/8.5 QA, Psalm/PHPStan, Sphinx, archived no-dev consumers; PR-level PHPForge benchmark intentionally skipped, so full final release gate is still outstanding |

**Interpretation and correction:** The *independent* 6.0.4 Runwire control failed before analyzing its budget; never report it as a completed performance comparison. Its original main harness covers 10 workloads, whereas the 6.1 harness tests 16 (including admission fixtures), uses split concurrency shards and additional hardening. Thus its findings support reliability diagnosis but are **not** a directly equivalent A/A control of 6.1's full matrix. The completed **6.1** Runwire A/A *is* direct evidence of unacceptable measurement variance under this 64-cell/all-must-pass gate. Static QA green ≠ runtime/performance certified.

#### Proposed next measurement contract (NOT implemented; limits unchanged until tested)

- [x] **P1.R1 — Reliability hard gate first.** Reproduce and attribute original-main Runwire `application_fatal` worker exit 70, transport resets and broken pipes, including test request IDs/fixture, process stderr and supervisor event times. Unexpected worker death, incorrect/missing responses, incomplete result matrix, queue leaks, and failed protocol/soak remain unconditional failures even in A/A or exploratory mode. Do not paper over this failure as mere p99 noise.
- [ ] **P1.R2 — Source-identical calibration** on isolated, identically provisioned, single-purpose runners with pinned CPU affinity, PHP, extensions, Runwire, dependency lock, host configuration and full provenance. Complete multi-run A/A repeatability with randomized/crossed order/port/process roles; record role effects, paired samples and false-positive rate. Do not use a single lucky A/A pass as a reliability certificate.
- [ ] **P1.R3 — Statistically defensible workload assessment.** Keep 2% successful-RPM non-inferiority and 15% p99 regression as **performance objectives**, not waived; validate that per-workload uncertainty permits meaningful decisions. Use paired confidence intervals/non-inferiority decision rules or repeatable evidence across independent runs, with an explicit inconclusive state when uncertainty straddles the objective. Preserve absolute latency service-level limits for meaningful tails (a relative % alone can mislead for submillisecond or saturated slow-provider paths), fixed 32-MiB RSS ceiling, and every response/negative-control requirement. Determine trial count/duration from measured variance, not a convenient green outcome; use reproducible latency histograms or clearly scoped per-trial p99 instead of mislabeling pooled p99.
- [ ] **P1.R4 — Distinguish correctness, stress and throughput scenarios.** Routine functional/protocol/soak gates stay hard; stable representative steady-state throughput/latency tests provide release non-inferiority; saturated c50, deliberately slow-provider, bcrypt/Redis, and cancellation/adversarial cases retain correctness and operational thresholds even where their CI-runner throughput variance exceeds the desired statistical resolution. Never drop high-concurrency or security workloads merely to turn green.
- [ ] **P2.R1 — Targeted production investigation in parallel.** Profile `range/c5` and `file/c5`, plus provider/login p99 under controlled c5/c20 Runwire load after stable A/A calibration; look at response writer, scheduling/CPU and Redis/provider contention. Separate a reproducible feature regression from baseline worker instability. Fix only measured source defects with tests, preserving Webrick and Runwire public contracts.
- [ ] **P3.R1 — Gate truth and P4 handoff.** Update CI so source-identical controls are an explicit **measurement-calibration status**, not an A/B release pass; unexpected worker exits, corrupted/missing responses and incomplete artifacts stay red. Final release runs at the **same final SHA**, with static, package, security, protocol/soak, and calibrated performance evidence. GitHub branch-protection-admin read permissions are **not needed to execute this work**; they only limit independent auditing of repository settings.

**No budget changed, no pass inferred, no merge/tag.** P1 reliability methodology and worker-fatal diagnosis OPEN; P2 performance attribution OPEN; P3 executable CI and static QA GREEN but final gate/provenance still to be calibrated; P4 BLOCKED. Preserve original test archives, source SHAs and per-workload failure records.

### Runwire worker exit 70 — confirmed Webrick 6.0.4 cause (2026-11-21)

**Disposition: Webrick 6.0.4 asynchronous cancellation-boundary defect; fixed on PR #59's 6.1 branch. Runwire supervisor is not responsible for this particular unexpected exit.** Keep throughput/p99 comparisons out of this incident's corrective scope as requested; all independent performance release gates remain separately tracked, not implicitly waived.

**Controlled source-identical reproduction:** The independent [Runwire worker-fatal diagnostic run #44873179908](https://github.com/infocyph/Webrick/actions/runs/44873179908) ran against pinned Webrick 6.0.4 source SHA `981d095251dc3bf9c65c434f0d84aecb38025f88`, Composer Runwire **3.0.1**, and PHP 8.5, using the isolated `perf/main-6.0.4-aa-20261121` branch at `bf957cf`. The diagnostic temporarily (only inside CI-installed vendor code) added **exception class + basename + line** logging to Runwire's caught worker fatal; no exception messages, credentials, request bodies or other secret payloads were emitted.

- [Released Runwire 3.0.1 variant](https://github.com/infocyph/Webrick/actions/runs/44873179908): reproducible unexpected `worker_exited` with `exit_code:70`, `exit_reason:application_fatal` under the c20 HTTP workload, and **45** broken-pipe notices. Fatal source:
  `RUNWIRE_WORKER_FATAL depth=0 type=Infocyph\\Webrick\\Runtime\\Http\\RunwireTransportCancellation file=RunwireRuntimeAdapter.php line=236`.
- **Notice-only correction variant** (same Webrick 6.0.4/Runwire 3.0.1, ephemeral vendor change to also contain expected `E_NOTICE` `fwrite():` diagnostics): same `RunwireTransportCancellation` source **still caused exit 70**, with **zero** broken-pipe notices. Thus the noise is a separate Runwire 3.0.1 stream writer issue, **not the fatal root cause**.
- Earlier [main 6.0.4 A/A #44863919923](https://github.com/infocyph/Webrick/actions/runs/44863919923) recorded repeated `application_fatal` exits at elapsed ~272s and ~374s for both identical-source checkouts. Exact workload timing differs under the focused shorter setup; the exception-origin evidence—not temporal coincidence—establishes attribution.

**Source-level failure mechanism:**

1. `Webrick 6.0.4 src/Runtime/Http/RunwireRuntimeAdapter.php:236` converts a transport-closed response write to `RunwireTransportCancellation`. This exception is an expected per-request disconnect, not a worker-fatal application error.
2. In **6.0.4** `RunwireRuntimeApplication::handle()`, the `catch (RunwireTransportCancellation)` is **outside** `RunwireResponseContinuation::run(...)`. When the response producer suspends for body/drain readiness and later resumes outside the original call stack, the exception bypasses that catch and escapes the request.
3. Runwire `Http1Connection::dispatchRequest()` aborts the connection and rethrows the uncaught application exception; `WorkerChildRuntime::run()` then exits with code 70. Runwire correctly reports this unhandled application exception through its restart policy, even though its worker-fatal diagnostic detail is insufficient.
4. **6.1 already fixes this**: `src/Runtime/Http/RunwireRuntimeApplication.php:74-88` moves the transport-cancellation and transport-owned `CancelledException` handlers **inside the continuation closure**. The matching regression coverage is present in `tests/Unit/RunwireRuntimeApplicationTest.php` (asynchronous transport closure and next-request usability) and `tests/Integration/RunwireResponsePressureCancellationTest.php`. The 6.1 Runwire multi-workload load shards, H1/H2/H3, and 30-minute persistent-worker soak completed their lifecycle/correctness checks green on [#44863460372](https://github.com/infocyph/Webrick/actions/runs/44863460372). That run's failed *performance aggregates* are unrelated to this fatal-lifecycle disposition.

**Ownership and remaining actions:** **Webrick** owns the original worker crash; the corrective code/tests are **already present and passing** in 6.1, so do not introduce redundant production changes. The non-fatal broken-pipe notice leakage (`Runwire 3.0.1 Network/Internal/StreamWriter.php` checks only `E_WARNING`, while PHP emits `E_NOTICE`) and worker-fatal telemetry omission belong to **Runwire**. This GitHub installation returned **403** for both creating a Runwire fix branch and opening a Runwire issue; no Runwire source change or upstream fix is claimed. Keep those separate upstream follow-ups rather than attributing exit 70 to Runwire.

**Incident status: fixed in Webrick PR #59, not backported to protected `main` / `master`.** The detached `main`-based reproduction branch is diagnostic only. PR remains draft and unmerged, and no performance comparison threshold has been weakened or changed.


### RW31 — Runwire 3.1 minimum and functional worker acceptance (2026-11-22)

Runwire [3.1](https://github.com/infocyph/Runwire/releases/tag/3.1) has published with the previously requested bounded fatal diagnostics and broken-pipe notice handling. PSR outbound bridge exact-URI authorization is a new direct-caller requirement; GitHub code search found no direct PsrMessageBridge usage in Webrick.

Implementation: raise the optional `require-dev` Runwire minimum to `^3.1`, retain the no-dev production package contract and add `.github/workflows/runwire-3.1-integration.yml`. The workflow separately checks PHP 8.4/8.5 cancellation, stream pressure, deployment, live H1/H2, file range, client abort recovery and zero unexpected worker exits. No throughput comparison metrics or acceptance budgets are changed.

Tracking: RW31 committed; exact-SHA GitHub CI results pending. Existing 6.0.4 worker exit 70 issue was attributed to Webrick asynchronous cancellation outside its continuation and is already corrected in 6.1. User-requested performance comparison pause remains in effect; no merge or tag.


### Batch RW31-AN — Psalm analyzer drift cleanup (2026-11-22)

At Runwire 3.1 candidate SHA `4992ac69`, functional acceptance [#45021857708](https://github.com/infocyph/Webrick/actions/runs/45021857708) **passed on PHP 8.4 and PHP 8.5**, with actual installed Runwire `3.1.0.0`, 34 targeted tests (323 assertions) each, live HTTP/1.1 and HTTP/2, file/range/cancellation, and no unexpected worker exits. No performance comparison claims are made.

Strict Sphinx, archive no-dev consumer and clean install passed. The independently refreshed PHPForge/Psalm run [#45021857845](https://github.com/infocyph/Webrick/actions/runs/45021857845) initially reported **29 explicit Psalm findings**, confined to four preexisting test files: AttributeRouteLoaderTest (4), DeepCorrectnessBatchATest (1), NativeFileBodyTest (10), PreRoutingGateTest (14); PHPStan passed. These are redundant nullable-safe operations after executable non-null assertions plus one redundant string cast, not Runwire behavioral failures. This batch simplifies those 4 files without suppressions or changing semantics. Verification of this exact new source SHA is pending.

No performance methodology, worker supervisor behavior, public API, thresholds, or protected main/master branches were changed.


### Batch RW31-AN2 — reconcile PHPStan and Psalm runtime type narrowing (2026-11-22)

At SHA `71afe36340517cdcf655dc07206c51d33b6d3a2d`, renewed [Security & Standards #45022179996](https://github.com/infocyph/Webrick/actions/runs/45022179996) reported **Psalm PASS on all PHP 8.4/8.5/8.6 lanes**, but **PHPStan FAIL (15 method-on-null/string-null findings)** across exactly the same four test files. The former Pest `expect(...)->not->toBeNull()` was honored as narrowing by Psalm but not by PHPStan; replacing the former null-safe calls with direct calls was therefore insufficient.

Corrective action: enforce explicit executable null/type guards **before** subsequent test expressions, retain exact behavioral comparisons, eliminate redundant Pest assertions, and avoid skip/suppression directives. This is a test-only source change; no runtime logic, API, dependency, or CI acceptance threshold is altered. New-source-SHA analysis and functional CI must be verified before closing the batch.


### RW31 final functional and static QA acceptance — 2026-11-22

**Verified source SHA:** `dde5e1457ad8904241a2dad2437b6497312d9677` ([PR #59](https://github.com/infocyph/Webrick/pull/59)). All relevant **non-performance** jobs below completed successfully at that same code SHA:

| Gate | Exact GitHub Actions evidence | Verdict |
| --- | --- | --- |
| Runwire 3.1 functional integration | [Run #45022432337](https://github.com/infocyph/Webrick/actions/runs/45022432337) | **PASS**, PHP 8.4 and 8.5; Runwire resolved to `3.1.0.0`, 34 tests/323 assertions each plus live H1/H2, file/range, cancellation and zero unexpected worker exits |
| Security & Standards | [Run #45022432765](https://github.com/infocyph/Webrick/actions/runs/45022432765) | **PASS**, Sphinx, archived no-dev consumer, clean install, all QA PHP 8.4/8.5/8.6 prefer-lowest and prefer-stable, dedicated Psalm/PHPStan analysis in all three versions |
| PR checks | [Run #45022431061](https://github.com/infocyph/Webrick/actions/runs/45022431061) | **PASS** |

**Attribution and closure:** Runwire 3.1 includes broken-pipe and fatal-diagnostic fixes, and Webrick 6.1 retains the corrected asynchronous cancellation boundary. Raising the optional dev-only dependency to `^3.1` and the live functional integration verification is complete. The 29 original Psalm failures and subsequent 15 PHPStan failures were resolved in test code without suppressions or behavior changes. The isolated P1/P2 benchmark noise investigations and comparative throughput budgets remain recorded but are **out of scope** at the user's explicit direction; their separately running/failing workflow cannot be silently counted as a release green. This tracker-only update may advance HEAD beyond the verified code SHA, so no new exact-SHA claim is made for its own CI until observed. No merge, tag or release.


### RW86 — PHP 8.6 GA and default functional verification (2026-11-22)

**Confirmed upstream:** [PHP 8.6.0 GA](https://www.php.net/releases/8.6/en.php) shipped 2026-11-19. [PHPForge default runtime manifest](https://github.com/infocyph/PHPForge/blob/main/resources/runtime.php) already supplies `[8.4, 8.5, 8.6]` and the reusable PHPForge Security & Standards workflow uses this full manifest when `php_versions` is not overridden. Webrick [Security & Standards #45022432765](https://github.com/infocyph/Webrick/actions/runs/45022432765) passed all three QA/analysis versions before this CI-only update.

**Changes in this batch:** make PHP 8.6 the primary archived no-dev consumer and Sphinx workflow PHP runtime (`security-standards.yml`), while retaining the additional 8.4 no-dev consumer. Expand live Runwire 3.1 cancellation/HTTP/worker-lifecycle functional integration from PHP 8.4/8.5 to **8.6, 8.5, 8.4**. Keep Webrick composer requirement `php: ^8.4`, which supports all three. The benchmark comparison workflow's PHP 8.5 host and PHP-FPM binaries remain intentionally pinned to avoid changing the baseline-to-candidate measurement environment during the user-directed performance-comparison pause. Do not conflate PHP 8.6 CI default with raising Webrick's PHP minimum.

**Acceptance:** new exact-SHA PHP 8.6/8.5/8.4 Runwire functional matrix, full PHPForge Security & Standards (including strict Sphinx and both archive consumers), and PR checks must pass; record actual run IDs and outcomes, not assumptions. No performance threshold, product runtime, public API, main/master, release tag or merge changes.


### RW86 exact-source acceptance — 2026-11-22

At verified PHP 8.6 adoption source SHA [`d0bf46f`](https://github.com/infocyph/Webrick/commit/d0bf46f7b9c05e80c5317629266b598ae0f380d1), all non-performance gates completed with **SUCCESS**:

| Verification | Exact run | Outcome |
| --- | --- | --- |
| Runwire 3.1 Functional Integration, PHP 8.6, 8.5, 8.4 | [#45023111962](https://github.com/infocyph/Webrick/actions/runs/45023111962) | **PASS** all 3 jobs, including live worker/cancellation/HTTP correctness checks |
| Security & Standards (PHPForge default 8.4–8.6) | [#45023112276](https://github.com/infocyph/Webrick/actions/runs/45023112276) | **PASS** dedicated Psalm/PHPStan 8.4/8.5/8.6, prefer-stable/prefer-lowest QA, clean install, strict Sphinx and both archive consumers (main smoke on PHP 8.6; compatibility smoke on PHP 8.4) |
| PR #59 integration checks | [#45023108201](https://github.com/infocyph/Webrick/actions/runs/45023108201) | **PASS** |

PHP 8.6 is now the primary no-dev archive validation runtime and included in the Runwire 3.1 functional test matrix; the supported minimum remains PHP 8.4 (`^8.4`). PHPForge `resources/runtime.php` already includes 8.4/8.5/8.6, so no PHPForge changes were needed. Historical performance tests retain a pinned PHP 8.5 comparable baseline, per the explicit decision to leave performance comparisons alone. These successful results apply to the exact code SHA **d0bf46f**, not to this subsequent tracker-only commit until its own CI completes. No protected-branch modification, automatic merge, tag, or release.


### P4 — explicit final-SHA acceptance launch (2026-11-22)

**User decision:** Run **all** Webrick 6.1 acceptance tests now that Runwire 3.1 is published and the prior Webrick cancellation/worker-crash root cause is corrected. Do **not** reinterpret historical performance-comparison failures as passing or relax 2% successful-throughput, 15% p99, or 32 MiB RSS limits.

**Trigger and isolation:** Add `.github/workflows/release-acceptance.yml`, a dedicated self-triggered `push`/manual wrapper that invokes the existing `runtime-release-certification.yml` reusable workflow at the same branch commit with `aa_control: false` and its complete reference comparison, 4 paired trials, load matrix, correctness checks, live H1/H2/H3 and 30-minute worker soak. Extend the separate `.github/workflows/http-aa-control.yml` push path filter to include the new trigger, so its existing **identical-source** reusable A/A control also runs on the same SHA in a **different GitHub Actions run** (keeping artifact names isolated). Extend `.github/workflows/runwire-3.1-integration.yml` PR filter to start the 8.4/8.5/8.6 lifecycle matrix on the same SHA. Security & Standards already runs unfiltered on PR changes; it will certify all QA/analysis/docs/no-dev lanes. No product source, dependency constraints, benchmark thresholds, measurement scripts, public API, main or master is changed.

**Acceptance rules and pending evidence:** Capture the resulting four exact-SHA workflow runs and all job verdicts, including explicit regression-budget outcomes. All mandatory non-performance jobs, load shards, aggregate performance checks, protocol/soak and exact-SHA provenance must pass before release readiness can be stated. A/A is diagnostic, never an A/B substitute; a failed Runwire A/A is important uncertainty evidence, not an automatic waiver of release thresholds. If a certification fails, retain artifacts, classify the **actual** issue, and fix it on this PR branch without merging/tagging. At commit creation these checks are **launched / awaiting live GitHub results**, not presumed green.

### P4.1 — CPU-isolated acceptance investigation after failed final run (2026-11-22)

**Observed exact source SHA:** `e503067d8eff066439b8f807c4c268519045ef63`. Completed [release #45024833214](https://github.com/infocyph/Webrick/actions/runs/45024833214) and [A/A #45024833080](https://github.com/infocyph/Webrick/actions/runs/45024833080) **both FAIL** their final performance comparison aggregates, while **all eight load shards per suite, HTTP/1–3, the 30-minute Runwire soak, Security & Standards #45024842192, Runwire 3.1 PHP 8.4–8.6 #45024841466 and PR checks #45024837571 PASS**.

- Release SAPI: exactly **1/64** cell, `risk_clearance/c50` throughput **−3.93%**.
- Release Runwire: **12 budget violations** (e.g. `upload/c50` **−8.36%**, `file/c50` **−9.22%**, `range/c50` **−9.11%**, `json/c50` **−5.52%**, `stream/c20` p99 **+63.53%**). Most failed c50 throughputs are consistent across paired trials; do **not** classify the entire A/B deficit as false noise.
- Identical-source A/A SAPI: **3 violations**, including `file/c20` throughput **−7.93%**, p99 **+58.42%**.
- Identical-source A/A Runwire: **6 violations**, including `risk_login/c5` p99 **+38.12%** and `risk_login_invalid/c5` p99 **+22.10%**. The same-source A/A `file/c5` *favors* candidate by ~12%, and median comparisons at c50 generally show much smaller differences: evidence of workload/concurrency-dependent measurement role effects, not proof of no true 6.1 regression.
- Raw evidence: [A/B Runwire](https://github.com/infocyph/Webrick/actions/runs/45024833214/artifacts/14756383067), [A/B SAPI](https://github.com/infocyph/Webrick/actions/runs/45024833214/artifacts/14756218475), [A/A Runwire](https://github.com/infocyph/Webrick/actions/runs/45024833080/artifacts/14756441903), [A/A SAPI](https://github.com/infocyph/Webrick/actions/runs/45024833080/artifacts/14756706872). All complete 512/512 rows; no socket errors or missing correctness evidence; no RSS violation.

**Next controlled experiment:** The reusable runtime release workflow gains an **opt-in** `benchmark_cpu_isolation` `workflow_call` input. On opted-in same-run load shards, derive the actual Linux scheduled CPU affinity with `os.sched_getaffinity(0)`, fail closed if fewer than four available CPUs, reserve the final **two** CPUs exclusively for the two-thread `wrk` client, and assign all other available CPUs to both baseline and candidate servers. Validate the disjoint sets with `taskset`, record `affinity.txt` alongside performance evidence. Both release A/B and identical-source A/A wrappers explicitly opt in; unrelated manual/push caller defaults do not silently change. Trigger independent exact-SHA A/B and A/A suites with **unchanged 16 workloads, four paired trials, 10-second measurements, 2% throughput/15% p99/32-MiB RSS budgets, correctness checks, HTTP/1–3 and soak**. This isolates client/server scheduling contention rather than relaxing standards or modifying production source.

**Decision rule:** A/A calibration must be interpreted before making claims about A/B source performance; persistent c50 A/B deficits after A/A stabilizes require focused candidate hot-path profiling and fixes. A/A failure means calibration is still open, not an automatic A/B waiver. No merge/tag/release before final acceptance. Exact run IDs/QA outcomes for this new experimental SHA remain pending.


### Pre-benchmark closure gate — 2026-11-22

**User-directed execution order:** Finish the non-benchmark unfinished plan items before any new A/A, A/B, profiling or soak measurements. All performance workflows on the PR branch are explicitly **manual-only** for this phase: `.github/workflows/runtime-release-certification.yml` no longer auto-runs on `feature/risk-admission` pushes, and its A/A and release wrappers no longer have branch-push triggers. `workflow_dispatch` is preserved; post-merge main/master security QA and unrelated branch gates are unchanged. This removes accidental 30-minute performance runs on planning/test commits. No threshold, source/runtime code or benchmark workload changed.

**Audit inventory:** Before this batch the P1–P4 checklist had exactly **28 entries (3 complete; 25 open)**. This batch closes **P1.R1 only**, supported by [exit-70 diagnostic #44873179908](https://github.com/infocyph/Webrick/actions/runs/44873179908), the [root-cause attribution recorded in this plan](https://github.com/infocyph/Webrick/pull/59#issuecomment-7058330818), Runwire 3.1's separate diagnostic and `fwrite` fixes, and Webrick 6.1 async-cancellation regression tests with [functional CI #45029940474](https://github.com/infocyph/Webrick/actions/runs/45029940474). The source-identical Webrick 6.0.4 test reproduced `RunwireTransportCancellation` at `RunwireRuntimeAdapter.php:236` exiting code 70; the corresponding 6.1 catch was moved inside the response continuation; HTTP/1–3 and the 30-minute soak remain fail-closed. If later work yields a new unexpected worker death, reopen P1.R1.

**Pre-benchmark work (can be completed without *new* performance measurements):** reconcile recorded A/A/A/B raw evidence and provenance (P1.3; do not assert calibration passed); confirm reproducibility verdict with demonstrable harness-rooted effects (P1.4/P1 acceptance as *diagnostic* tasks, not as performance certification); verify CI triggers and available branch-policy evidence (P3.2); reconcile first-party PHPForge exception policy and existing source/behavior tests and explicitly record what third-party alert state cannot be retrieved (P3.3); verify PHP 8.4/8.5/8.6 standard QA already green and repeat if code changes (P3.4 provisional); reconcile host-owned timeout and artifact deployment responsibilities (P4.4); classify stress vs stable workloads and design a repeatability/statistical decision contract (P1.R3/R4), and implement harness regression tests without running performance measurements.

**Benchmark-dependent tasks cannot truthfully be completed *before* benchmarks:** P1.R2 (multi-run A/A repeatability), P2.1–P2.5/P2 acceptance (source performance profiling/fixes/final measured green), P2.R1, P4.1–P4.3/P4.5/P4 acceptance (final pinned SHA and exact-SHA release certification), and downstream P3/P3.R1 signoff. These remain open, explicitly dependent on the pre-benchmark gate. Marking them checked before actually obtaining measurements would falsify the release acceptance. Performance objectives remain 2% successful RPM, 15% p99, 32 MiB RSS, with strict correctness and worker lifecycle.

**External access limitation:** GitHub branch-protection REST endpoint returned **403 Resource not accessible by integration**, and GitHub code/secret-scanning-alert endpoints are not available to the connected repository fetch action. Do not claim the remote required-check/alert policy is green solely because normal CI passed; record the verification boundary for P3.2/P3.3/P4.4.

**Current candid status:** 28 tracked boxes, 4 checked and 24 open (one additional P1.R1 closure). PRE-BENCHMARK AUDIT IN PROGRESS; PERFORMANCE CERTIFICATION PAUSED; RELEASE BLOCKED; PR draft, unmerged, untagged.


### P1 archive evidence disposition — calibrated evidence review, without new measurements (2026-11-22)

**P1.3 COMPLETE as an evidence-review activity, P1.4 COMPLETE as a reproducibility-decision activity, P1 acceptance still OPEN/RED.** No benchmarks were started for this batch. The evidence is the two already-finished 2026-11-22 source-identical runs on `e503067` and `635ff48`, plus their 6.0.4→6.1 A/B cohorts; the current review compares artifact metrics at the specific source SHA and does not claim a new result.

| Comparison | Aggregate artifact | Source and identity | Matrix and gate | Median cell throughput difference | Median cell p99 difference | Max per-cell sample RPS CV | Maximum RSS increase |
| --- | --- | --- | --- | --- | --- | --- | --- |
| A/A SAPI isolated | [14758044381](https://github.com/infocyph/Webrick/actions/runs/45029933689/artifacts/14758044381) | `635ff48` both; PHP 8.5.13, nginx 1.24, PHP-FPM 8.5.13 | 512/512 rows; **0/64 budget violations** | +0.02% baseline-relative regression | +0.26% | 3.46% | 0.477 MiB |
| A/A Runwire isolated | [14758028018](https://github.com/infocyph/Webrick/actions/runs/45029933689/artifacts/14758028018) | `635ff48` both; PHP 8.5.13, Runwire 3.1, InterMix 11.2.1 | 512/512 rows; **9/64 breaches** | +0.04% regression | −0.14% | 7.25% | 0.068 MiB |
| A/B SAPI isolated | [14758861427](https://github.com/infocyph/Webrick/actions/runs/45029933720/artifacts/14758861427) | `main` `981d095`+tested cancellation backport versus `635ff48`, PHP 8.5.13 | 512/512; **3/64 breaches** | +0.13% regression | +0.06% | 4.41% | 0.768 MiB |
| A/B Runwire isolated | [14758468145](https://github.com/infocyph/Webrick/actions/runs/45029933720/artifacts/14758468145) | same A/B SHAs; Runwire 3.1, InterMix 11.2.1 | 512/512; **18 threshold violations in 64 cells** | −0.12% regression | −2.89% | 6.48% | 0.535 MiB |

**All four exact-source aggregates retain baseline/candidate per-trial raw RPS, p99, RSS and CV in their `load-results.jsonl` / `comparison.json`, rather than only summary medians.** The selected detailed same-source Runwire c5 shard [14758521657](https://github.com/infocyph/Webrick/actions/runs/45029933689/artifacts/14758521657) additionally contains `pair-order.txt`, `affinity.txt`, warm-up/load stderr, worker-exit events and response-validation settling data. The four crossed trial orders (recorded, not randomized) are baseline→candidate, candidate→baseline, candidate→baseline, baseline→candidate. Ports rotate 18082/18083 across pairs. Environment reports PHP 8.5.13, Runwire 3.1, InterMix 11.2.1 and nginx/PHP-FPM versions for SAPI; PHPForge job metadata and prepared-PHP extension provisioning remain attached to the relevant run. Affinity recorded CPUs **0,1** for servers and **2,3** for `wrk`, on a 4-logical-CPU Xeon 6975P runner advertising **2 SMT threads/core**. Disjoint logical IDs do not demonstrate disjoint physical cores.

**Concrete per-trial examples establishing the inconclusive calibration:** A/A Runwire `file/c5` yielded paired baseline-relative RPS differences **+12.08%, +10.65%, +10.24%, −10.30%** across trials 1–4, with independently computed group medians producing a false **10.42% regression** despite identical sources. A/A `upload/c5` paired RPS differences were **+10.40%, +8.95%, +6.30%, −8.63%**; `json/c5` **+6.48%, +8.38%, +7.44%, −7.45%**. This reversal under the crossed order/port design indicates systematic trial/role/host effects; it does **not** prove which process caused them. On A/B, Runwire `file/c20` has consistent per-trial deficits **+9.37%, +10.31%, +7.60%, +9.76%** and `range/c20` **+8.43%, +10.38%, +7.04%, +9.44%**. Those may represent real source changes or correlated environmental effects, and still require corrected controls. A/A SAPI passed this isolated run but failed at `e503067`, so even SAPI repeatability is not fully established.

**P1.4 explicit verdict: NOT REPRODUCIBLE ENOUGH for 2% per-cell certification.** Repeated source-identical A/A controls failed, despite valid complete 512/512 matrices and CPU pinning. The benchmark starts both baseline/candidate worker groups for one trial; the idle group remains runnable while the other is measured. The 10-second duration and four short paired trials cannot establish a suitably narrow uncertainty bound around a 2% tolerance when several CVs exceed that tolerance. CPU isolation used logical IDs without proving physical-core separation. The analyzer's **ratio of independently aggregated baseline/candidate medians** is not equivalent to inference on matched per-trial ratios; small p99 denominators make relative 15% tails unstable. Those are confirmed harness *design limitations*, but no single one is proven the entire cause. This activity is closed with a **failing** calibration verdict; P1 acceptance/R2/R3/R4 and performance A/B acceptance remain OPEN. Do not change limits, claim source improvements, or start another benchmark before pre-benchmark tasks are reviewed.

**Checklist after this evidence-only batch:** 28 total; **6 checked, 22 open**. Tasks that require a final passing benchmark remain dependent on the (currently paused) measurement process. External alerts/branch protection are independently unresolved and must not be inferred from QA success.


### Pre-benchmark ownership/security reconciliation — P4.4 classification (2026-11-22)

**P4.4 COMPLETE as the required ownership/blocker classification; release readiness remains BLOCKED.** No new benchmarks were run. The status below distinguishes accepted host responsibilities from unresolved release evidence rather than silently granting approvals.

| Audit qualification | Ownership and verified evidence | Disposition |
| --- | --- | --- |
| Slow body/timeouts/abort/cancellation | Webrick owns **bounded early form promotion** and fail-closed 413, replay and cancellation cleanup; see `docs/middleware/request-limits.rst` lines 1–15, 90–115, `docs/deployments/runwire.rst` request-body/response backpressure sections, and `tests/Integration/RunwireResponsePressureCancellationTest.php`. Transport-facing idle-read, overall deadlines, connection drain/close and head/body admission are host/Runwire/NGINX/Apache responsibilities; no imaginary `maxUploadSeconds` API is advertised. | **Documented and deployment-owned**; real host-specific deadlines require the consuming deployment's operational QA, not a Webrick library release bypass |
| Executable route and InterMix release artifacts | `docs/deployments/route-cache-warmup.rst` lines 125–156 and `docs/guides/upgrading-6.0-to-6.1.rst` require immutable host-owned release directories and an atomic whole-generation switch. Coordinated `ReleaseCompiler` emits trusted byte SHA-256 identities and `ReleaseArtifactLoader` checks them before PHP artifact evaluation; historical `xxh128` fingerprints/sidecars are explicitly **not** authenticators. Manifest is unsigned; a writable manifest and artifact set remain unsafe. | **Library protections documented/tested; immutable deployment filesystem ownership remains host-owned**, with separate deployment rollout/rollback verification |
| Runwire version and outbound PSR bridge | `composer.json` now requires optional `infocyph/runwire: ^3.1`; this batch corrects stale `docs/deployments/runwire.rst` 3.0 installation guidance. Runwire 3.1 outbound PSR destination policy is enforced for hosts invoking its outbound client; inbound Webrick routing does not call it directly. | **Documentation mismatch corrected**, downstream host policy remains host-owned |
| PHPForge exceptions and third-party source scanning | `composer.json` has **14 default-deny PHPForge policy exceptions**, 5 rooted in `src/` (two bounded taint escapes with SHA-256 sanitizer identity + regression-test references, and three narrowly explained Psalm inference/BC-compat suppressions) and nine for isolated `.github/certification` operator-owned I/O. On [Security & Standards #45029940961](https://github.com/infocyph/Webrick/actions/runs/45029940961), all 12 jobs passed, including PHP 8.4–8.6 QA, Psalm and PHPStan. [PR #59](https://github.com/infocyph/Webrick/pull/59) exposes **469 resolved/0 unresolved inline code-scanning review threads**, but actual GitHub code/secret scanning alert state is NOT available through the connected API; resolved threads are not equivalent to dismissed/closed source alerts. Branch protection query returned 403. | **First-party QA PASS; external scanning-alert disposition and required branch-policy attestation UNVERIFIED/RELEASE BLOCKER**, pending a principal with repository security/branch-settings permission. Do not check P3.3/P3 acceptance/P4 overall acceptance based on this classification. |

**Current tracker count:** 28 original P1–P4 checklist items, **7 checked and 21 open**. Finishing P4.4 means explicit classification of these concerns; it does not claim an externally inaccessible security gate passed. No new benchmark, performance-waiver, main/master change, tag or merge occurred.


### Pre-benchmark harness correction B1 — single active benchmark server (2026-11-22)

**Change without running a benchmark:** In `.github/certification/load-benchmark.sh`, baseline and candidate no longer remain alive simultaneously. For each crossed trial and concurrency, start/warm one version, perform the exact same **16 workload measurements** with full response/negative-fixture/worker telemetry assertions, stop and reap its process group, preserve its per-trial/concurrency supervisor events, and only then start the counterpart. The version order and loopback-port crossing remain unchanged. This removes idle Runwire worker-group / PHP-FPM+NGINX contention for the measured server's CPU set. The former code comment 'one load client at a time' was not sufficient for process isolation.

**Evidence/truth rules:** Every process log and Runwire supervisor-event artifact now includes trial and concurrency, so multiple concurrency groups in the same job do not overwrite one another. A fail-closed check rejects an already-active tracked server before every start; process cleanup remains guarded by the existing EXIT trap. The unchanged workload suite continues to emit the same schema and expected complete matrix; no performance threshold, trial count, duration, response validator, production PHP or resource budget was changed. The **time gap between baseline and candidate's 16-workload suites may itself introduce drift**; ABBA crossing helps but a multi-run A/A must establish repeatability, so P1.R2/P1 acceptance stay OPEN.

**Pre-benchmark verification:** The existing Security & Standards PR workflow now runs `bash -n` for the benchmark scripts plus the non-load crossed-order and source-identity tests. This is syntax/control-plan checking only; it is explicitly NOT evidence that benchmark noise is fixed. Both measurement workflows remain manual-only and **are not triggered by this source/QA commit**. P1.R3 statistical CI and physical-core topology follow-up remain OPEN. The 2%/15%/32 MiB acceptance thresholds are untouched. Plan checklist count remains **7 completed/21 open** until real independent calibration evidence exists.


### Pre-benchmark B2 — paired trial uncertainty and classification diagnostics (2026-11-22)

Added an **exploratory** Student-t log ratio 95% confidence interval for matched baseline/candidate trial RPS and per-trial p99, with correct t critical values for the existing 4/8/12-trial crossing. Every comparison includes explicit `non_inferior`, `regression_supported`, `inconclusive`, or error-status diagnostics and scenario labels (`steady_state`, `file_or_stream_io`, `high_concurrency_stress`, `security_or_provider_contention`). Do not describe the per-trial p99 intervals as a pooled response-latency histogram; independent, stable trial-pair assumptions must still be verified. The original **hard median-based 2% throughput, 15% p99 and 32-MiB RSS** acceptance, complete/validated responses, socket checks and worker invariants are unchanged.

The new offline synthetic [test-analyze-load-uncertainty.sh](https://github.com/infocyph/Webrick/blob/feature/risk-admission/.github/certification/test-analyze-load-uncertainty.sh) generates 512 fixture rows and asserts that a 10% synthetic slowdown **still fails** release acceptance, a balanced noisy pair is classified inconclusive, exact equal samples are non-inferior, and stress categories are differentiated. Security & Standards runs this test without starting any real benchmark, PHP-FPM, wrk or Runwire load.

**P1.R3 and P1.R4 remain unchecked** until physical-core topology, hosted-runner repeatability, a calibrated confidence/absolute p99 decision and actual workload saturation are validated. The new diagnostics MUST NOT be interpreted as a waiver or as proof of source regression. This is a staged methodology correction, not a verified release threshold change. Checklist remains **7 completed / 21 open**; performance workflows remain manual-only.


### Pre-benchmark B2 policy identity follow-up (2026-11-22)

The new statistical *diagnostic-only* fields in `.github/certification/analyze-load.php` update its source bytes. Four PHPForge `extra.phpforge.skipper_policy` rules for this trusted CLI-only, local path I/O boundary carry a **whole-file SHA-256 identity**. Leaving their hash at the old source value would intentionally fail the default-deny integrity gate. Recalculated SHA-256 of the exact updated source using independent SHA-256 implementation, first cross-checked against the previous pinned hash: old `7b435b8b476df6ac0719991fa368f60a3641fc9f4b23b378bd784bcdb364ecf4` matches previous file bytes, new `2024f4f8ec86534da72e1dc459f07c58ea6c405c89062adccb89d231beb9fab2` matches the updated analyzer. Rebound **only** the four corresponding scoped policy references; did not introduce exceptions, change default-deny, declassify new data or alter test assertions. `tests/Unit/CertificationPathBoundaryTest.php` remains the policy's contract evidence. The existing gate is retained; the latest commit must pass Security & Standards again before verification.


### Pre-benchmark B2 Psalm strict-type correction (2026-11-22)

At SHA `d2f5678`, PHPForge PHPStan and the offline synthetic benchmark-analyzer fixture passed but Psalm (PHP 8.4, 8.5, 8.6) reported **two InvalidOperand findings** in `.github/certification/analyze-load.php`: mixed inferred integer/float operands in a log-ratio division and in the sample-variance denominator. Explicitly cast only those numeric operands to floats; no change to interval formula, hard acceptance or other behavior. Recomputed the exact analyzer whole-file SHA-256, verified the previous source matches its pinned hash, then updated the **four existing** scoped PHPForge certification-script exception fingerprints to the new exact SHA. No extra skip/suppression directives. Exact new SHA and CI verdict pending; do not mark the calibration or release ready on this change.


### Pre-benchmark B3 — enforce physical-core CPU isolation (2026-11-22)

Previously, the CPU-pinned A/A still failed because the harness isolated **logical** CPU IDs only; 4 vCPUs on a 2-threads/core host do not prove that 0,1 and 2,3 are independent cores. This batch replaces the YAML inline affinity heuristic with `.github/certification/benchmark-cpu-topology.py`: read the actually permitted CPU IDs via `os.sched_getaffinity(0)`, read Linux socket/core topology for each, group SMT sibling threads together, give the entire last physical core to the wrk client and every other physical core to the server. Fail closed for fewer than four permitted logical CPUs, fewer than two distinct physical cores, unreadable topology or overlapping sets. Record the complete socket/core mapping in `affinity.txt`, verify both sets with `taskset`, and keep all measurements/provenance unchanged. The single-file `--self-test` verifies grouped SMT siblings, multi-socket core IDs and a single-core rejection in Security & Standards **without executing a benchmark**.

**Calibration still OPEN:** A two-physical-core hosted runner can leave only one physical core for the server and may still be too small for c50 and a 2% non-inferiority target. The gate makes core isolation truthful; it does **not** certify that the selected runner has sufficient capacity or that paired-trial assumptions hold. P1.R2/P1.R3/P1 acceptance and P2/P4 remain unchecked pending independent live A/A controls. All performance workflows stay manual-only, original limits remain unchanged.

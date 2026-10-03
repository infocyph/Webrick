# Webrick 6.0 — Consolidated improvement and release plan

Status: **plan synchronized; Batches 0–2 complete; Batch 3 in progress; implementation and release gates remain open**.

Audit date: 2026-10-03 (Asia/Dhaka). Baseline: Webrick **5.4**, commit
`0f01cb4c303f94e683de8a3567682b70908122af`.

This is the single implementation and release plan for Webrick 6.0. It combines
security/correctness remediation, InterMix 11 / CacheLayer 4 / Runwire 2.1
integration, the existing runtime ownership contracts and real-HTTP performance
acceptance. The former Webrick 5 runtime design, progress tracker and performance
recovery plan are retired; their applicable requirements are consolidated here.

Evidence, reproduction details and verification limits are included in the
[audit evidence appendix](#audit-evidence--2026-10-03) below. This document is the
single source for both the implementation plan and its supporting audit evidence.

## Release decision

Proceed directly from **Webrick 5.4 to Webrick 6.0**. All required fixes and
dependency migrations ship together in this major release; there is no separate
5.x patch or minor-release track.

InterMix types and invocation contracts occur in public Webrick constructors
and build APIs. Migrate them deliberately and document the breaking changes,
including unsafe-input rejection, legacy-cookie retirement, cache namespace
changes and artifact rebuilding. Preserve established HTTP semantics and avoid
unrelated API changes; a major version is not a reason for a broad rewrite.

The release is not ready to tag. Passing baseline CI does not cover the newly
reproduced defects or the new dependency combination. Execute phases A–G below
as one release backlog, with optional improvements considered only after the
required work and its acceptance criteria are satisfied.

Proposed 6.0 dependency policy:

| Location | Dependency | Constraint / policy |
| --- | --- | --- |
| require | PHP | Keep `^8.4`; test 8.4 and 8.5 |
| require | infocyph/intermix | `^11.0` |
| require-dev | infocyph/cachelayer | `^4.0` |
| require-dev | infocyph/runwire | `^2.1` |
| suggest | CacheLayer / Runwire | Describe optional 4.x / 2.1+ integration |
| require | PSR packages | Retain only interfaces actually consumed |

Runwire's 64-bit/platform requirements must not become requirements of ordinary
Webrick installations. Do not raise the PHP floor solely for a convenient URL
parser; the redirect fix must also work on PHP 8.4.

## Batch implementation tracker

Execute Webrick 6.0 sequentially by batch. Do not advance until the current batch's
implementation, focused regressions and QA exit gate are complete. Update this table
and the mapped phase checkboxes after every implementation and QA stage.

| Batch | Scope | Phase mapping | Status |
| --- | --- | --- | --- |
| B0 | Baseline review and plan synchronization | Audit + planning | **Complete** |
| B1 | Response cache, redirects and encrypted cookies | A: W01–W04 | **Complete** |
| B2 | Request limits, HTTP deflate and upload integrity | A: W05–W07 | **Complete** |
| B3 | InterMix 11 core runtime migration | B: runtime API | **Complete** |
| B4 | InterMix kernels, dispatcher and artifacts | B: dispatch/build | **Complete** |
| B5 | Runwire 2.1 contracts and lifecycle | C: lifecycle | **Complete** |
| B6 | Borrowed runtime/request/scope integration | C: ownership/context | **Complete** |
| B7 | CacheLayer 4 compatibility and deployment | D | **Complete** |
| B8 | Static quality, duplication and migration docs | E | **Complete** |
| B9 | Production request-path performance | F | **Complete** |
| B10 | Final release evidence and acceptance | G | **In progress** |

### Batch 0 — synchronized baseline

- [x] Confirm `feature/runwire` is based on Webrick 5.4 (`0f01cb4c303f94e683de8a3567682b70908122af`).
- [x] Reconfirm W01–W07 against their current source owners.
- [x] Validate target releases InterMix 11.0, Runwire 2.1 and CacheLayer 4.0.
- [x] Confirm InterMix 11 provides `RuntimeContainerInterface` and its Runwire integration.
- [x] Confirm Runwire 2.1 terminal and application-health contract changes.
- [x] Establish B1–B10 sequencing and per-batch QA/tracker rules.

### Batch 1 — W01–W04

- [x] Add failing adversarial regressions for W01–W04 before production fixes.
- [x] W01: preserve exact raw query semantics in response-cache keys and bump the namespace.
- [x] W02: reject ambiguous redirect backslashes, malformed authorities, userinfo and invalid ports.
- [x] W03: explicitly reject unsupported dotted protected-cookie names on write and never pass them through plaintext on read.
- [x] W04: accept only shaped store references containing current authenticated `C1:` ciphertext; remove legacy plaintext acceptance.
- [x] Run focused B1 tests, affected PHPForge checks and the full suite; record evidence before B2.

Batch 1 evidence:

- Regression checkpoint: `1a33f9387fcef0bad60e92546264732d8b6ee60e`; PHPForge/Pest reproduced W01–W04 with **16 failed / 499 passed** before fixes.
- Production fixes: `196b84908ff4614608977bcf66ce6754c4428775`.
- Upgrade notes: `ccbbbd21454021bb528379fa81bc1b538841c41f`.
- Static-analysis cleanup: `dbd535265ded88d4746eafbef50253a3159019b3`.
- Exact-head Security & Standards run `37120816851`: PHPForge detail diagnostics, PHP 8.4/8.5 stable and lowest QA, PHP 8.4/8.5 analysis, clean install and both benchmark jobs all passed.


### Batch 2 — W05–W07

- [x] Add failing regressions for stricter Webrick request limits under Runwire capabilities, zlib-wrapped HTTP deflate and no-progress upload reads.
- [x] W05: keep configured application body/header limits enforceable on every adapter unless the transport proves equivalent or stricter bounds.
- [x] W06: emit zlib-wrapped bytes for HTTP `Content-Encoding: deflate` without changing internal cookie compression.
- [x] W07: never treat a temporary empty read as successful EOF, never mark a partial upload moved, and never delete unrelated pre-existing destination data on failed copy.
- [x] Run focused B2 tests, affected PHPForge checks and the full suite; record evidence before B3.

Batch 2 evidence:

- Final implementation/quality head: `dd017e248151358fb1248a83fc42b2a2ed4b430c`.
- The full PHPForge/Pest suite passes with **520 tests / 1,790 assertions**.
- PHPStan, Psalm, PHPCS, duplicate-code, comment-policy, clean-install and benchmark gates pass.
- Exact-head Security & Standards run `37124045404` passed on PHP 8.4/8.5 stable/lowest combinations.
- W05 regressions prove Webrick application limits remain active despite transport capability metadata.
- W06 is independently decoded with `gzuncompress()`, proving HTTP deflate uses the zlib wrapper.
- W07 uses target-directory temporary publication, rejects non-EOF zero progress, preserves retry state and leaves pre-existing destination data untouched on failed copy.

### Batch 3 — InterMix 11 core runtime migration

- [x] Update Composer to InterMix `^11.0` without changing the optional Runwire/CacheLayer ranges yet.
- [x] Replace the Webrick runtime wrapper's legacy `Container|ProductionContainer` API with `RuntimeContainerInterface`.
- [x] Replace `resolveNow()` / `findByTag()` consumption with explicit `make()`, `invoke()` and `tagged()` runtime contracts.
- [x] Add/adjust focused runtime tests and keep the requestless/scopeless compiled fast path unchanged.
- [x] Run focused B3 tests and PHPForge gates before B4.

Batch 3 evidence:

- Final B3 head: `223c05175e50beb5de4987f1d77c4b1e9a353d4d`.
- Exact-head Security & Standards run `37126967811` passed PHPForge detail diagnostics, PHP 8.4/8.5 stable and lowest QA, PHP 8.4/8.5 analysis, clean install and both benchmark jobs.
- The InterMix 11 migration uses `RuntimeContainerInterface`, `make()`, `invoke()` and `tagged()`; no legacy `resolveNow()`, `findByTag()`, `Invoker` or mutable repository access remains in Webrick runtime owners.
- Parameterized runtime descriptors preserve constructor arguments under InterMix 11, singleton tagged factories avoid captive request scope, and benchmark fixtures declare the request input before scope seeding.

### Batch 4 — InterMix kernels, dispatcher and artifacts

- [x] Cross-check both kernels, dispatcher/pipelines, alias invocation, handler compiler, CLI/examples and release compiler for finalized InterMix 11 contracts.
- [x] Declare every Webrick-owned scope input before graph finalization and reject undeclared/conflicting seeds through InterMix rather than bypassing validation.
- [x] Revalidate coordinated release metadata against InterMix 11 ABI/build/graph identity and reject stale or mixed release artifacts.
- [x] Preserve direct zero-argument/route-argument compiled routes on the requestless/scopeless fast path.
- [x] Cover dynamic/production parity, captured-scope attachment, independent Fiber isolation and failed cleanup at Webrick integration boundaries.
- [x] Run focused B4 tests and full PHPForge gates before B5.

Batch 4 evidence:

- Final B4 head: `7d87b02f5a7ffd45096aaa889980dcd916f6b752`.
- Exact-head Security & Standards run `37128872244` passed PHPForge detail diagnostics, PHP 8.4/8.5 stable and lowest QA, PHP 8.4/8.5 analysis, clean install and both benchmark jobs.
- Coordinated release manifests now bind Webrick router metadata to InterMix 11 digest/graph/build/artifact identity through a canonical release fingerprint; mixed, stale and obsolete release metadata is rejected before kernel boot.
- `CompiledRouterKernel::fromReleaseManifest()` consumes the coordinated release while InterMix remains the owner of its ABI/graph/fallback validation.
- Dynamic and production InterMix dispatch parity is covered, and direct zero-argument/route-argument compiled handlers remain requestless/scopeless while DI-backed handlers enter the request scope.

### Batch 5 — Runwire 2.1 contracts and lifecycle

- [x] Update the optional development Runwire range and suggestion to released Runwire `^2.1`.
- [x] Implement the full Runwire 2.1 application contract, including `healthy()` and `healthFailure()`, and preserve exactly-once response completion.
- [x] Migrate all Webrick test/custom writers to `onTerminal()` with exactly-once and late-registration semantics.
- [x] Audit the Webrick continuation bridge so it never resumes a scheduler-owned Fiber behind its owner.
- [x] Prove terminal state, handler completion, owned work, cleanup/reset, admission release, drain and health transitions in focused lifecycle tests.
- [x] Run focused B5 tests and full PHPForge gates before B6.

Batch 5 evidence:

- Final B5 head: `1dcfe03bf191795d1704b417df21bcfacc5f105d`.
- Exact-head Security & Standards run `37131083421` passed PHPForge detail diagnostics, PHP 8.4/8.5 stable and lowest QA, PHP 8.4/8.5 analysis, clean install and both benchmark jobs.
- The full suite passes with **527 tests / 1,824 assertions** after the Runwire 2.1 migration.
- `RunwireRuntimeApplication` forwards health state and requested response completion to Runwire 2.1; terminal observers support exactly-once and late registration semantics.
- The continuation bridge owns only its inner continuation Fiber: I/O readiness resumes that inner Fiber, while ordinary handler suspension is returned to the caller-owned Fiber and is never resumed behind its owner.
- Runwire 2.1 writer compatibility is covered in unit/integration fixtures and the Runwire runtime benchmark.

### Batch 6 — borrowed Runwire / InterMix scope integration

- [x] Register Runwire runtime/request/coroutine inputs through InterMix 11 before graph finalization when this optional integration is enabled.
- [x] Derive stable Webrick runtime capabilities from the supplied/bound Runwire `RuntimeContext`; keep unbound fallback conservative.
- [x] Reuse the host-supplied InterMix `RunwireIntegration` for one request scope or an exact borrowed `ScopeContext`, without adding a second DI scope.
- [x] Preserve exact Runwire `RuntimeContext`, `RequestContext` and optional `CoroutineScope` identities for injected consumers; reject conflicting/completed contexts.
- [x] Preserve zero-scope compiled routes and ordinary SAPI/FPM behavior when the optional bridge is absent.
- [x] Cover direct host, intermediary/borrowed scope, interleaved requests, nested restoration and runtime/generation conflicts.
- [x] Run focused B6 tests and full PHPForge gates before B7.

Batch 6 evidence:

- Final B6 head: `dff28a5651c51727666f69839a5751aae465b31c`.
- Exact-head Security & Standards run `37131976307` passed PHPForge detail diagnostics, PHP 8.4/8.5 stable and lowest QA, PHP 8.4/8.5 analysis, clean install and both benchmark jobs.
- `RuntimeRequestContext` carries an optional runtime-scope bridge while the compiled kernel invokes it only for scope-required dispatch; direct zero-scope routes retain the existing fast path.
- `RunwireRuntimeBinding` derives stable capabilities from the concrete runtime, validates request/runtime identity and creates the optional InterMix scope bridge only when the host supplies a bound integration.
- `RunwireInterMixScopeBridge` delegates fresh and borrowed-scope ownership to InterMix 11's native `RunwireIntegration`; tests cover exact runtime/request/coroutine identity, borrowed-scope reuse, interleaved isolation, completed-request rejection and conflicting container/runtime rejection.

### Batch 7 — CacheLayer 4 compatibility and deployment

- [x] Update the optional CacheLayer development range to released `^4.0`.
- [x] Preserve PSR-6 as the response-cache/cookie-store consuming contract and Webrick's own atomic-counter contract.
- [x] Verify the CacheLayer 4 atomic counter adapter, response cache, encrypted-cookie backing store and fail-open cache behavior.
- [x] Preserve optional-loading behavior: core routing must not autoload CacheLayer unless the relevant feature is selected.
- [x] Re-run persistent-worker/cache-boundary and concurrent throttle regressions under CacheLayer 4.
- [x] Run full PHPForge stable/lowest PHP 8.4/8.5 gates before B8.

Batch 7 evidence:

- Final B7 head: `afb2ee09526956ed317f485700b91d245dfc5822`.
- Exact-head Security & Standards run `37132796469` passed PHPForge detail diagnostics, clean install, PHP 8.4/8.5 stable and lowest QA, both analyzers and both benchmark jobs with `infocyph/cachelayer 4.0` resolved.
- Full PHPForge detail suite passes with **534 tests / 1,850 assertions**; duplicate detector remains passing at **43 clone groups / 1,892 duplicated lines / 4.95%**.
- Response-cache entries remain on `webrick.hr.v4.`; throttle counters rotate to `webrick.th.v3.` and encrypted-cookie backing entries rotate to `enc_cookie.v2.` so security-relevant state is not shared across CacheLayer major-version writers.
- Core routing keeps CacheLayer optional; the consuming contracts remain PSR-6 for cache stores and Webrick's atomic-counter interface for throttling.

### Batch 8 — static quality, duplication and migration docs

- [x] Resolve W08 without making `ext-intl` a runtime requirement or suppressing analysis.
- [x] Re-run Unicode-normalization behavior with and without the optional intl surface.
- [x] Triage the current PHPForge duplicate report by semantic ownership and only extract genuinely shared behavior.
- [x] Update README and deployment/middleware docs for Webrick 6, InterMix 11, Runwire 2.1, CacheLayer 4 and PSR-6 cache contracts.
- [x] Record the shared-tooling `doctrine/annotations` warning accurately without treating it as a Webrick production advisory.
- [x] Run full PHPForge gates before B9.

Batch 8 evidence:

- W08 no longer references the optional `Normalizer` class symbol directly. Runtime normalization is guarded by `function_exists()`, `defined()` and an integer type check; no suppression or mandatory `ext-intl` dependency was added.
- `InputSanitizerTest` covers the intl-present behavior while the detail workflow runs an explicit `php -n` no-intl smoke check.
- The current duplicate report passes at **43 clone groups / 1,892 duplicated lines / 4.95%**. Triage groups intentional adapter/matcher/benchmark parallelism separately from same-owner parser/validator repetition; no release-blocking semantic duplicate justified a cross-owner abstraction.
- README, Runwire deployment/performance guidance and response-cache documentation now describe Webrick 6, InterMix 11, Runwire 2.1, CacheLayer 4, PSR-6 cache ownership and the CacheLayer major-version namespace rotation.
- PHPForge currently owns `phpbench/phpbench ^1.7`, which installs abandoned `doctrine/annotations`. Current Webrick Composer audit passes and the no-dev clean install excludes that toolchain; removing the warning belongs to the shared PHPForge/PHPBench tooling owner and is not being masked by a Webrick dependency change.

- Final B8 head: `181aa0c5a7880d2dd01d4fd5da403ab833deb882`; exact-head Security & Standards run `37134054350` passed detail diagnostics, clean install, PHP 8.4/8.5 stable and lowest QA, both analyzers and both benchmark jobs.
- Final detail suite: **535 tests / 1,851 assertions**. The explicit `php -n` no-intl smoke passed; PHPStan, Psalm and Rector are green.

### Batch 9 — production request-path performance

- [x] Split coordinated release profiling into manifest, InterMix runtime and router-artifact stages while keeping profiling opt-in.
- [x] Reconfirm trusted-prevalidated artifact loading, cache-boot matchers, explicit 404/405 outcomes, lazy Request promotion and zero-scope dispatch without rebuilding completed 5.x work.
- [x] Keep runtime response-write measurement separate from kernel dispatch and response-ready stages; add no benchmark-only production path.
- [x] Run semantic/parity tests plus PHPBench after any instrumentation change and compare against the preceding B8 head.
- [x] Run full PHPForge gates before B10.

Batch 9 evidence:

- Final B9 head: `2d7798c66b9d3367ae9fc02634c76784169e0b07`.
- Exact-head Security & Standards run `37134396611` passed detail diagnostics, clean install, PHP 8.4/8.5 stable and lowest QA, both analyzers and both benchmark jobs.
- Coordinated release profiling now separates `release_manifest_load`, `intermix_runtime_load` and `router_artifact_load`; the profiler remains host-supplied/opt-in.
- Existing request-path stages remain separate: routing input, matcher result, dispatch, response-ready and runtime response-write. No benchmark-only production branch was introduced.
- The B8→B9 PhpBench artifact comparison matched 177 PHP 8.4 and 180 PHP 8.5 subjects. Median B9/B8 mode ratio was **1.00** on PHP 8.4 and **1.01** on PHP 8.5; geometric-mean ratios were **1.007** and **1.023** respectively. Median, minimum and maximum memory deltas were **0 bytes** across matched subjects. Individual single-run outliers were treated as noise rather than production-tuning evidence.
- Full parity/quality coverage remained green, including cache-boot matcher behavior, explicit 404/405 outcomes, lazy request promotion and zero-scope compiled dispatch.

### Batch 10 — final release evidence and acceptance

- [ ] Re-run PHPForge release/quality gates on the final candidate SHA and record exact run/versions.
- [ ] Reconfirm stable and lowest dependency matrices on PHP 8.4/8.5 plus clean no-dev production install.
- [ ] Reconfirm W01–W07 adversarial regressions and supported routing/middleware/URL/cache/HEAD/range/conditional/upload/error parity.
- [ ] Record live Runwire H1/H2 and prepared-host H3 evidence separately from fixture/composition tests.
- [ ] Record actual Foundation and Infbyte consumer results at exact SHAs.
- [ ] Record controlled 5.4 vs 6.0 unbound/bound end-to-end throughput, latency, memory, queue/utilization and concurrency evidence.
- [ ] Record persistent-worker soak evidence for cleanup, cancellation, drain and replacement.
- [ ] Require all applicable CI checks on the exact final candidate SHA before tagging; do not merge/tag as part of this plan.

#### Runtime release certification workflow

`.github/workflows/runtime-release-certification.yml` owns the external runtime
evidence that ordinary PHPForge fixture CI cannot certify. It deliberately excludes
Foundation and Infbyte consumer migration/results.

Manual release certification uses `workflow_dispatch` with:

- baseline ref `5.4` and the selected candidate ref/SHA;
- 10-second steady-state trials, 3 trials per workload/concurrency by default;
- concurrency 1/5/20/50 for static, dynamic, JSON, streaming and upload workloads;
- SAPI/unbound and native Runwire/bound execution on the same runner;
- an initial 2% median-RPM regression budget, 15% p99 regression budget and
  32 MiB RSS regression ceiling;
- a 30-minute persistent-worker soak covering request-count recycling, paced
  client cancellation, streaming/upload traffic, queue/rejection state, memory/RSS
  growth, worker replacement and graceful drain;
- real TLS HTTP/1.1 plus multiplexed HTTP/2 certification on GitHub-hosted Linux;
- optional HTTP/3 only on a prepared self-hosted runner labelled
  `runwire-quic`, with ext-quic and an HTTP/3-capable curl.

Pushes that change only the certification workflow/harness on `feature/runwire`
run a short smoke variant (2-second/1-trial load runs, 2-minute soak and relaxed
performance budgets). Smoke validates the harness itself; it does **not** replace
the full-duration manual release evidence.

Certification support lives under `.github/certification/`:

- `cert-server.php` — shared 5.4/6.0 SAPI and native-Runwire HTTP fixture;
- `load-benchmark.sh` — correctness-first same-run HTTP load matrix;
- `analyze-load.php` — throughput/p99/RSS/queue acceptance budgets;
- `run-soak.sh` — persistent-worker cleanup/cancellation/replacement/drain soak.

## Engineering constraints

Follow `vendor/infocyph/phpforge/resources/engineering-principles.md` and its
referenced agent workflow. Correctness, security, data integrity and public
contracts precede throughput. Among valid candidates, measure sustained
successful RPM; when equivalent, retain the simpler implementation.

Modify the existing owner first. No new scheduler, event loop, worker manager,
process-global current-request registry, generic capability framework, parallel
DI scope store, or automatic environment discovery. Keep direct SAPI, Workerman,
Swoole/OpenSwoole and RoadRunner adapters available.

Keep required remediation separate from optional optimization. Do not disable
detectors, introduce inline suppressions, or widen types just to pass analysis.
Review formatter/refactor output and retain only changes within the phase.

## Runtime and HTTP contracts to preserve

| Owner | Responsibility |
| --- | --- |
| Runwire / selected host | Listeners, wire protocols, loops, workers, scheduling, transport hard bounds, admission, authoritative cancellation/deadlines and application lifecycle |
| Webrick | Routing, middleware, application HTTP policy, request/response values, application input limits, cookies, HEAD/ranges/conditional responses and body production |
| InterMix | Container execution, logical scope identity, scoped instances, scope capture/attachment and cleanup |
| Framework/application | Composition, runtime selection, authentication/session/transaction policy, deployment defaults and filesystem authorization |

Ordinary Apache/LiteSpeed/CGI/FastCGI/PHP-FPM/shared-hosting and CLI use must
remain possible without Runwire, a persistent worker, PCNTL/POSIX, Fibers or QUIC.
Keep direct adapters independent; Foundation and Infbyte remain external
consumers. Do not add a Pathwise dependency or filesystem trust subsystem.

Preserve the distinct context roles: Runwire's context owns execution lifecycle;
Webrick's `RuntimeRequestContext` holds routing/lazy request/transport state;
Webrick's `Support\RequestContext` holds application correlation/telemetry.
Reuse runtime request IDs where the host's correlation policy permits, without
treating them as authentication or DI scope identities.

- [x] Preserve method, target/path/query, authority, duplicate headers/cookies,
  TLS/protocol and peer/local metadata across normalized H1/H2/H3 requests.
  Do not reconstruct native requests through globals or an unnecessary PSR-7 hop.
- [x] Keep incremental request reads and bounded buffering. Preserve lazy
  URL-encoded parsing and `_method` replay so routing does not steal the body.
  Leave unread-body drain/connection reuse and H2/H3 stream reset to Runwire.
- [x] Preserve status, duplicate Set-Cookie fields, HEAD/body-forbidden responses,
  range/conditional semantics and H2/H3 header filtering. Do not materialize
  complete streaming/file output or introduce a second transport queue.
- [x] Keep application scope alive for lazy body production and owned child work;
  release it when that work finishes, without retaining it solely for network
  flush of bytes already accepted by the transport. Never resume producers after
  scope cleanup. Respect Runwire 2.1's separate terminal/request-completion rules.
- [x] Keep malformed framing/protocol errors below routing. Render application
  failures only while the response is writable; partial-output failures must not
  trigger a second response. Cancellation must not affect sibling streams, and
  client disconnect must not imply application transaction rollback.
- [x] Exercise success/error/cancellation/deadline cleanup exactly once. Use
  InterMix's carrier-local defensive reset only at an owned cleanup boundary;
  never reset a borrowed parent scope. Worker recycling must not conceal leaks.
- [x] Accept file transfer targets only after application authorization. Preserve
  file/range/HEAD/cache semantics and reject traversal/private-file/symlink escapes
  in consumer boundary tests. Acceleration must not bypass routes or middleware;
  retain normal streaming when unavailable and add no unmeasured zero-copy API.

## Phase A — Security and data integrity

All items below require failing regressions before fixes, followed by the full
suite. Severity describes the affected configuration, not universal exploitability.

| ID | Priority | Required change | Acceptance |
| --- | --- | --- | --- |
| W01 | High: shared-cache integrity | Change `ResponseCacheMiddleware::makeKey()` to preserve query semantics. Prefer hashing the exact query string over lossy sorting/decoding. Bump the response-cache namespace so old entries cannot collide. Review other `normalizeQueryString()` callers before changing the helper itself. | `+`/`%2B`, semicolon/ampersand, scalar/array overwrite order, duplicate keys, encoded delimiters and query ordering cannot reuse a response for a different application input. Preserve GET/HEAD behavior, Vary, cookies, authorization and fail-open cache errors. |
| W02 | Medium: open redirect | Harden `GatewayHardeningMiddleware::guardRedirects()` against browser/PHP parsing disagreement. Reject ambiguous backslashes and invalid authority syntax before treating a Location as relative; apply the configured host policy to accepted absolute/network-path targets. | `/\\evil.test/path` is rejected; ordinary relative paths, fragments and explicitly allowed hosts still work. Cover slash/backslash mixtures, controls, userinfo and invalid ports. Use a WHATWG parser as an independent test oracle where available. |
| W03 | High when protected dotted cookies are consumed | Make encryption and decryption agree on protected cookie names. `CookieDecryption::partition()` must never pass a prefix-matching name through as plaintext because it fails the segment regex. Either support dotted base names unambiguously or explicitly reject them on both write and read. | An attacker-supplied `enc_session.extra=plaintext` never arrives as authenticated plaintext; round-trip supported names, segment ordering, gaps, duplicates, malformed suffixes and cross-name substitution on hosts that preserve cookie names. |
| W04 | Medium, conditional on legacy cache records | Remove the implicit plaintext path in `CookieEncryptionMiddleware::resolveCipherInput()`. Validate store-reference shape, require authenticated ciphertext and retain cookie-name/key/mode binding. Retire legacy plaintext records through explicit deployment migration. | A legacy `S:<id>` record cannot be accepted without authentication or transplanted from `enc_profile` to `enc_session`. Current encrypted C1 records and key rotation continue to work; malformed references fail safely. |
| W05 | Medium: application limits | Separate transport hard-limit ownership from application limit policy. A generic `transportRequestLimits=true` does not prove the configured Webrick header/body thresholds were enforced. Keep explicit application bounds active; avoid redundant reads only with demonstrably equivalent bounds. | A five-byte body with `maxBodyBytes: 4` fails on every adapter, including default Runwire. Cover stricter app limits, missing length, streaming bodies, header bytes/count, cancellation, form method overrides and bounded pre-dispatch reads. |
| W06 | Medium: HTTP interoperability | Emit zlib-wrapped data for HTTP `Content-Encoding: deflate`, instead of raw `gzdeflate()` output. Keep internal cookie compression independent. | An independent zlib decoder recovers the complete body; gzip/identity, validators, Vary, HEAD, ranges and no-transform behavior remain correct. |
| W07 | Medium: upload data loss | Make `UploadedFile::copyStreamTo()` distinguish temporary/no-progress empty reads from EOF. Throw on unsupported no-progress streams or wait only through an explicitly supported bounded readiness contract. Do not mark a partial copy as moved. | Empty read while `eof() === false` cannot return success. Test partial writes, read/write failure, cleanup, retry state and genuine EOF; do not delete unrelated pre-existing destination data during failure handling. |

Implement these fixes in the 6.0 workstream before accepting performance changes.
Security-invalid input need not retain its former acceptance behavior. Record
cache namespace and legacy-cookie cutover effects in the 5.4 → 6.0 upgrade guide.

## Phase B — InterMix 11 migration

The isolated new-dependency test run already demonstrates that a Composer-only
version bump is insufficient.

- [x] Replace public/internal `Container|ProductionContainer` and `Invoker`
  dependencies where appropriate with InterMix's `RuntimeContainerInterface`.
  Configure graphs with `ContainerBuilder`; consume the finalized runtime.
- [x] Migrate `resolveNow()` to explicit `make()` / `invoke()` and `findByTag()`
  to `tagged()`. Resolve Webrick route/middleware descriptors in their existing
  compiler/dispatcher owners, preserving scoped resolution at invocation time.
- [x] Cover `InterMixRuntime`, both kernels, dispatcher and middleware pipelines,
  alias invocation, `HandlerCompiler`, `ReleaseCompiler`, CLI, examples and tests.
  Do not assume updating the thin runtime wrapper completes this migration.
- [x] Declare scope inputs before finalization, including `Request::class` and
  optional Runwire inputs. Reject undeclared or conflicting seeds clearly.
- [x] Revalidate artifact metadata, callable/Closure encoding, ABI identity and
  build/load behavior against released InterMix 11. Rebuild artifacts during
  deployment; reject obsolete artifacts rather than reinterpret them.
- [x] Keep direct zero-argument/route-argument compiled routes requestless and
  scopeless when they need no injected context. Do not resolve a container or
  create a scope simply because Runwire is installed.
- [x] Test dynamic/production parity, lazy aliases, scoped handlers, explicit
  captured-scope attachment, independent Fibers and failed cleanup.

## Phase C — Borrowed Runwire instance and active scope

### Ownership and data flow

```text
Host/framework creates worker runtime and active request/task scope
    ├─ directly supplies them to Webrick
    └─ supplies them through an intermediary library to Webrick
         ↓ same object identities, same active lifetime
    Webrick HTTP dispatch + existing InterMix scope integration
         ↓ eligible, already configured integrations
    CacheLayer / other consuming services
```

Use the concrete released `RuntimeContext`, `RequestContext`, `CoroutineScope`
and InterMix `ScopeContext` where those contracts apply. A runtime context is
metadata/capabilities, not an event-loop handle and not proof of an active task.
Never manufacture request/task contexts from IDs or assume an ambient Fiber is
a Runwire task.

The existing application factory already accepts a `RuntimeContext`, and native
HTTP requests already carry a Runwire request context. Extend this composition;
do not construct another Runtime or standalone CoroutineRuntime in Webrick.

- [x] Reuse InterMix 11 `Integration\Runwire\RunwireIntegration` for scoped inputs,
  borrowed `ScopeContext` attachment, child wrapping and compatible CacheLayer
  forwarding. Allow the host to pass its existing integration instance. Assert
  that it belongs to the same container/runtime before use.
- [x] Add the smallest explicit hooks to the existing runtime/context boundary
  for a passed runtime, active request and optional task/scope handle. Permit
  callers using non-Runwire HTTP adapters to supply the same execution context.
  Finalize exact signatures with the 6.0 API migration; avoid a new hierarchy.
- [x] When the host/intermediary has already opened the logical DI scope, attach
  its captured scope instead of opening a second request scope. When Webrick is
  the application boundary, open one scope through InterMix. Preserve the scope
  until lazy response production and structured child work have finished.
- [x] Use the supplied native request's context as authoritative. Reject a
  separately passed conflicting runtime/request, completed request, stale scope,
  or release/rebind while requests remain active. Restore nested bindings in
  `finally`; exceptions and cancellation must not leave request state behind.
- [x] Expose the exact borrowed context to injected consumers and forwarding
  boundaries. Keep general HTTP request types free of mandatory Runwire loading.
- [x] Create/bind integration state after the worker/generation begins. Release
  only state Webrick actually owns. Borrowed framework contexts, scopes, loops,
  workers and CacheLayer bindings remain under their owner's lifecycle.

### Automatic capabilities and normal fallback

Resolve stable capability facts once at binding/adapter creation. Inspect live
cancellation/deadline state during I/O. Do not inspect installed extensions on
every request or infer capabilities from a driver name.

| Concern | Automatic use when available | Normal path / boundary |
| --- | --- | --- |
| Persistence/concurrency | Derive adapter metadata from the supplied runtime | Ordinary request-owned execution; never advertise concurrency by default for every host |
| Request/task propagation | Share exact live objects through the existing InterMix bridge | Existing explicit Webrick/InterMix scope behavior when integration is absent |
| Cancellation/deadlines | Observe the supplied token and monotonic deadline through dispatch, body reads and response production | Existing synchronous behavior when unbound; a cancelled operation must stop, not retry through fallback |
| Backpressure/read readiness | Use the supplied writer/body contracts and owning host's supported continuation path | Synchronous bounded I/O when immediately available; fail explicitly if required readiness cannot be provided |
| Compression/file transfer | Use only the selected transport's explicitly proven support, preserving HTTP semantics | Existing compression and chunked file response path |
| Cache integration | Forward through the already bound compatible integration | Caller-provided PSR-6 pool and existing atomic counter contract |
| H2/H3 | Consume normalized HTTP messages when the host supports the protocol | Existing H1/SAPI paths; absence of QUIC does not break startup |

No Runwire capability automatically makes an arbitrary Redis/PDO/file call
asynchronous. No background maintenance task or worker is started merely because
CacheLayer is installed. Keep production throttling fail-closed: missing shared
atomic capability must never silently select a process-local approximate pool.

### Runwire 2.1 lifecycle acceptance

- [x] Migrate `RunwireRuntimeApplication` to the complete Runwire 2.1
  `RuntimeApplicationInterface`, including `healthy()` and `healthFailure()`,
  forwarding those semantics to the owned Runwire application.
- [x] Update test/custom writers for exactly-once `onTerminal()` notification,
  including late registration, rejection and cancellation.
- [x] Audit `RunwireResponseContinuation` with Runwire 2.1 structured scheduling.
  Do not resume a scheduler-owned Fiber behind its owner's back. Reuse released
  continuation contracts where suitable; retain any bridge only with proven
  ownership and cancellation behavior. This is a migration investigation, not
  a proven baseline scheduler defect.
- [x] Prove handler return, response terminal state, child completion, scope
  detach, request reset, metrics and admission release occur in the right order.
  Keep `end()` exactly once and never complete a borrowed request early.
- [x] Exercise pressure, slow reader/writer, cancellation while suspended,
  expired deadline, producer exception, reset failure, drain and worker retirement.
- [x] Test direct host → Webrick, host → intermediary → Webrick and Webrick →
  downstream service flows. Assert object identity, independent interleaved
  requests, nested restoration, separate runtime instances and generation changes.

## Phase D — CacheLayer 4 compatibility and deployment

- [x] Keep PSR-6 pools and Webrick's atomic-counter interface as the consuming
  contracts. Do not require CacheLayer for core HTTP/routing.
- [x] Retest response cache, encrypted-cookie store, default-store construction,
  failure paths and `AtomicCounterAdapter` on CacheLayer 4. The current 23-test
  cache/throttle subset is encouraging, not full certification.
- [x] Use a new namespace for disposable HTTP cache entries. Stop mixed 3.x/4.x
  writers; explicitly migrate or expire security-relevant counters and cookie
  state. Do not enable object/Closure deserialization to preserve old values.
- [x] Document worker-local resource construction after fork, ownership of
  optional forwarding, and storage-compatible rollback. Never silently turn a
  required shared resource into a local-memory fallback.

## Phase E — Quality and documentation

- [x] Resolve W08: local PHPStan's unknown `Normalizer`/argument-type diagnostics
  at `InputSanitizer.php:111`. Test with and without `ext-intl`; provision analysis
  dependencies/stubs as appropriate without making optional Unicode normalization
  a mandatory runtime dependency or suppressing findings.
- [x] Triage the currently reported clone groups by shared semantics. The detector passes
  its threshold, but genuine duplication should be reduced in existing owners;
  do not merge unrelated code just because normalized syntax looks similar.
- [x] Classify the abandoned development dependency (`phpbench` →
  `doctrine/annotations`) as a shared PHPForge/PHPBench tooling-owner follow-up.
  Webrick's Composer audit passes and the no-dev production install excludes that
  toolchain; do not mask it with a Webrick production dependency change.
- [x] Update migration docs, Composer suggestions, examples, CI dependency matrix
  and plan status. Keep the implementation checklist focused on outstanding
  gates; retain dated evidence in this document's audit appendix.
- [x] Preserve the documented native Runwire multipart boundary: raw bounded body
  plus a caller-owned decoder, versus host-parsed uploads on supporting adapters.
  Do not claim complete multipart parity or add a hidden upload subsystem.

Optional improvements, outside security remediation: bounded/stream-backed PSR-7
export instead of whole-body buffering; measured header/routing allocation work;
bounded cookie decompression and input/segment limits; injected clocks where
security expiry/throttle tests need deterministic time. Avoid broad API churn
without a demonstrated benefit. Any newly proven security defect found while
testing these moves into required remediation.

## Phase F — Production request-path performance

Carry the existing performance work forward as regression contracts and
measurement-driven work. Check current implementations before editing; completed
5.x optimizations do not need to be rebuilt. Consume InterMix 11's released
contracts rather than recreating its loader/runtime behavior.

- [x] Capture opt-in stage timing for release loading, InterMix runtime loading,
  router-artifact loading, matcher initialization/matching, dispatch preparation,
  handler execution, response construction and emission. Separate static,
  dynamic, 404 and 405 paths; keep diagnostics disabled by default.
- [x] Preserve trusted-prevalidated artifact loading without full fingerprint or
  route/plan/middleware traversal on each request. Keep full validation in the
  build/deployment path and cheap ABI/environment/release-identity checks at
  runtime; do not weaken artifact integrity or trust assumptions.
- [x] Boot Generated, Fused and Sharded matchers from valid caches without
  re-registering every route. Preserve reverse routing and metadata separately.
  Do not rewrite matcher algorithms unless current profiling identifies a need.
- [x] Keep ordinary NOT_FOUND/METHOD_NOT_ALLOWED outcomes out of exception
  control flow. Preserve custom renderers, Allow, HEAD/OPTIONS, domain routing,
  middleware and genuine exception handling.
- [x] Preserve lazy Request creation and the zero-scope compiled route path
  end-to-end. Profile URL registries, freezes, header/constraint policy, dispatch
  setup and error collaborators before changing eager initialization.
- [x] Measure release-manifest I/O/decoding and retain an OPcache-friendly path
  where justified. Validate InterMix 11 metadata/ABI rather than carrying an
  obsolete version-specific digest compatibility branch forward.
- [x] Measure Response construction, emission and benchmark telemetry separately.
  Keep matcher-only, compiled-kernel and full HTTP results distinct; add no
  benchmark-only production fast path.

After each material hot-path change, run semantic/parity tests, relevant
microbenchmarks and the same representative Apache + OPcache workload. Record
the delta before continuing. Include FPM/Runwire/application workloads in the
release comparison below; a component improvement cannot substitute for an
end-to-end result. Historical component measurements remain documented in
[the Runwire performance guide](../advanced/runwire-runtime-performance.rst).

## Phase G — Release evidence and performance gates

- [ ] Run the PHPForge doctor/config commands, scoped process/fixer flow, detail
  suite and final `composer ic:tests` or `composer ic:release:guard`. Record exact
  commands, versions, exit codes and remaining failures.
- [ ] Run stable and lowest supported dependency combinations on PHP 8.4/8.5.
  Perform clean production installs with Runwire/CacheLayer/PSR-7 absent and
  feature-enabled installs. Verify no optional classes are loaded on normal paths.
- [ ] Run adversarial W01–W07 regressions and routing/middleware/URL/cache/HEAD/
  range/conditional/upload/error parity on the supported adapters.
- [ ] Validate real Runwire H1 and multiplexed H2; validate H3 on a prepared QUIC
  host before claiming H3 certification. Test FPM, native portable/prefork and
  the actual hosted runtimes advertised in release documentation. Fixture writers
  and simulated host drivers are unit/composition evidence, not live certification.
- [ ] Obtain actual Foundation and Infbyte consumer results at recorded SHAs.
  Webrick's named fixture tests do not execute those external applications.
- [ ] Measure unchanged 5.4, 6.0 unbound and 6.0 bound on the same
  controlled runner. Separate kernel microbenchmarks from real HTTP results.
  Cover static/dynamic/404/405, scoped handlers, real middleware, cache hit/miss,
  JSON, streaming/file/range, upload and cancellation/slow-client workloads.
- [ ] Warm up, run at several concurrency levels (initially 1/5/20/50, extending
  to saturation), use at least three steady-state trials, and compare median
  successful RPM and variance. Start with a 2% regression budget for stable
  workloads; define workload-specific p99, timeout, memory and queue limits
  before comparing. Reject invalid responses and growing queues/memory; do not
  relax correctness to meet throughput. Investigate noisy results instead of
  claiming a win. Record CPU, RSS, worker/connection utilization and cache stats.
- [ ] Soak persistent workers for repeated request cleanup, scope retention,
  cancellation, drain and replacement under representative traffic. Record a
  deployment-appropriate duration and resource ceilings, not only a short burst.
- [ ] Require all applicable CI checks on the exact final candidate SHA, plus
  the live/consumer/performance evidence above, before tagging. Recheck advisories
  immediately before release. Passing implementation checks, merging, publishing
  and tagging are distinct states.

Rollback: restore the complete prior application/artifact set and its compatible
cache namespaces/storage. Drain the current generation before switching. Do not
reuse incompatible 6.0 artifacts or mixed CacheLayer storage with the 5.x release.

## Audit evidence — 2026-10-03

This appendix preserves the evidence gathered against Webrick **5.4**, commit
`0f01cb4c303f94e683de8a3567682b70908122af`. Results and source line references
below describe that dated baseline, not a later 6.0 candidate. The working tree
was clean before the audit. The audit and plan consolidation changed documents
only; they did not implement fixes or change project dependencies.

### Scope and method

Broad risk-based review across routing/matching and generated artifacts; request
and response messages; URI/proxy/header boundaries; signed URLs and CSRF;
encrypted cookies; CORS and redirect policy; response caching, validators and
compression; throttle counters; files/uploads/streams; runtime adapters,
continuations and DI/request lifetime; optional dependencies; CI and release
evidence. This is not a proof that every possible defect has been found.

Used PHPForge's installed engineering principles, doctor/config commands and
quality detectors, existing tests, source inspection and targeted adversarial
probes. Graphify structurally indexed the 200 source files for navigation;
all findings below were checked in source and executed independently of the
graph. Temporary navigation/probe/compatibility artifacts are under `/tmp`.

The normal host runs PHP 8.5.4 and Composer 2.10.3. `ext-intl` is absent;
OpenSSL, zlib, PCNTL/POSIX and PHP's optional URI extension are present. No new
extensions were installed. Browser URL interpretation was checked with
`Uri\WhatWg\Url`; this is an audit oracle, not a proposed PHP 8.5 dependency.

### Dependency snapshots

| Package | Installed audit baseline | Isolated migration experiment |
| --- | --- | --- |
| InterMix | 10.1.1, `f687452b9b8d10333e7beb7d6905b479b2b8480e` | 11.0, `624862aa16700bf0b91c7ece81f7d70c0942bf89` |
| CacheLayer | 3.4, `b064b8196ddc4672ce37be252bc7a4cadb78527e` | 4.0, `58ae96dfe14ee45a528247c00c6a3d727160f833` |
| Runwire | 1.0, `7ab48fcf224ee86838e5bf82a50b998c2aaa8a90` | 2.1, `178308361772d4e995040a8ab84d4df876579078` |
| PHPForge | dev-main, `18917f38bad206cf7d1bc119c20373bac3982433` | Same reference |

The requested stable versions were resolved and installed from Composer in
`/tmp/webrick-latest-audit`, with only that copy's dependency constraints changed.
Plugins/scripts were disabled for the experiment. This validates availability
and exposes compatibility failures; it does not establish migration readiness.
The initial repository constraints exclude all three newer major lines.

### Verification results

| Check | Result / limitation |
| --- | --- |
| `composer validate --strict --no-check-publish` | Pass |
| `composer ic:doctor`, `ic:list-config`, `ic:active-config` | Healthy setup; bundled configuration resolved |
| Baseline Pest suite | **488 passed, 1,739 assertions**, no reported skips |
| `composer ic:tests:details` | **Exit 1**; PHPStan reports two diagnostics at `src/Support/InputSanitizer.php:111` |
| Final `composer ic:tests` | Same two PHPStan failures; the other 12 listed quality tasks pass, including the skip-directive scanner |
| Syntax / references | Pass: 299 PHP files; 284 symbols / 8,118 references |
| Duplicate detector | Threshold passes; 42 clone groups, 1,856 duplicated lines, 4.97%; findings need semantic triage |
| Comment policy / Pint / PHPCS | Pass |
| Deptrac | Zero violations, but **190 uncovered** dependencies; not complete architecture enforcement |
| Psalm | No errors; this is not proof of absence of vulnerabilities |
| Rector dry run | Pass |
| Live locked Composer audit | Zero advisories; nonzero exit because `doctrine/annotations` is abandoned (development chain: `phpbench/phpbench`) |
| New-dependency full suite | Aborts during test discovery: Runwire writer fixture lacks `ResponseWriterInterface::onTerminal()` |
| New-dependency InterMix scope subset | Five errors: old mutable `Container::definitions()` calls are no longer public |
| New-dependency cache/throttle/optional-boundary subset | 23 passed / 71 assertions, with two PHPUnit notices from the isolated runner |
| New-dependency production wrapper probe | `InterMixRuntime::resolveNow()` calls an undefined InterMix 11 method |

Baseline test command:

```sh
vendor/bin/pest tests \
  --configuration vendor/infocyph/phpforge/resources/pest.xml \
  --bootstrap vendor/autoload.php
```

Passing `tests` explicitly matters for this direct Pest invocation: otherwise the
bundled XML resolves its relative test directory inside PHPForge's resources.
The PHPForge command itself handles the project root.

PHPStan diagnostics:

```text
InputSanitizer.php:111
Access to constant FORM_KC on an unknown class Normalizer.
Parameter #2 $form of function normalizer_normalize expects int, mixed given.
```

The runtime code guards the optional function. Distinguish this local analysis
environment failure from a reproduced production crash. Resolve analysis
configuration/provisioning and verify both optional-extension paths.

The exact baseline has a passing
[Security & Standards run 36287138296](https://github.com/infocyph/Webrick/actions/runs/36287138296).
Verified jobs include PHP 8.4/8.5 stable and lowest QA, analysis, component
benchmarks, clean install, detail diagnostics and security report. The newer
run 36694595394 is Dependabot automation, not the quality suite.

No live multi-host HTTP certification, real Foundation/Infbyte consumer run,
production-equivalent sustained benchmark or long worker soak was performed
in this audit. The named Foundation/Infbyte and deployment tests use Webrick
fixtures/custom writers; their names must not be treated as external deployment
evidence. Current CI also does not test the proposed dependency ranges.

### Confirmed findings

#### W01 — Response-cache query collisions

Sources: `src/Middleware/ResponseCacheMiddleware.php:181`,
`src/Request/Core/Uri.php:145`.

`makeKey()` uses a canonicalizer that raw-decodes `+`, treats semicolons as
separators and sorts key buckets. PHP application query parsing has different
semantics. Real `Request` construction plus an isolated memory pool produced:

| First request query | Second query | Expected second body | Actual cached second body |
| --- | --- | --- | --- |
| `q=a+b` | `q=a%2Bb` | `{"q":"a+b"}` | `{"q":"a b"}` |
| `a=1;b=2` | `a=1&b=2` | `{"a":"1","b":"2"}` | `{"a":"1;b=2"}` |
| `a=1&a[]=2` | `a[]=2&a=1` | `{"a":"1"}` | `{"a":["2"]}` |

Each pair invokes the handler only once. This is a shared-cache poisoning /
wrong-response risk on cacheable routes whose result depends on those inputs.
It does not require bypassing the existing Authorization/Cookie exclusions.

Minimal reproduction, run from the project root:

```php
require 'vendor/autoload.php';
$cache = new Infocyph\Webrick\Middleware\ResponseCacheMiddleware(
    Infocyph\CacheLayer\Cache\Cache::memory('audit.' . bin2hex(random_bytes(6))),
);
$next = static fn ($r) => Infocyph\Webrick\Response\Response::json($r->getQueryParams());
foreach (['q=a+b', 'q=a%2Bb'] as $query) {
    $request = new Infocyph\Webrick\Request\Request(
        'GET', new Infocyph\Webrick\Request\Core\Uri('https://example.test/?' . $query),
    );
    echo $cache($request, $next)->getBody(), "\n";
}
```

Use `new Request(...)` here: `Request::fake()` supplies an explicit empty query
array by default and would obscure the parser distinction.

#### W02 — Browser-normalized redirect escapes the host guard

Source: `src/Middleware/GatewayHardeningMiddleware.php:177`.

With trusted host `example.test`, the guard accepts `Location: /\evil.test/path`
because `parse_url()` does not report a host. Independent WHATWG parsing against
`https://example.test/` produces `https://evil.test/path`.

```php
$guard = new Infocyph\Webrick\Middleware\GatewayHardeningMiddleware(
    trustedHosts: ['example.test'],
);
$response = $guard(
    Infocyph\Webrick\Request\Request::fake(uri: 'https://example.test/'),
    static fn () => new Infocyph\Webrick\Response\Response(
        302, '', ['Location' => '/\\evil.test/path'],
    ),
);
echo $response->getHeaderLine('Location'); // Accepted unchanged.
```

The exploit requires an application redirect influenced by untrusted input.
The parser behavior follows the
[WHATWG relative slash state](https://url.spec.whatwg.org/#relative-slash-state).
Rejecting ambiguous input can fix this without adding a parser dependency.

#### W03 — Protected dotted cookie names pass through unverified

Source: `src/Middleware/CookieDecryption.php:53`.

Outgoing encryption selects names by prefix, but incoming grouping only matches
a dot-free base plus an optional `.p<number>` suffix. A nonmatching cookie name
is copied directly to the downstream cookie map.

```php
$middleware = new Infocyph\Webrick\Middleware\CookieEncryptionMiddleware(str_repeat('K', 32));
$response = $middleware(
    Infocyph\Webrick\Request\Request::fake()->withCookieParams([
        'enc_session.extra' => 'plaintext',
    ]),
    static fn ($r) => Infocyph\Webrick\Response\Response::json($r->getCookieParams()),
);
echo $response->getBody(); // {"enc_session.extra":"plaintext"}
```

This affects applications consuming such protected names on transports that
preserve them, including Runwire's cookie mapping. PHP SAPI name normalization
can change exposure; this is not a claim that every cookie/application is affected.

#### W04 — Legacy cookie-store plaintext bypasses authentication/name binding

Source: `src/Middleware/CookieEncryptionMiddleware.php:458` and `decrypt()`.

The `S:<id>` lookup accepts a string that is not base64 as legacy plaintext and
returns it without decrypting or authenticating the cookie name. Seeding
`enc_cookie.<32 hex chars>` with `{"role":"admin"}` and requesting the same
reference as either `enc_profile` or `enc_session` returns that plaintext for
both names. Current authenticated ciphertext does not exhibit this substitution.

Precondition: such a legacy record exists and its reference is known, or the
attacker can influence that cache namespace. This is a conditional legacy-format
weakness, not a demonstrated remote attack against every fresh installation.
Remove implicit plaintext acceptance and document the migration.

#### W05 — Runwire capability flag bypasses application request limits

Sources: `src/Middleware/RequestLimitsMiddleware.php:56`,
`src/Runtime/Http/RunwireRuntimeAdapter.php:25`.

A five-byte POST body with `RequestLimitsMiddleware(maxBodyBytes: 4)` throws
`Payload exceeds maximum allowed size.` normally. Attach the capabilities
returned by default `new RunwireRuntimeAdapter()` and the same middleware calls
the handler and returns 200. The adapter advertises transport limits by default;
the middleware skips all configured body and header checks.

Runwire's own hard bounds may still stop larger requests. The defect is that
their existence does not prove a stricter application policy has been enforced.

#### W06 — HTTP deflate uses the wrong wire format

Source: `src/Middleware/CompressionMiddleware.php:234`.

Configure `minBytes: 0, prefOrder: ['deflate']` and request `Accept-Encoding:
deflate`. The response advertises deflate, but `gzuncompress()` fails while
`gzinflate()` succeeds. Webrick emits raw DEFLATE, whereas HTTP deflate requires
the zlib wrapper. This affects the explicit deflate option, not default gzip/br/
zstd preferences. See [RFC 9110 section 8.4.1.2](https://www.rfc-editor.org/rfc/rfc9110.html#section-8.4.1.2).

#### W07 — An upload can be marked moved after an incomplete read

Source: `src/Request/Core/UploadedFile.php:155`.

A legal custom `BodyStream` that returns `''` once while `eof()` remains false
and still has data causes `moveTo()` to return normally with a zero-byte target.
Observed result:

```json
{"returnedSuccess":true,"targetBytes":0,"sourceAtEof":false}
```

The copy loop breaks on an empty read, marks copying complete and then marks the
upload moved. This loses data for no-progress/nonblocking custom streams. The
response-copy path already distinguishes no progress from EOF; upload movement
needs an equally explicit contract.

### Migration findings and useful existing behavior

The existing Runwire factory accepts a concrete passed `RuntimeContext`, and
the HTTP adapter reads the exact native request context for cancellation,
deadline and request identity. These are useful foundations to preserve.

However, the adapter's public capabilities are manual booleans with persistent/
concurrent defaults; the factory does not derive them from its supplied context.
`RuntimeRequestExecution` exposes metadata/cancellation closures, not the concrete
borrowed Runwire context or coroutine scope. Core dispatch creates its own
InterMix scope and does not provide the requested general host → intermediary →
Webrick live-scope attachment API.

InterMix 11 already supplies `RuntimeContainerInterface` and a concrete Runwire
integration with `bind`, `withinRequest`, `withinScopeContext`, `wrapChild` and
CacheLayer forwarding. Reuse it with explicit ownership. Runwire 2.x adds terminal
writer notification and stricter request completion/reset/admission semantics;
updating interface fixtures alone is not enough to certify lifecycle behavior.

Source review also confirmed useful existing protections: header control-byte
validation, explicit trusted-proxy networks, signed URL HMAC comparison, CSRF
proof separate from cookies by default, cache privacy/Vary exclusions, required
atomic production throttling, explicit artifact trust paths, request-local trace
objects and streaming response scope retention. Preserve their regressions.

The documented native Runwire multipart limitation is intentional: the caller
owns decoding/storage of the raw bounded body. Do not turn that documented
boundary into an unsupported claim of full host-parsed upload parity.

### Remaining evidence boundaries

At the audit baseline, W01–W07 and local static analysis (W08) remained open.
Track subsequent remediation in the implementation phases above.
The dependency audit and green baseline CI do not contradict application-level
findings. Stable/lowest 6.0 CI, real host/protocol certification, consumer
composition, sustained successful RPM and persistent-worker soak remain release
gates. No release, tag, commit or production deployment was made by this audit.

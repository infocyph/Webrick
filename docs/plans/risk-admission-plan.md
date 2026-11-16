# Optional risk admission for Webrick

Status: planning complete; implementation and acceptance pending.
Date: 2026-11-16. Repository baseline: `981d095`.
Authorized repository scope: Webrick only. This task creates planning files first.

Follow [PHPForge engineering principles](../../vendor/infocyph/phpforge/resources/engineering-principles.md) throughout implementation. The accompanying [acceptance plan](risk-admission-acceptance.md) defines the evidence required to finish each phase.

## Outcome and boundaries

Add optional, route-selected native middleware that applies a host-supplied risk decision before an expensive authentication handler. The feature must support allow, challenge, deny and unavailable outcomes, observational shadow mode, controlled responses and request-local metadata.

The host application owns scoring, account identity, trusted proxy configuration, CAPTCHA verification, clearance issuance/consumption, trusted-device policy, credentials and authentication sessions. Webrick owns middleware execution and the HTTP response boundary. The host-selected runtime owns workers, event loops, cancellation and deadlines.

The pasted authentication example is design input, not production code to copy into Webrick. No changes to CacheLayer, TalkingBytes, ReqShield, Epicrypt, InterMix, Runwire or other sibling libraries are planned. Their existing public APIs are reused through host composition. Any demonstrated missing capability must be recorded as a separate scope decision rather than silently expanding this change.

## Inspected integration points

| Existing owner | Use or constraint |
| --- | --- |
| [MiddlewarePipeline](../../src/Router/Dispatch/MiddlewarePipeline.php) | Native request/closure middleware contract; preserve existing call and exception semantics. |
| [RuntimeMiddlewareDescriptor](../../src/Router/Dispatch/RuntimeMiddlewareDescriptor.php) and [HandlerCompiler](../../src/Router/Build/HandlerCompiler.php) | Resolve host policy in the active DI scope; verify registrar/compiled parity without serializing live services. |
| [ThrottleMiddleware](../../src/Middleware/ThrottleMiddleware.php) and [AtomicCounterAdapter](../../src/Interop/CacheLayer/AtomicCounterAdapter.php) | Reuse fixed-window attempt limiting with CacheLayer atomic counters. These are not distributed concurrency permits. |
| [RequestLimitsMiddleware](../../src/Middleware/RequestLimitsMiddleware.php), [GatewayHardeningMiddleware](../../src/Middleware/GatewayHardeningMiddleware.php), and [EndUser](../../src/Request/Http/EndUser.php) | Existing request and proxy boundaries; verify ordering rather than adding a second parser or unconditional header trust. |
| [RuntimeRequestExecution](../../src/Runtime/Http/RuntimeRequestExecution.php) | Observe supplied cancellation/deadline metadata. Never manufacture host execution contexts. |
| [PsrBridge](../../src/Interop/Psr7/PsrBridge.php) | Optional message conversion; not an existing PSR-15 middleware executor. No automatic PSR-15 compatibility claim. |

## Proposed public contract

Names are provisional until P1 verifies the contracts against existing conventions.

1. `RiskAdmissionMiddleware`: a native middleware receiving a host evaluator compatible with `callable(Request): RiskDecision`. Resolve and normalize the callable once at construction. No service lookup, reflection discovery, body parsing, network connection or backend selection inside the middleware itself.
2. `RiskDecision`: one immutable public result with validated action, optional non-negative integer score and bounded non-secret reason codes. This type is justified by the security invariant and the result crossing the host-policy boundary. Prefer named factories/constants over a second action/config/result hierarchy. Specify a single namespaced request attribute for the result.
3. A construction-time mode with explicit `enforce` and `shadow` values, defaulting to `enforce`. No automatic global installation: absence from the route is the disabled state. No implicit runtime/environment detection.
4. A host-supplied response renderer is optional. If provided, it returns a native `Response` for a rejecting decision and its result is validated. It cannot turn a rejecting decision into downstream handler execution. Keep authentication redirects and provider-specific challenge payloads in the host renderer.

| Decision | Default enforced behavior | Downstream behavior |
| --- | --- | --- |
| allow | Attach decision metadata to the immutable request. | Invoke once. |
| challenge | JSON 429 with a stable public challenge-required code. | Do not invoke. |
| deny | JSON 403 with a generic access-denied code. | Do not invoke. |
| unavailable | JSON 503 with a generic temporarily-unavailable code. | Do not invoke. |

Rejecting responses use `Cache-Control: no-store` and JSON content type; detailed reasons, scores and backend failures are not reflected publicly by default. Do not invent `Retry-After` without an actual retry interval. Respect Webrick's HEAD/body suppression behavior in both kernels.

Invalid decision values are programming/contract errors and follow the existing controlled error boundary. Known evaluator dependency failures should become explicit unavailable decisions. Exceptions must never silently become allow decisions. Default error responses must not reveal throwable details.

Shadow mode records the proposed result as request-local metadata and proceeds once, even for a valid rejecting/unavailable decision. It is an explicit observational bypass of enforcement and must not be enabled accidentally. The middleware performs no token mutations in either mode. The host must provide a non-consuming evaluator for shadow mode: separate any observational counters from enforcement state, preserve one-use clearance and avoid trust-cookie/session mutations. Malformed decisions still fail the contract in shadow mode.

## Reuse and responsibility map

| Library | Existing capability to compose | Responsibility that remains with the host |
| --- | --- | --- |
| CacheLayer | Atomic counters; `atomic()` capability with `setIfAbsent`, `getAndDelete`, CAS; ownership-token locks. | Shared backend selection, atomic capability checks, state consistency domain, key namespace, retention, outage policy and account binding. |
| TalkingBytes | HTTP client, explicit timeouts/response bounds, operation deadlines and supported passed-context integration. | Turnstile endpoint, expected hostname/action, replay policy, response validation and safe retry semantics. |
| ReqShield | Strict field schemas, validation limits and prepared validators. | Credential/challenge schema and privacy-safe identifier normalization. Reject before expensive verification. |
| Epicrypt | Password algorithm/options, verification and rehash APIs; cryptographic primitives if their wire format fits. | Account authentication, dummy-hash policy, key purposes, rotation, trust revocation and persistence. Existing HMAC formats do not change merely to introduce a dependency. |
| InterMix | Application-owned DI graph, inputs and scoped runtime resolution. | Supply the evaluator and already configured clients; do not embed mutable clients, credentials or trust state into generated artifacts. |
| Runwire | Supplied execution/cancellation contexts and scheduler-local coroutine semaphore. | Host capacity, process supervision and I/O strategy. Scheduler-local limits do not claim cross-process coordination. |

No new hard production dependency is planned. Add a test-only/suggested dependency only when an executable example or tested optional boundary actually needs it. Keep provider calls in the reference host composition; do not create pass-through adapters for every library. Ordinary Webrick installations must work without optional providers and without any risk-related I/O.

### Shared state rules

- Use a shared authoritative backend for security state. An in-memory or local-file fallback must not silently replace it across workers or nodes. Layered caches must not independently authorize replay-sensitive operations.
- For CacheLayer authentication state, explicitly select `failOpen: false` and verify the required atomic capability at composition time. Generic PSR-6 `get` plus `save` does not provide atomic redemption.
- Namespace keys by application/tenant, purpose, policy version and applicable subject. Hash sensitive identifiers with an appropriate cryptographic construction; set bounded TTLs and bound attacker-controlled key cardinality.
- Use one-time operations for both clearance consumption and successful CAPTCHA-redemption deduplication. Multiple HTTP validations of one provider token must not mint multiple independent passes.
- Prefer positive outstanding-clearance state whose atomic consumption fails closed when the record is missing. If replay tombstones are used, require retention for the complete acceptance window and document eviction/restart/failover assumptions; signing a token does not preserve its consumed state after backend data loss.
- Multi-signal scoring must document whether it reads a snapshot or separately updated counters. Do not claim cross-key atomicity from per-key counters; keep any required backend-specific transaction in the host's explicit provider boundary.
- If Redis Cluster is supported by the chosen client/operation, verify hash-slot constraints. Redis script errors must not become allow decisions.

### Stateless REST CAPTCHA flow

The API uses no PHP session, session cookie or sticky worker for CAPTCHA. Each request carries the material needed to evaluate it. Shared anti-replay records are security resource state, separate from a conversational login session. Completely storage-free validation cannot guarantee strict one-use behavior.

All application routes and URLs are host-owned. Attach the middleware to routes selected through the existing registrar; Webrick must not register, match or construct a fixed login/challenge path. Any challenge-exchange, frontend callback or redirect URL is explicitly supplied or generated by the host router. The CAPTCHA verification endpoint also belongs to the injected host provider/client configuration, with no hidden hardcoded endpoint in Webrick. Validate that configuration once: use an approved HTTPS destination, preserve TLS verification and prevent request-controlled endpoint replacement or redirects that could expose provider credentials.

The default reference integration submits the provider proof with the protected request; a second clearance token and separate CAPTCHA verification endpoint are not required:

1. A bounded `POST` request to the host-selected protected route reaches the evaluator. If the policy requires a challenge and no valid proof is supplied, return the challenge-required 429 JSON response before calling the authentication handler. The host renderer may include public provider/sitekey/action information and configured client-facing URLs; never include the provider secret.
2. The browser frontend runs its Turnstile widget and receives a short-lived provider token. Webrick does not generate CAPTCHA images or render browser UI.
3. The frontend resubmits the same host-selected protected request with credentials and a `captcha_token` JSON field over HTTPS. Keep proofs out of URLs and logs. The existing host body parser/validator owns bounded JSON parsing once; the risk middleware does not add a second parser. A native/mobile client needs a supported provider frontend flow; the API cannot itself solve a challenge for a headless client.
4. The host evaluator validates the proof through its configured verification endpoint using TalkingBytes with explicit timeouts, response limits and supplied cancellation. For the Turnstile reference provider, this is Siteverify. Validate success, configured hostname/action, token schema and any configured request-context binding.
5. After successful provider validation, atomically claim a cryptographic fingerprint of that proof in the shared CacheLayer store before authorizing the challenged attempt. Concurrent provider validations can both succeed, so only the successful claimant may proceed. Deduplicate across every accepting route/account/tenant sharing that provider proof domain, rather than putting the same proof into independent per-account replay namespaces. Validate any account/tenant binding separately.
6. Retain the consumed marker for at least the provider's remaining acceptance window plus a documented clock margin; enforce the backend retention/durability policy. A duplicate, invalid or expired proof requires a new challenge. An unavailable provider or anti-replay store produces controlled unavailable behavior. Do not delete a consumed marker to retry a failed login or an ambiguous response.
7. A verified, claimed proof satisfies only the CAPTCHA condition for one attempt. Ordinary account/IP/tenant limits, hard deny decisions, hash-capacity admission and password verification still apply. A wrong password consumes the proof. Successful authentication then follows the application's existing bearer-token or other authentication protocol; CAPTCHA proof is never an authentication credential.

Turnstile currently documents a 300-second provider-token lifetime and eventually consistent reuse detection; recheck those values/semantics when implementing. The reference integration does not promise zero duplicate provider calls, but must guarantee at most one proof-based admission inside its documented shared-backend consistency domain. Rate limits and bounded request concurrency protect the verification calls themselves.

An optional host-registered challenge-exchange route, at a host-selected URL, may exchange a provider proof for a short-lived opaque clearance when a separate client flow is necessary. Apply the same provider-proof deduplication before issuance, store positive outstanding-clearance state with tenant/account/action/expiry bindings, then atomically consume it during login. Use a deployment-configured lifetime such as 120 seconds. A signed token or JWT alone does not establish single-use semantics. This alternative is not an additional core Webrick controller or a mandatory session mechanism.

Shadow-mode evaluators do not redeem live provider tokens, claim replay markers or consume clearances. Cross-origin frontends receive explicit host CORS configuration for the login/challenge routes; neither credentialed cookies nor a custom clearance header are required by the default JSON-body flow.

### Hashing capacity boundary

V1 does not implement a distributed semaphore or promise a hard global active-hash limit. The reference host first uses measured authentication-pool/process capacity and rejects excess work with existing admission controls. Runwire-local bounded admission is optional where the host provides the relevant scheduler.

CacheLayer ownership-aware locks can support a later separately designed permit pool, but expiring one shared counter and decrementing it in `finally` is prohibited. Any future pool must define acquisition, ownership, renewal, crashed-holder recovery and stale release behavior. Lease expiration does not itself stop an old worker's hashing; a hard CPU ceiling needs a host-enforced execution boundary. This capability is not required to complete the middleware integration.

## Security requirements carried into host examples

- Treat JA4 as one optional risk signal. No hardcoded browser allowlist, automatic trust or guaranteed unique-device interpretation. Missing fingerprints must have an explicit policy; unfamiliar fingerprints alone must not cause unconditional denial.
- Accept proxy-supplied JA4/IP only through an authenticated/configured edge boundary that overwrites client headers and prevents direct-origin bypass. The host passes authoritative metadata; Webrick does not claim to inspect TLS handshakes.
- Bind device-trust benefits to the attempted account and tenant, purpose, expiry and revocation state. An attacker authenticated to their own account must gain no trust reduction against another account. Define privacy-safe diagnostics and cookie attributes; do not reflect browser HttpOnly trust tokens in JSON.
- Validate token lengths, exact encodings and integer bounds; capture wall time once per verification. Specify key rotation, purpose separation and unambiguous signed payload encoding. Keep passwords, raw clearance, device secrets and HMAC keys out of logs/artifacts.
- Rate-limit the challenge endpoint as well as login attempts. Validate provider success, configured hostname/action and response schema; apply total/connect/response-size bounds and supplied cancellation. Do not retry redemption blindly.
- Reuse existing body limits before parsing and strict schema validation before credential verification. A rejection proves the handler/password-verifier was not invoked; it cannot guarantee a host did not already construct a database connection.
- The default production failure policy is bounded rejection/unavailable, not catch-all fail-open. Optional host availability policies must retain independent admission limits and be explicitly documented.

## Implementation sequence

| Phase | Work and deliverable | Exit evidence | Status |
| --- | --- | --- | --- |
| P0 | Confirm repository-only scope, inspected owners, provider reuse and the two planning files. | Source/API inspection and documentation link checks. | Verified for planning. |
| P1 | Finalize the two public types, named parameters, decisions, defaults, attribute and renderer/shadow semantics. Record a justification for any additional production type. | Contract tests A01-A08; active complexity 80/12 preserved. | Pending. |
| P2 | Implement native route middleware and explicit DI wiring; preserve registrar/compiled routing, request scoping and rejection semantics. | A09-A13, compiler/artifact parity and optional-provider absence. | Pending. |
| P3 | Add one maintained host-composition guide and runnable test/example fixtures using existing provider APIs. Include the stateless REST CAPTCHA flow, host-configured routes/URLs, enforcement, shadow and degraded-mode examples. | A14-A26, real shared-backend concurrency and reference-example validation. | Pending. |
| P4 | Complete PHPForge checks, documentation/package/consumer checks, matched performance and worker qualification. | Acceptance ledger with actual commands, revisions, prerequisites and results. | Pending. |
| P5 | Classify/version the final additive API, document migration/deployment, qualify the committed candidate, then remove obsolete plan files after reference checks. | Final-SHA CI and applicable certification; durable user docs retained. | Pending. |

A new public middleware capability normally warrants a minor release; `6.1.0` is a provisional target from the inspected `6.0.4` line. Confirm the current published baseline and final API before assigning a release. Do not tag, push or publish as part of planning.

## Engineering checklist

Statuses distinguish plan review from implementation evidence; all implementation gates remain pending.

| Rule ID and ruling | Affected boundary | Required evidence | Status |
| --- | --- | --- | --- |
| EXEC-01/02/03: explicit scope and evidence | Plan, implementation, final report | Maintain phase/acceptance ledgers and identify covered revision. | Planning verified; implementation pending. |
| CORE-01/02: smallest scoped optional change | Webrick only; no implicit request work | Diff review, absent-provider consumer, off-route workload. | Pending. |
| STRUCT-01/02/04: cohesive owners and justified public types | Middleware/result and host callbacks | Justification per new type; 80/12 analysis; no pass-through hierarchy. | Proposed design reviewed; implementation pending. |
| TYPE-01 and COMPAT-02/04: strict public contracts | Decision, named parameters, examples | Malformed-input cases, documented defaults and executable examples. | Pending. |
| SEC-01/02/03/08/09: trust, replay, time, secrets | Host examples and risk boundary | A14-A24; no auth/crypto weakening for throughput. | Pending. |
| DATA-03/04/06/07: bounded I/O and authoritative state | Cache/CAPTCHA/provider composition | Timeout/failure/atomicity tests and no local fallback. | Pending. |
| OPS-01/02/04/05: host ownership and isolation | FPM/Runwire and persistent workers | Cancellation, replacement, parallel-request isolation and soak. | Pending. |
| PERF-01/03/04/07: successful RPM and stable evidence | Disabled/enabled/slow/rejecting workloads | Matched baseline/candidate and existing unchanged budgets. | Pending. |
| FLOW-01/02 and CONFIG-02: complete gates and dependency isolation | Tooling, archive and consumers | Required checks pass with unchanged detectors and actual archive inspection. | Pending. |
| OBS-02/04: bounded diagnostics | Metadata, logs and response renderer | Privacy/redaction and bounded logging tests. | Pending. |

TLS fingerprint collection, user-database schema changes and distributed permit-pool implementation are outside this feature's authorized library boundary. Security requirements for them apply to the host reference examples; no code-level completion is claimed for external deployments. A full PSR-15 executor is also outside scope because the selected feature uses Webrick's existing native contract.

## Plan lifecycle

Keep these two files only while this feature has pending work. Record durable API, deployment and verification guidance in the maintained user documentation during implementation. When the feature is complete, check references and remove the planning files; they are not permanent release reports. The existing `docs export-ignore` rule already excludes them from distributions.

## Execution tracker (append-only)

### Batch 01 — Native risk decision contract (2026-11-16)

- Base branch revision: `70ea3b1d7526eb7bc608bd3e41480917468fbf62`. PR: [#59](https://github.com/infocyph/Webrick/pull/59) (draft; do not merge).
- Scope: add `RiskDecision`, opt-in `RiskAdmissionMiddleware`, and focused unit contract tests; no vendor integrations, global registration or middleware-pipeline rewrites.
- Contract decisions: actions `allow/challenge/deny/unavailable`; default `enforce`, explicit `shadow`; decision request attribute `webrick.risk_admission.decision`; rejection JSON status 429/403/503; enforce `Cache-Control: no-store` on default/custom rejects. Evaluator exceptions propagate to the existing kernel error boundary, not fail-open. Host remains responsible for distinguishing known outages and returning `unavailable`.
- Engineering principles: CORE-01/02 minimal scoped change; FLOW-02 root-cause quality gates unchanged; TYPE-01 strict result validation; COMPAT-02/04 public named arguments and accurate types; SEC trust/replay remain host-owned; PERF-07 unprotected route remains unchanged.
- Verification: focused unit tests added; repository PHPForge, Psalm, PHPStan, hosted CI and downstream integration acceptance pending. Do not mark P1 complete until the checks pass.
- Next: verify A01-A08, then P2 registrar/compiled/InterMix and scoped evaluator parity; remediate reported analyzer findings in separate single-commit batches.

### Batch 01 QA correction (2026-11-16)

- First candidate `6d989412d65c6cf50284348039907acdaeb8483e`: hosted PR checks reported 635 PHPStan errors (630 pre-existing on main, 5 new), and the quality lanes failed on Pint class layout and PHPCS unused test parameters. Pest itself passed.
- Corrective actions: conform to PHPForge PER style for constants, narrow-check runtime callback results at the real untyped host boundary, retain strict validation of dynamically supplied reason codes, and use Reflection-based negative tests rather than inaccurate PHPDoc or ignored invalid callables.
- No detector config, exclusions, baselines, or test assertions weakened. CI rerun against next committed revision is pending.
### Batch 01 dynamic-callback typing follow-up (2026-11-16)

- Candidate `2803089f932269f7c7ab56b9100e1dea60620058`: no-intl PHPStan reported 632 errors, compared to the 630-error main baseline. The two new findings were inferred-true type checks for host-supplied callbacks.
- The executable contract must reject incorrect evaluator/renderer values from dynamically composed providers. Their constructor PHPDoc now reflects the real dynamic trust boundary (`mixed` return), while both results remain checked at runtime and documented to require `RiskDecision`/`Response`. No detector settings or runtime checks were relaxed.
- Awaiting hosted CI on the subsequent commit; full static-analysis backlog and release acceptance remain open.

### Batch 01 documentation correction (2026-11-16)

- Merged constructor parameter and exception contracts into one PHPDoc block. CI validation pending.

### Batch 02 — risk contract Psalm remediation (2026-11-16)

- Baseline verified for exact SHA `b1dc88797aef7bc060fb90bdc0e1921c2415020e` via [hosted run 43831667584](https://github.com/infocyph/Webrick/actions/runs/43831667584): all four PHP 8.4/8.5 QA lanes and both benchmarks passed; no-intl PHPStan **630** findings (same count as pre-existing main), Psalm **2,224** (main previously 2,231). Analyzer gates still fail.
- Open review feedback in PR #59: four Psalm `MissingThrowsDocblock` findings in `RiskDecision` and two unused public diagnostic property warnings; added explicit constructor and factory exception documentation, verified property access in unit contract fixtures, and covered associative/non-string reason metadata.
- P1 A02/A05 unit checks expanded; registrar/compiled A09-A13, HEAD, host composition A14-A26, full analyzer cleanup, and release qualification remain pending. Next: verify this revision on hosted CI before closing its review findings.

### Batch 03 — explicit inherited-contract overrides, 50 files (2026-11-16)

- Input: Psalm diagnostic listing from [CI run 43831667584](https://github.com/infocyph/Webrick/actions/runs/43831667584), SHA `b1dc88797aef7bc060fb90bdc0e1921c2415020e`. Rule: `MissingOverrideAttribute`.
- Exactly **50 files** updated (39 `src/` production files, 11 `tests/` files), **306** method declarations given native `#[\\Override]` at the specific reported method lines. Each target verified as an existing method declaration before updating; no signatures, bodies, return values, dependencies, or detector settings changed.
- Batch includes PHP 8.4+ interface and parent overrides (request/response/stream implementations, route matchers, runtime adapters and fixtures). This explicitly enforces overriding contracts using the supported language attribute, per PHPForge COMPAT-02.
- Status: **implemented, CI unverified**; expected reduction of 306 `MissingOverrideAttribute` diagnostics is a target, not a verified result. Validate Pint, PHPCS, Pest, Psalm, PHPStan, PHP 8.4/8.5 matrix, and performance/benchmarks at the committed SHA. If any attribute fails a real contract or formatter check, correct source instead of weakening analysis. One batch/one commit.
- Remaining Psalm `MissingOverrideAttribute` findings include 13 other files/148 locations (historical snapshot); broader missing-throws/type defects remain open. Continue with the next analyzer family only after triaging current CI.

### Batch 04 — complete remaining inherited-method overrides, 13 files (2026-11-16)

- From historical Psalm `MissingOverrideAttribute` diagnostics at [43831667584](https://github.com/infocyph/Webrick/actions/runs/43831667584), fixed all **148** remaining reported method locations across **13** files (10 test/fixture files, two benchmark files, root `routes.php`). Each attribute inserted only after verifying the exact method declaration at the diagnostic line.
- Combined Batch 03 + 04 scope: 63 files and 454 method declarations in two separate commits; implementation is complete for this known diagnostic snapshot. Real CI validation pending for this revision; do not claim 454 fewer diagnostics until the latest analysis run verifies it.
- Native PHP 8.4+ `#[\\Override]` preserves existing method signatures, call sites and request logic. No analyzer baselines/exclusions changed. New snapshot after QA determines whether further missing-override remediation is needed.
- Risk admission phases P2-P5, shared-backend security, and broader Psalm/PHPStan findings are unaffected and remain pending.

### Batch 05 — PR type-contract feedback, 14 files (2026-11-16)

- Source revision: `4f5439cb83c609163d2c950a22a12d1c03da77ff`; feedback in [PR #59](https://github.com/infocyph/Webrick/pull/59) and PHPStan no-intl [CI 43834246806](https://github.com/infocyph/Webrick/actions/runs/43834246806).
- Three native Request hierarchy `__clone` overrides no longer declare an unnecessary `: void` return type (Psalm `MethodSignatureMustOmitReturnType`), preserving clone side effects and callable parameters.
- Eleven Runwire `RequestBodyInterface` test/benchmark fixture implementations changed `trailers(): ?Headers` to the covariant non-null `trailers(): Headers` because every implementation returns `new Headers()`; aligns with PHPStan `return.unusedType` without changing payloads, I/O or interface requirements.
- This batch targets **14 files / 14 type-contract findings**. Source/test behavior and quality rules unchanged; compile/runtime, PHPStan, Psalm and PHPForge QA on the next candidate are pending. No revision marked release-ready.

### Batch 06 — registrar opt-in and HEAD response parity (2026-11-16)

- Candidate base: `67d09c54e78a979961ce8cdc1d94d3b3226aec70`. Added focused registrar fixture for an unprotected route and a risk-protected route. Asserts evaluator skipped entirely off-route, protected handler never invoked for a challenge, and HTTP status/no-store remain correct for GET and HEAD.
- A07: identified existing registrar kernel behavior that normalized HEAD only on pre-routing gate responses, unlike the compiled kernel which suppresses bodies for ordinary matched-route responses. Applied the minimal HEAD-only response normalization in `RouterKernel::handle()`; no change to middleware pipeline invocation or non-HEAD responses. Requires PHPForge performance equivalence/QA verification on the new commit.
- Fixed a remaining Psalm `MethodSignatureMustOmitReturnType` in base `Message::__clone()` to match the other Request class hierarchy corrections from Batch 05.
- Tests added are pending execution; P1/P2 A01/A03/A07 partly covered, while compiled artifacts, InterMix-scoped evaluator, A09-A26, full static-analysis cleanup and release gates remain pending. Keep tracked acceptance evidence tied to the actual revision.

### Batch 06 QA correction — representative kernel request fixture (2026-11-16)

- [CI run 43835466242](https://github.com/infocyph/Webrick/actions/runs/43835466242) reported exactly one new focused Pest failure at `tests/Feature/RiskAdmissionRoutingTest.php:48`: the test created synthetic requests through `Request::fake` with no server context, and the resulting health request returned HTTP 400 instead of 200. Other 656 tests passed on that lane; Pint/PHPCS passed.
- Changed only the new feature test to use the existing `mockRequest` helper, matching how established registrar-kernel integration tests populate request/server/Host context. No production security gate bypass or relaxed assertion. Recheck the HEAD and route-specific assertions on new CI; status **pending** until verified.

### Batch 06 diagnostic pass — isolate registrar HTTP 400 (2026-11-16)

- [CI 43836037296](https://github.com/infocyph/Webrick/actions/runs/43836037296) still returned HTTP 400 for the unprotected registrar fixture even with `mockRequest`, while the 656 other tests continued passing. Therefore the previous request-context hypothesis was insufficient; no production change should be made on that assumption alone.
- Added failure-only diagnostics in the feature test (explicit URI/Host and debug-mode error response) to identify the actual root cause on CI. This test is not an application runtime and the debug fixture must not be reused for production requests. Test acceptance remains **blocked** until diagnosed and corrected.

### Batch 06 final root-cause correction — explicit plaintext status (2026-11-16)

- [CI 43836877991](https://github.com/infocyph/Webrick/actions/runs/43836877991) confirmed HTTP 400 **with body `ok`** from unprotected `/health`, not a route-match error. Source inspection: `Response::plaintext(string $msg, int $code = StatusEnum::BAD_REQUEST->value)` defaults to **400**, not 200. The new fixture constructed the wrong expected status.
- Fixed only the new test: use `Response::plaintext('ok', 200)`, remove the temporary `debug: true` and failure-only diagnostic message; keep full route absence, rejection/HEAD and handler-bypass assertions intact.
- Prior fixture hypothesis concerning Request::fake/Host was incorrect; superseded by the verified Response factory default. Required evidence: next hosted QA, strict analyzers and benchmarks. No weakening of production validation or interface changes.

### Batch 07 — analyzer type aliases and benchmark generics (2026-11-16)

- Verified [CI 43837345213](https://github.com/infocyph/Webrick/actions/runs/43837345213) at `ee39cc9ab2afc6318402f23973640ce3ec5448ca`: four QA lanes **passed**, both benchmarks **passed**, no-intl PHPStan **619 errors**, Psalm **1,765 errors**. Static-analysis gates remain red, primarily pre-existing findings; the new registrar test is now green.
- PR #59 review surfaced three Psalm `InvalidArgument` errors for dynamic `Registrar::get` because public `@method` signatures referenced `@phpstan-type` aliases not declared for Psalm. Added matching explicit `@psalm-type` aliases to `Registrar` and `Router` without changing their runtime APIs, accepted handler shapes, routes or dispatch costs.
- Resolved two independently reported PHPStan `missingType.iterableValue` findings by declaring the PSR-6 cache-item return iterable and PSR-16 setMultiple value iterable in the existing benchmark failure-store fixture. No return/argument behavior changed.
- Scope: exactly 3 PHP files plus both append-only progress trackers; one commit. Expected review resolution is pending new analyzer diagnostics. No configurations, suppressions, or thresholds weakened. Continue P1/P2 acceptance and static-remediation batches after QA validation.

### Batch 08 — typed native Pest response assertions, 14 files (2026-11-16)

- Source revision: `c1376b46f1b8a8d5a6c7a72d2f1663ba95a3b386`; [CI 43837944076](https://github.com/infocyph/Webrick/actions/runs/43837944076) verified **618** no-intl PHPStan errors, **1,766** Psalm errors, 4/4 PHP QA and 2/2 benchmarks passed. These analyzer counts are not claims of release readiness.
- Remediation: replace **106** usages of dynamic Pest `toHaveStatus` / `toHaveHeader` expectations across **14 test files** with native `Response::getStatusCode()` / `Response::hasHeader()` followed by typed Pest `toBe` / `toBeTrue`. The current no-intl PHPStan report contained approximately 96 `method.notFound` diagnostic occurrences for these macros; CI must confirm the reduction.
- Preservation: the existing custom `toHaveHeader` expectation checked header presence only (its second argument was ignored), so each replacement checks exactly the old assertion, including chained expectations and repeated middleware calls. No runtime `src/` files, public APIs, test fixtures, analyzer settings, baselines or threshold modifications. Further semantic strengthening of header-value tests requires separate explicit assertions.
- Planned verification: PHPForge Pint/PHPCS/Pest, strict Psalm/PHPStan, all four PHP lanes and benchmarks. **Implemented, not yet verified**. Risk A01-A26 and compiled/provider acceptance remain as previously recorded; no implied release readiness.

### Batch 08 verification and Batch 09 — magic-route contracts and benchmark iterable types (2026-11-16)

- Verified Batch 08 candidate `86df760fea9cac455cfd8b5a3d81c6552ec794ab` on [CI 43840921171](https://github.com/infocyph/Webrick/actions/runs/43840921171): **4/4 PHP 8.4/8.5 QA lanes passed**, **2/2 benchmarks passed**, PHPStan no-intl **524** errors (down from 618), Psalm **1,750** (down from 1,766). Analysis gates remain failing and no release-readiness is claimed. The 94/16 error reductions are observed, not projected.
- Batch 09 scope: **three files** (the two public route-registration PHPDoc contracts in `Registrar`/`Router` and the existing `ResponseCacheBench` failure-store fixture). Inline the exact magic-method handler/options union contracts for all seven verbs in both classes, so both PHPStan and Psalm can resolve types without treating local aliases as class names. This updates documentation only; PHP route selection, handler dispatch, and public native call signatures remain untouched.
- Existing PSR-cache benchmark fixture documents its producer's expected `iterable<string,CacheItemInterface>` result and relaxes its `setMultiple` accepted key type to `array-key`, honoring the upstream interface's integer-or-string key contract. No extra iteration/I/O or benchmark workload changes.
- Follow-up verification required: Psalm route fixture `InvalidArgument` review findings (3), PHPStan `getItems` return type (1), Psalm `setMultiple` implemented-parameter variance (1), all QA/benchmarks and updated reviewer feedback. These are **targets**, not yet passed.
- Strict PHPForge and analyzer configurations unchanged; continue all P1-P5, A01-A26 and static backlog stages in discrete commits.

### Batch 09 verified / Batch 10 — typed matcher dataset boundaries (2026-11-16)

- Verified Batch 09 at SHA `8ded2c1cac4675cb702bcb66b88746f0530aa1a3` using [CI 43841734657](https://github.com/infocyph/Webrick/actions/runs/43841734657): **four PHP 8.4/8.5 QA lanes green**, **two benchmark lanes green**, PHPStan no-intl **523** errors (down 1), Psalm **1,567** errors (down 183). Static analyzer gates remain failing overall; 14 magic route doc contracts fixed and the benchmark `getItems()` issue was removed. The benchmark `setMultiple` Psalm parameter-variance finding remains and needs a separate correction.
- Batch 10 scope: exactly **four test-only files**, including the existing shared test helper. Introduce `makeTestMatcher(Closure): MatcherInterface` as an explicit runtime-validated dataset boundary, and use it at **22 call sites** across three matcher test suites (compiled outcomes, matcher performance revision and generated matcher cache cases). Factory outputs previously appeared to static analysis as `mixed` even though the data sets contain MatcherInterface implementations.
- This is one coherent fix at the test fixture ownership boundary; no production route matcher code, dispatch path, dependencies, configuration, quality rules, benchmark workload, or public APIs are changed. Runtime misuse of a dataset factory now fails with a clear exception instead of an unchecked mixed method call.
- Validate next SHA against Psalm/PHPStan, PHPForge style and QA, both PHP runtimes and performance matrix. **Batch 10 implementation pending verification**. P2 compiled artifact parity and A09-A26 host/security acceptance remain pending.

### Batch 11 — PHPForge extension matrix and redundant no-intl job (2026-11-16)

- Source baseline `b4701828f85f18d58b7d931ac0a9cfb7203dba83`: [GitHub Actions 43842417101](https://github.com/infocyph/Webrick/actions/runs/43842417101) recorded **424** PHPStan errors in the dedicated no-intl job and **1,578** Psalm errors; all four PHP 8.4/8.5 QA lanes and both benchmarks passed. Static-analysis jobs failed, so release readiness remains blocked.
- Verified the current upstream PHPForge reusable Security & Standards workflow accepts `php_extensions` as a comma-separated setup-php extension input in addition to `integration_services: '[]'` and `service_topologies: '{}'`. The shared workflow supplies these extensions to QA, clean install, analysis and benchmarks.
- Configure `php_extensions: intl` at the existing PHPForge reusable-workflow call and remove the separate job which deliberately ran PHPStan with `:intl`. This is a CI ownership consolidation, **not** a static-analysis exemption: PHPForge's strict analysis remains enabled on both supported PHP runtimes. Preserve `detail-diagnostics` and its `php -n` InputSanitizer behavior test, which verifies optional operation without ext-intl.
- Validation: YAML structure reviewed; required hosted PHPForge CI on the new commit **pending**. Do not interpret removed duplicate no-intl job as reducing production analyzer errors: compare analyzer evidence on matching extension configurations.

### Batch 12 — explicit exception contracts in 50 production files (2026-11-16)

- Input evidence: SHA `215525625a7bec6473613a270d33a17281d89d40`, [PHPForge CI 43845029582](https://github.com/infocyph/Webrick/actions/runs/43845029582), PHP 8.4/8.5 QA **4/4 passed**, benchmarks **2/2 passed**, PHPStan **424 errors**, Psalm **1,578 errors**. Strict PHPForge analysis remains failing. The former no-intl duplicate job has been removed; the shared `php_extensions: intl` configuration ran successfully.
- Selected the 50 `src/` files with the largest `MissingThrowsDocblock` error-site counts in that exact Psalm diagnostic snapshot (**541** reported error sites across those files). Matched each diagnostic to its containing named method, preserving the pre-existing PHPDoc, named argument declarations and method signatures.
- Prepared **346 explicit `@throws` exception type entries across 323 diagnosed methods**. Annotations use reported exception identities (fully-qualified in PHPDoc), including artifact validation, matcher IR, request/response boundaries and runtime adapters. These changes modify comments only: **no executable control flow, exceptions thrown, API signatures, authorization, I/O, or performance hot path changed**.
- Static-analysis target: eliminate the matched missing-exception-contract findings without adding skip directives, baselines or config weakening. **No numerical reduction is claimed before next hosted CI**; propagation to calling methods and any documentation-style issues will be resolved from actual feedback.
- Tracking: one batch/one commit; next reconcile CI, PR threads and remaining Psalm/PHPStan root-cause classes. Risk admission P1-P5 and A01-A26 remain at their independently verified statuses.

### Batch 13 — precise analyzer contracts and compiled-route validation (2026-11-16)

- Baseline: PR #59 HEAD `d3ef0d4739337810971dc936314265364ea76334`; [run 43846752612](https://github.com/infocyph/Webrick/actions/runs/43846752612): PHPForge QA 4/4 passed, benchmarks 2/2 passed, clean install passed; PHPStan **424** and Psalm **1,376** errors remained on PHP 8.4/8.5. This verifies Batch 12 reduced Psalm by 202 relative to the previous 1,578-error run; PHPStan did not improve.
- PR review inventory at the baseline: 12 unresolved inline threads, predominantly Psalm type/return coercion and exception/Fiber documentation. The sharded matcher intentional preload/unused-return concern remains for separate investigation; no thread is marked resolved merely because source is edited.
- Scope: nine production PHP files with targeted root-cause changes, plus append-only updates to the two existing plan/acceptance trackers; **one batch, one commit**. No public signature, analyzer configuration, suppression, baseline, excluded path, CI threshold, or test assertion is changed.
- Risk/security boundary: enforce active cookie-key byte range on both sides; preserve RiskAdmissionMiddleware's invalid-decision and serialization error contracts; preserve read-only artifact authenticity and metadata validation. Validated JSON and PHP-array artifact data receive precise post-check types rather than unchecked casts; matcher fields retain explicit runtime validation.
- Router/runtime: make the compiled route-map normalizer's filtering explicit and typed (same accepted keys and values); narrow segment fields once at trust boundaries, without extra per-request parsing. Separate PHPStan Fiber/generator generics from Psalm's different modeled type parameters without changing scheduler ownership or execution.
- Engineering rulings: CORE-01/02 minimal and secure change; TYPE-01 validate at untrusted data boundary; FLOW-02 correct source contracts with unchanged rules; COMPAT-02/04 preserve public named arguments and PHP 8.4+ contracts; PERF-07 preserve route fast path; EXEC-03 require exact-SHA QA/reviewer evidence.
- Verification **pending on this new commit**: Pint, PHPCS, Pest, strict PHPStan/Psalm on PHP 8.4/8.5, benchmarks, clean install, and code-scanning review. A01-A26 compiled/host security/performance/release acceptance not advanced by typing fixes. PR stays draft; no merge, tag or release.

### Batch 14 — CI regression correction after Batch 13 (2026-11-16)

- Input: HEAD `c1b21c6af6e6dce55972a42f837c1f48554c8221`, [CI run 43854846322](https://github.com/infocyph/Webrick/actions/runs/43854846322): 4/4 PHP 8.4/8.5 QA passed, 2/2 benchmarks passed, clean install passed; PHPStan **426** (increased 2), Psalm **1,372** (decreased 4), strict analysis still failed. Do not mark Batch 13 verified green.
- Root-cause findings: adding a redundant lower-bound condition to cookie key ID triggered `smaller.alwaysFalse` and pushed the class over the active 80 complexity limit; it was removed and the analyzer-specific return contract now reflects Psalm's inferred bound without relaxing runtime validation. Psalm also parses `@phpstan-*` variable/parameter Fiber generic tags, reporting incompatible template arity; explicit `@psalm-*` alternatives were added beside PHPStan tags for the same symbols. Previously introduced mixed-valued temporary assignments in matcher/route normalization were reverted to the original runtime-validated expressions.
- Scope: five production files plus two append-only tracker updates, one commit; no detector suppression, quality threshold change, runtime behavior alteration, or auth/security bypass. PHPForge FLOW-02/COMPAT-02, TYPE-01, CORE-01/02, EXEC-03. New CI is **pending**; no projected count reduction is claimed. PR #59 stays draft and unmerged. A01-A26, docs/consumers, performance soak and release are independently pending.

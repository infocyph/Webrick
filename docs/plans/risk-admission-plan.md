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

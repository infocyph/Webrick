# Risk admission: acceptance and verification

Companion to the [implementation plan](risk-admission-plan.md).
Status: specification only; every test and release gate below is pending unless explicit execution evidence is added.

## Acceptance matrix

Test observable security/HTTP behavior and real provider boundaries. Unit doubles are useful for deterministic decisions and time, but do not establish shared-backend atomicity or distributed failure guarantees.

| ID | Scenario | Required result |
| --- | --- | --- |
| A01 | Middleware omitted from a route | Existing behavior, no policy invocation, no extra dependencies or I/O. |
| A02 | Valid allow decision | Policy once; downstream once; immutable request carries only request-local decision metadata. |
| A03 | Challenge or deny | 429/403 respectively; no downstream handler or password-verification invocation; no-store and no private diagnostics. |
| A04 | Explicit unavailable decision or documented dependency outage | Controlled 503 in enforce mode; never an implicit allow. Known outages and programming failures remain distinguishable. |
| A05 | Unknown action, malformed score/reason metadata, invalid renderer result | Deterministic contract failure through the existing error boundary; no authentication work. Test enforce and shadow. |
| A06 | Shadow mode with each valid outcome | Downstream once and proposed verdict available to the host; no clearance consumption or trust/session mutation by middleware/reference observational policy. |
| A07 | Custom renderer and HEAD | Native response validated, rejection cannot invoke downstream, HEAD body suppressed consistently and metadata/cookies preserved appropriately. |
| A08 | Mode/defaults, named parameters and callable setup | Invalid configuration rejected at composition time; no per-request discovery or mutable global configuration. |
| A09 | Registrar and compiled kernels | Same decisions/status/body/header behavior for a protected handler; middleware requires Request only on routes that use it. |
| A10 | Class/factory middleware and active InterMix inputs | Resolve the host evaluator in the correct request scope; no singleton captures request/tenant/user data. |
| A11 | Compiled artifacts and production consumers | PHP/JSON manifests, trusted/prevalidated and supported source-free loading preserve behavior; no client sockets, keys, closure captures of live providers or replay state serialized. |
| A12 | Parallel requests/Fibers, sequential worker reuse and tenants | Decisions, identifiers and cancellation state never cross requests; no leaked scopes or callbacks. |
| A13 | Unbound SAPI and supplied Runwire context | Normal synchronous path works; supplied identity/cancellation/deadlines propagate through configured providers; no second host scheduler or worker creation. |
| A14 | Spoofed, duplicated, malformed, oversized or absent fingerprint/IP headers | Host trust boundary normalizes once; untrusted header values do not become authoritative. Missing metadata follows explicit policy. |
| A15 | New browser, common/shared JA4 and changing IP/JA4 | No automatic denial/trust from fingerprint alone; no cross-account grant; calibrate shared-signal effects in shadow data. |
| A16 | Payload/schema bounds, account/IP/tenant attempt counters | Oversize/invalid input rejected before expensive verification; key scope, TTLs and cardinality are bounded. Existing request body is not parsed twice. |
| A17 | Parallel valid clearance use across independent processes | Exactly one successful consumption inside the backend's documented consistency domain. Invalid proofs cannot burn someone else's valid clearance. |
| A18 | Repeated/concurrent successful validation of one CAPTCHA token | At most one proof-based admission in the direct flow, or at most one usable clearance in the optional exchange flow. Deduplicate across accepting routes/accounts/tenants sharing the provider proof domain; verify hostname/action and configured bindings. Do not rely solely on provider replay propagation. |
| A19 | Expiry boundary, excessive TTL, clock movement, bad encoding and wrong signing purpose/key | Defined deterministic rejection, one captured wall-time reading per verification and explicit key-rotation behavior. |
| A20 | Attacker-owned trust cookie used against another account/tenant; revoked/stolen/replayed cookie | No cross-account benefit; expiry/revocation and limited replay/possession assumptions are explicit. Browser responses do not expose HttpOnly trust material in JSON. |
| A21 | Redis outage, timeout, eviction/restart, failover, unsupported atomic capability and script failure | No silent in-memory fallback or security bypass. Reject safely; document durability/eviction requirements and the consistency guarantee actually tested. Cluster topology is tested only if claimed. |
| A22 | Turnstile latency, oversized/malformed response, 4xx/5xx, timeout or cancellation | Bounded I/O, controlled unavailable/failed challenge result, no blind retry or admission/minting after failure/cancellation. Verification requests and an optional challenge endpoint are admission-limited. |
| A23 | Authentication-pool saturation and expired-counter regression | Host capacity remains bounded. Fixtures demonstrate why a shared expiring counter and stale decrement do not provide permit ownership. No global-semaphore claim from local Runwire limits. |
| A24 | Logs, metrics, public errors, secrets and cache interaction | Bounded reason codes, no raw credentials/tokens/keys, generic default responses, no shared-cache storage of admission rejections/trust responses; authentication routes exclude inappropriate response-cache middleware. |
| A25 | Stateless REST flow across workers and clients | Initial login returns challenge-required JSON; the frontend retries with a provider token in the login body; verification and atomic replay claim precede the handler. No PHP session/cookie/sticky-worker dependency. Wrong-password/ambiguous-response retries need a fresh proof; proofs cannot bypass hard deny or other admission limits. Shadow does not redeem live tokens. Test body parsing once, host CORS and the optional exchange flow separately. |
| A26 | Host-configured routes and URLs | Identical behavior on different host-selected protected/exchange paths and configured provider endpoints. No automatic login/challenge route, hardcoded Webrick URL, or path-specific enforcement branch. Host renderer uses configured/router-generated client URLs. Invalid provider configuration fails before serving requests; untrusted request values cannot change verification destination or redirect provider secrets. |

Reference policy tests establish the documented example's behavior, not correctness of arbitrary host callbacks. Multi-host or provider-failover guarantees must name the tested backend/topology and durability prerequisites. Do not label an in-process fake as proof of distributed correctness.

## Validation workflow

Run the installed PHPForge command set and preserve its active configuration. Confirm actual commands in the installed version before execution.

1. Capture baseline Git revision/tree, dependency versions, PHP/extensions, workload correctness and required service prerequisites before production code changes.
2. Run `composer ic:doctor`, `composer ic:list-config`, and `composer ic:active-config`. Inspect `composer ic:active-config phpstan.neon.dist --parameter=cognitive_complexity --json`; retain class 80 / function 12.
3. During P1-P3, run focused contract/regression tests after relevant changes. Reproduce a claimed defect before fixing it. Use real isolated shared-backend processes for A17/A18/A21; use deterministic clocks and injected failure providers for boundary cases. Never use production Redis keys or live credentials for tests.
4. Run `composer ic:process`, review the automated diff, and run strict comments plus `composer ic:tests:details`. Resolve root causes with no weakened rules, new baselines, suppressions, skipped tests or omitted mandatory detectors. Finish with `composer ic:release:guard` or the required full final suite.
5. Run executable host-composition examples and a strict Sphinx HTML build. Ensure optional APIs and configuration examples use supported methods, units, defaults, null/disabled meanings and failure policies.
6. Resolve supported PHP 8.4/8.5 lowest/stable dependency lanes and optional-provider present/absent consumers. Test any additional current configured runtime/next-upgrade lane as required by the repository; platform emulation alone is insufficient.
7. Build the actual Composer archive and compare its entries against tracked runtime files. Exclude plans, tests, dependency trees, generated caches and local/analysis output. Install fresh `--no-dev` consumers with optional providers absent and present, then run representative native/compiled smoke cases in separate processes as required by frozen registries.
8. Run relevant component benchmarks, representative HTTP load and persistent-worker qualification below. Record correctness and missing prerequisites separately from performance results.
9. After final changes are committed, require hosted CI and applicable Runtime Release Certification on that exact revision. Local results and historical hosted runs do not certify a later candidate.

Planning-only edits require link/path checks and `git diff --check`; they do not establish any implementation gate or require source-mutating processors. All full code/example checks above apply when implementation starts.

## Performance and worker qualification

Establish the baseline before touching middleware dispatch. Measure complete successful authorized responses, not rejection throughput as a substitute for successful authentication throughput.

| Workload | Comparison and invariant |
| --- | --- |
| Unprotected route, feature unused | Current baseline versus candidate; preserve existing lazy Request/zero-scope behavior, dependency isolation and no policy I/O. |
| Protected allow route with a deterministic policy | Compare semantically equivalent host logic as ordinary native middleware versus the new integration; measure added dispatch/metadata cost. |
| Protected route using real counter/clearance providers | Same backing topology, key/data state and required checks on both sides. Report hit/miss/consume/outage paths separately. |
| Challenge, deny and unavailable responses | Verify rejection and no password-verifier calls. Record response correctness, worker occupancy and bounded resource use separately from successful-login RPM. |
| Representative login mix and slow dependencies | Include the actual hash algorithm/cost, account lookup, valid/invalid credentials, challenge volume, false-positive rate and bounded provider latency. Rate limits must not be disabled to improve results. |
| Persistent-worker reuse and shutdown | Parallel accounts/tenants, cancellation, slow provider, response write pressure, worker replacement and cleanup. No growing queues, leaked scopes/leases or unbounded retained state. |

Use matched repeated trials in a stable environment, representative concurrency levels and warmed production OPcache/autoload settings. Record PHP/extensions, hardware, dependency versions, commands, seed/data, response validation, successful RPS/RPM, failures/timeouts, p50/p95/p99, CPU, live process-tree RSS, queue depth and backend calls/latency. Start memory sampling after readiness and retain peaks throughout the load window.

Preserve the repository certification budgets: maximum 2% median successful-RPM regression, 15% p99 regression and 32 MiB memory regression, with the configured trial count and 30-minute soak. Do not silently relax these limits. Define workload-specific absolute tail-latency/capacity limits before testing; an added risk check must be compared with equivalent enforced semantics. No improvement claim from a single microbenchmark or a short burst. A noisy outlier requires unchanged-candidate reruns and matched samples before tuning code or budgets.

The existing certification's ordinary routes remain required regression coverage. Add the new protected workloads to its established harness when needed rather than writing a competing load/soak framework. Hardware/provider-specific prerequisites remain explicit open gates when unavailable.

## Sources and assumptions to recheck during implementation

- Local source/API inventory and engineering rulings are in the [implementation plan](risk-admission-plan.md).
- [Cloudflare JA4 documentation](https://developers.cloudflare.com/bots/additional-configurations/ja3-ja4-fingerprint/): fingerprints group TLS clients and may be absent. Treat as a signal, not account or device identity.
- [Cloudflare Turnstile validation](https://developers.cloudflare.com/turnstile/get-started/server-side-validation/#token-reuse-and-network-convergence): closely spaced validations can succeed before redemption state converges; local deduplication is necessary for the stronger one-admission/one-clearance guarantee. Recheck provider lifetime and behavior before implementing retry/redemption logic.
- [Redis Cluster specification](https://redis.io/docs/latest/operate/oss_and_stack/reference/cluster-spec/): applicable multi-key operations have slot/topology constraints. Backend durability, atomicity and eviction assumptions must be documented rather than generalized to all cache providers.

## Execution ledger

Use this table for actual results; replace pending only with commands, covered revision/tree and concrete evidence. Preserve failed/unavailable results and limitations.

| Gate | Required evidence | Current status |
| --- | --- | --- |
| P0 planning and links | Read current owners/APIs; both documents link correctly. | Planning links and A01-A26 matrix checked; stateless CAPTCHA flow, configurable URLs and provider semantics reviewed. PHPForge doctor/configuration were inspected during initial planning; implementation gates remain pending. |
| A01-A13 middleware/runtime contracts | Focused tests plus registrar/compiled/native consumer parity. | Pending implementation. |
| A14-A26 host security composition | Executable example tests, adversarial cases and real shared-backend concurrency. | Pending implementation. |
| Full local QA | Process review, detail suite, security/static checks and final guard. | Pending implementation. |
| Docs/archive/consumers | Strict docs, archive inventory and fresh no-dev consumers. | Pending implementation. |
| Matched HTTP/worker qualification | Raw same-environment results and required soak. | Pending implementation. |
| Final-revision hosted checks | Actual candidate SHA and workflow/job conclusions. | Pending commit and implementation. |
| Release and cleanup | Final minor-version classification, durable docs and reference-checked plan removal. | Pending all applicable gates. |

No commit, tag, merge, publication, provider deployment or end-to-end security certification is implied by these planning documents.

## Execution tracker (append-only)

### Batch 01 — Public contract fixtures (2026-11-16)

- Candidate base SHA: `70ea3b1d7526eb7bc608bd3e41480917468fbf62`; review PR [#59](https://github.com/infocyph/Webrick/pull/59).
- Added tests for the allow path and immutable decision attribute (A02); rejection/handler bypass (A03-A04); shadow observational forwarding (A06); custom renderer no-store and downstream isolation (A07 partial); invalid configuration and malformed outcomes (A05/A08 partial).
- Outstanding: A01 omitted-route test, HEAD kernel parity, invalid action constructor accessibility, registrar/compiled/artifact parity A09-A13, host provider security A14-A26. No completion asserted before execution.
- Historical quality baseline, November 15 `main` run: Psalm 2,231 errors, PHPStan no-intl 630 errors. These are **not** fresh branch counts; both gates must pass on the final SHA with unchanged configuration.
- Next validation: focused Pest tests and lint on the candidate, then PHPForge static analysis, full test matrix, and PR feedback resolution.

### Batch 01 QA correction (2026-11-16)

- Observed GitHub Actions run [43830780163](https://github.com/infocyph/Webrick/actions/runs/43830780163) for `6d989412d65c6cf50284348039907acdaeb8483e`: Pest passed, PHPStan no-intl reported 635 errors, Pint and PHPCS failed due to introduced style/test diagnostics. Baseline of 630 PHPStan errors is historical, not a green gate.
- Corrected introduced diagnostics in the next commit; all A01-A26 completion states remain **pending** until current-SHA checks and integration tests pass.
### Batch 01 follow-up: dynamic callback boundary (2026-11-16)

- Previous CI [43831260587](https://github.com/infocyph/Webrick/actions/runs/43831260587) identified two remaining new PHPStan issues in defensive evaluator/renderer checks, with 632 total reported errors (630 inherited).
- Kept both defensive checks and updated the callable annotations to admit runtime-invalid host values, which the tests explicitly reject. A05 remains pending complete matrix execution. No new suppressions or exclusions.

### Batch 01 documentation correction (2026-11-16)

- Constructor PHPDoc normalized without changing implementation, existing tests or acceptance obligations.

### Batch 02 — hosted baseline and decision-contract verification (2026-11-16)

- Covered revision before this change: `b1dc88797aef7bc060fb90bdc0e1921c2415020e`, [GitHub Actions 43831667584](https://github.com/infocyph/Webrick/actions/runs/43831667584). Pest/QA PHP 8.4 and 8.5 stable/lowest: 4/4 passed; benchmark jobs 2/2 passed; PHPStan no-intl 630 errors; Psalm 2,224 errors; PHPForge analysis failed. Do not claim green static gates.
- A02: inspect `RiskDecision::$score` and `$reasonCodes` in tests to confirm public metadata remains test-backed; A05: add associative and wrong-element reason inputs; exception contracts documented to address new Psalm review comments. These tests require execution on the new commit.
- PR #59 review automation observed: five original PHPStan findings resolved, several Psalm `MissingThrowsDocblock` and public-property findings on `RiskDecision` remain awaiting fresh results. No automatic merge.

### Batch 03 — 50-file override-annotation remediation (2026-11-16)

- Source revision: `568e2f903fa359a111f0f4ad39aca64110badab9`; diagnostic evidence [CI 43831667584](https://github.com/infocyph/Webrick/actions/runs/43831667584). Applied exactly 306 diagnostic-correlated `#[\\Override]` annotations across 50 PHP files, including 39 production sources and 11 existing test/fixture files.
- No middleware/runtime semantics or test assertions altered. **Verification pending fresh hosted run**; baseline before this batch was 630 PHPStan and 2,224 Psalm errors. Avoid interpreting counts as current until the next runner completes.
- All risk admission A01-A26 gates and detailed performance/host security qualification retain their previous statuses. Reviewed under PHPForge COMPAT-02 and FLOW-02, without ignores, baselines or config changes.

### Batch 04 — remaining explicit override declarations (2026-11-16)

- Input: historical `MissingOverrideAttribute` report at [CI 43831667584](https://github.com/infocyph/Webrick/actions/runs/43831667584). Added 148 declarations across 13 remaining source-controlled PHP files; 306 were already addressed by Batch 03. Combined target 454 annotations for 63 files.
- Required fresh evidence: PHPForge Pint/PHPCS/Pest, strict Psalm and PHPStan, PHP 8.4/8.5 QA, benchmark regression, and review feedback. Unverified outputs must not be treated as passed gates.
- All A01-A26 admission/security/runtime acceptance remains pending unless separately proven by tests.

### Batch 05 — review feedback resolved in source (2026-11-16)

- PR #59 raised three `MethodSignatureMustOmitReturnType` issues on `__clone` and eleven `return.unusedType` findings for fixture `trailers()`. Changed 14 PHP files in response to actual diagnostics and confirmed source method bodies before adjusting signatures.
- Expected: preserve existing clone behavior and return non-null Headers as before. Required evidence: next SHA strict Psalm/PHPStan and PHP 8.4/8.5 QA before review threads can be considered verified. No risk-admission acceptance gate advanced solely due to this typing work.

### Batch 06 — route selection and HEAD acceptance (2026-11-16)

- New registrar test exercises A01 route omission, A03 challenge rejection/handler bypass, and A07 HEAD body stripping and Content-Length preservation. Core `RouterKernel` normalizes the final response for HEAD; compiled kernel already had analogous behavior.
- Supplemental static fix: `Message::__clone()` return-type declaration removed in the base class. Next hosted run must verify no Psalm regressions and preservation of existing request clone tests.
- **Verification pending** at this candidate SHA: no A01/A03/A07 gate marked complete before its focused test and full QA pass; no P2 artifact/compiled parity claim yet.

### Batch 06 QA correction — request setup (2026-11-16)

- Run [43835466242](https://github.com/infocyph/Webrick/actions/runs/43835466242) returned 1 failure / 656 passes on a PHP 8.4 lane, from the newly introduced registrar fixture's synthetic request returning 400. Replaced its `Request::fake` instances with existing kernel-oriented `mockRequest` fixture for GET/HEAD, which supplies expected server and Host metadata.
- A01/A03/A07 remain pending execution of corrected test; all security/release gates remain open.

### Batch 06 diagnostic pass — reproducible 400 response (2026-11-16)

- Failure remains at feature-test unprotected `/health` request, HTTP 400 versus expected 200, in hosted [43836037296](https://github.com/infocyph/Webrick/actions/runs/43836037296). Subsequent revision adds targeted debug-mode test diagnostics to determine which validation boundary rejects the request. No false completion of A01/A03/A07.

### Batch 06 root-cause verified — response factory default (2026-11-16)

- The failing unprotected test route returned HTTP 400 and plaintext `ok` on [CI 43836877991](https://github.com/infocyph/Webrick/actions/runs/43836877991). Investigation found the `Response::plaintext()` factory deliberately defaults its `$code` argument to `StatusEnum::BAD_REQUEST` (400); test fixture now explicitly requests 200.
- Temporary debug diagnostics removed; response semantics, security boundary and negative assertions unchanged. A01/A03/A07 still require a passing hosted run at this correction's commit SHA.

### Batch 07 — type-contract review closure attempt (2026-11-16)

- Previous current-sha evidence: [CI 43837345213](https://github.com/infocyph/Webrick/actions/runs/43837345213), four PHP 8.4/8.5 QA lanes and both benchmarks passed; PHPStan 619, Psalm 1,765 issues. A01/A03/A07 registrar fixture assertions now pass, though full compiled/host acceptance remains pending.
- New changes: Psalm-specific named alias declarations reflecting existing handler and option semantics in `Registrar` and `Router`; precise PSR cache iterable value PHPDoc on benchmark fixture. Target five outstanding PR feedback threads, **unverified** until a fresh hosted analyzer run.
- A09-A26, upstream optional-provider security, full non-dev consumer tests, load/soak and final candidate checks remain pending. No full-green or release-ready claim.

### Batch 08 — 106 static-type-visible test assertions (2026-11-16)

- Evidence base: `c1376b46f1b8a8d5a6c7a72d2f1663ba95a3b386`, [run 43837944076](https://github.com/infocyph/Webrick/actions/runs/43837944076): static analysis failed at **618 PHPStan / 1,766 Psalm** while PHP 8.4/8.5 QA and benchmark lanes passed.
- Updated 14 tests in a single remediation batch: replaced 106 dynamic response status/header macros with equivalent typed status/header-presence assertions; retained execution ordering for middleware attempts, original expected status values, and chained assertions. Source files and detector rules unchanged.
- Goal: remove related `Pest\\Expectation::toHaveStatus/toHaveHeader` `method.notFound` findings; actual analyzer delta **pending** fresh CI and PR feedback. Existing A01-A26 gates and external shared-backend/soak qualifications unchanged.

### Batch 08 verified / Batch 09 typed route registration and cache fixture (2026-11-16)

- [Run 43840921171](https://github.com/infocyph/Webrick/actions/runs/43840921171), commit `86df760fea9cac455cfd8b5a3d81c6552ec794ab`: 4/4 PHP QA passed, 2/2 benchmarks passed, PHPStan **524** / Psalm **1,750**. These reflect 94 and 16 fewer errors, respectively, than prior candidate `c1376b4`. Full analysis gates still red.
- Batch 09 changes: resolve potential Psalm interpretation of route magic-method handler/options aliases with inlined, behavior-equivalent union shapes (14 documented verbs across two classes); refine the failure-store benchmark's PSR cache iterable key/item contracts without changing runtime logic.
- Await fresh analyzer and PR checks on the next revision. Original A01/A03/A07 registrar tests passed in the earlier CI matrix; compiled, provider, host/CAPTCHA, concurrency and final source-SHA qualification gates remain open.

### Batch 09 verification / Batch 10 matcher test fixture (2026-11-16)

- [Run 43841734657](https://github.com/infocyph/Webrick/actions/runs/43841734657) on `8ded2c1cac4675cb702bcb66b88746f0530aa1a3`: Pest/QA PHP 8.4/8.5 four lanes passed; both benchmarks passed; PHPStan **523**, Psalm **1,567**; static gates fail overall. Full PR review thread inventory currently contains no unresolved threads. Keep PR in draft and unmerged.
- Batch 10 updates one shared test helper plus three test files, asserting `MatcherInterface` at the callable/dataset boundary for 22 invocations. Existing matcher dataset factories, route fixtures, expected outcomes, and workload semantics remain unchanged. Required fresh CI evidence pending.
- A01/A03/A07 previously pass focused registrar tests; compiled kernel/host provider A09-A26, archive and final-SHA checks remain open.

### Batch 11 — official PHPForge ext-intl configuration (2026-11-16)

- Historical baseline: `b4701828f85f18d58b7d931ac0a9cfb7203dba83`, [run 43842417101](https://github.com/infocyph/Webrick/actions/runs/43842417101). Four QA lanes passed, both benchmark lanes passed; Psalm 1,578 and separately configured no-intl PHPStan 424 errors remained.
- Changed only Webrick CI integration: explicit `php_extensions: intl` in reusable PHPForge workflow, while retaining empty external-service selection and unchanged service topologies. Removed bespoke no-intl PHPStan analyzer job because the user's desired analyzer environment is ext-intl-enabled; PHPForge native PHP 8.4/8.5 analysis still runs with unchanged strict rules.
- `detail-diagnostics` continues a `php -n` optional-Intl behavior smoke check. New hosted matrix and diagnostics **pending**; A01-A26 release/security acceptance statuses unchanged.

### Batch 12 — 50-file Psalm exception-contract remediation (2026-11-16)

- Based on [run 43845029582](https://github.com/infocyph/Webrick/actions/runs/43845029582) at `215525625a7bec6473613a270d33a17281d89d40`: PHPForge with ext-intl configured, 4/4 QA and 2/2 benchmarks passed, PHPStan **424**, Psalm **1,578**; strict analysis still failing.
- Batch changes: 50 existing production PHP files, 541 originally reported `MissingThrowsDocblock` locations mapped to 323 named methods, with 346 unique `@throws` type declarations written to method-level PHPDoc. Existing tags preserved; no generated cache, static-analysis suppression, runtime behavior or public method signature changes.
- Requires new CI proof: PHP 8.4 and 8.5 PHPForge analysis; PHPForge Pint/PHPCS/Pest; benchmark comparison and PR reviewer feedback. These are **pending at the new commit**, not evidence of green gates.
- Existing risk acceptance A01-A26, compiled artifact parity, host stateless CAPTCHA/replay safety and load/soak remain open unless separately tested.

### Batch 12 verified / Batch 13 root-cause analyzer contracts (2026-11-16)

- Verified source SHA `d3ef0d4739337810971dc936314265364ea76334` on [CI 43846752612](https://github.com/infocyph/Webrick/actions/runs/43846752612): 4/4 PHPForge QA passed, 2/2 benchmark lanes passed, clean install passed; PHPStan **424**, Psalm **1,376** (202 fewer than Batch 12 input). Detail diagnostics still failed; neither static gate is green.
- Batch 13 changes nine `src/` files: cookie key-range guard; risk-admission thrown-error contract; correctly differentiated PHPStan/Psalm Fiber and generator shapes; previously validated metadata/payload return type assertions; narrower matcher literal/variable/regex data; and equivalent typed compiled-route-map filtering. No acceptance gate is inferred from a static analysis improvement.
- Reviewer feedback: 12 unresolved threads at the input revision. This batch targets the actionable static-contract/type-boundary issues; sharded matcher preload return semantics require separate review, and every review-thread resolution requires fresh code-scanning evidence.
- Evidence still pending: committed Batch 13 SHA, full PHP 8.4/8.5 hosted analysis and QA, benchmarks, clean install. Contract matrix A01-A08 only previously unit/registrar exercised in part; A09-A13 compiled/runtime and A14-A26 host/CAPTCHA/replay security, archive, soak and final-SHA gates remain independently open. Keep PR #59 draft and do not merge, tag or publish.

### Batch 13 verification / Batch 14 regression correction (2026-11-16)

- [GitHub CI 43854846322](https://github.com/infocyph/Webrick/actions/runs/43854846322) at SHA `c1b21c6af6e6dce55972a42f837c1f48554c8221`: QA 4/4 passed, benchmarks 2/2 passed, clean install passed; PHPStan 426 and Psalm 1,372 remained failing. Two additional PHPStan findings came from a redundant lower-bound key guard and increased class complexity; Psalm review feedback surfaced Fiber template-arity conflicts and new mixed assignments. This is not a release or security acceptance result.
- Batch 14 corrects the exact analyzer regressions without dropping intended production checks or weakening gate configuration: independent PHPStan/Psalm type annotations for private Fiber APIs, unchanged runtime parsing expression semantics, and the already-enforced cookie key ID constraint.
- Verification of this correction and any new code-scanning threads is **pending new committed SHA and hosted PHP 8.4/8.5 CI**. A01-A26 scoped acceptance, archive, optional consumers, real shared-store concurrency and soak remain open; retain draft PR #59.

### Batch 14 invalidated / Batch 15 syntax recovery (2026-11-16)

- Hosted [CI 43855967110](https://github.com/infocyph/Webrick/actions/runs/43855967110) at `c43bd4812a485a43c23acadba096421693a698b7` was **QA red** (27 Runwire-related test failures, Pint/PHPCS failure) because Fiber `@psalm-*` annotations were written outside comments. No analyzer-count improvement can be credited to this broken candidate.
- Batch 15 restores valid PHPDoc syntax exclusively; execution evidence **pending** on its exact commit. Follow with both analyzers and the entire 4/4 QA matrix; don't mark reviewer findings resolved prematurely. Compiled route and host CAPTCHA/replay A01-A26 acceptance remains pending; PR #59 remains draft.

### Batch 15 verified / Batch 16 constructor-root-cause remediation (2026-11-16)

- [Hosted run 43856614003](https://github.com/infocyph/Webrick/actions/runs/43856614003) at `d2177f2456f43bbe84f538fe6fbc483f4ed9f188`: all 4 QA lanes and clean installation green, PHPStan 424 and Psalm 1,358 strict errors remaining; static-analysis gates red. Benchmark outcomes to be checked on completion. Original Batch 14 invalid parse-result counts remain excluded from trends.
- Batch 16 addresses three constructor-unpack root sites in `CompiledRoute` (39 Psalm argument diagnostics reported at the input SHA), retaining identical immutable route field semantics in one direct named-argument constructor helper and removing one redundant private Fiber annotation. New source SHA CI **pending**, don't mark `TooFewArguments` gone until confirmed by hosted logs.
- A01-A26 functional/compiled/host policy/cancellation, real shared-backend concurrency, docs, package and soak acceptance remain open. PR #59 draft, no merge/tag/release.

### Batch 16 verification / Batch 17 exception contracts (2026-11-16)

- [Run 43857420663](https://github.com/infocyph/Webrick/actions/runs/43857420663) at `50b2ddfade5edf0dcd88d830567e56421de0662d`: PHP 8.4 analyzer **PHPStan 424 / Psalm 1,317**; zero remaining `TooFewArguments` in `CompiledRoute` and zero private Fiber template errors targeted by the preceding batch. All four QA lanes and clean install passed; verify both benchmark conclusions and full run status before marking the batch fully certified. Strict analyzers remain failing.
- Batch 17 adds 63 exception contracts to 51 methods in eight production request/response/router files, derived from 113 exact Psalm `MissingThrowsDocblock` error sites; unchanged PHP executable behavior. Affected files and methods are traceable via commit diff. Results pending new strict analyzers and PHPForge CI (expected drops must not be counted before observed).
- A01-A26 native, compiled, host-CAPTCHA, anti-replay backend, runtime isolation and packaging/soak acceptance remain independently open. No release, merge or tag; keep draft PR #59 and append the next verified evidence beneath this record.

# Webrick 5 — Deep Correctness Completion Ledger

## Status

**Source implementation: COMPLETE**

This document closes the source-level deep correctness revision defined in `docs/webrick-5-deep-correctness-revision.md` on branch `webrick-5/batch-1-correctness`.

The final current-head reconciliation was performed against the actual branch contents, not only commit history. That reconciliation caught and restored corrections that had been overwritten by later whole-file edits, then rechecked the affected request, response, routing, cache, runtime, interop, middleware, parser, and persistent-worker paths.

All **42 required source items** from the deep correctness plan are now fixed or directly verified in the current source tree. There are **no known source-level correctness blockers remaining from this audit**.

No additional correctness batches are planned. Any issues discovered by the deliberately deferred automated certification phase are QA/regression fixes, not a new Batch G/H/etc.

---

## 42-item reconciliation

- [x] 1. Route-cache destructive path safety
- [x] 2. Non-seekable request-body preservation
- [x] 3. Response-cache scheme isolation and cache-key versioning
- [x] 4. Signed URL ignored-parameter generation/verification symmetry
- [x] 5. Signed URL canonical decimal integer grammar
- [x] 6. Removal of request-derived media-extension static memoization
- [x] 7. Shared structured JSON/XML media-type classification
- [x] 8. Generic added-header duplicate preservation
- [x] 9. `withUri(..., preserveHost: true)` Host port preservation
- [x] 10. Request application-input isolation from cookie/server/env compatibility variables
- [x] 11. Malformed uploaded-file normalization
- [x] 12. Complete stream initialization writes
- [x] 13. Strict request Content-Length and charset parsing
- [x] 14. Shared strict qvalue parsing
- [x] 15. Strict shared HTTP-date parsing
- [x] 16. UAParser Android/Client-Hint/Edge corrections
- [x] 17. Response status invariant
- [x] 18. Case-insensitive response-helper header handling
- [x] 19. Exact cookie Max-Age semantics
- [x] 20. Cookie domain validation
- [x] 21. Public Range helper unification with canonical range parsing
- [x] 22. Externally supplied range validation
- [x] 23. Case-insensitive authoritative range response headers
- [x] 24. CLI emitter HEAD/bodyless/streaming parity
- [x] 25. Runtime file fast paths use captured `FileBody` metadata
- [x] 26. CORS Private Network Access `Vary` semantics
- [x] 27. Bounded/validated telemetry request IDs
- [x] 28. PSR producer-backed response conversion
- [x] 29. PSR source-stream cursor preservation
- [x] 30. PSR uploaded-file preservation or explicit unsupported failure
- [x] 31. Duplicate canonical route detection at registration
- [x] 32. Compressed IPv6 route constraints
- [x] 33. Paired PCRE delimiter support
- [x] 34. Build/runtime route-host grammar alignment
- [x] 35. Cache-Control delta-seconds grammar
- [x] 36. Language helper qvalue/empty-list/stable-order behavior
- [x] 37. RateLimit non-negative/invariant guards
- [x] 38. Vary field-name token validation
- [x] 39. HTTP grammar parser consolidation
- [x] 40. Repository response-header casing reconciliation
- [x] 41. Strict numeric/configuration reconciliation
- [x] 42. Persistent-worker static-state reconciliation

---

## Final reconciliation corrections

The final current-head pass additionally closed several residual/regression edges rather than trusting earlier commit history:

- restored canonical native content parsing in `NativeServerRequest`, including exact form media-type matching and shared structured JSON/XML classification;
- rejected malformed CORS requested methods through the controlled HTTP-error path;
- made cached response integer restoration overflow-safe;
- hardened response-cache bootstrap configuration and made corrupt cached metadata degrade to a cache miss;
- made malformed conditional/request ETag lists fail closed;
- centralized quote-aware HTTP list splitting in `HttpUtils` and routed request Accept/ETag and content negotiation through it;
- made response-linter Content-Length comparison overflow-safe;
- restored zero-progress response-stream failure instead of silent truncation;
- validated telemetry correlation-header names and NEL TTL at bootstrap;
- rechecked RoadRunner/Workerman native file fast paths against `FileBody::sourceSize()` and Swoole/shared streaming semantics;
- rechecked `Uri`, `HeaderBag`, `IpCidr`, view-response header handling, input sanitization, and persistent-worker request-local state.

These are part of the same correctness closure and do not create another implementation batch.

---

## Regression coverage

Deep-correctness regression coverage is retained in the dedicated unit files, including:

- `tests/Unit/DeepCorrectnessBatchATest.php`
- `tests/Unit/DeepCorrectnessBatchCTest.php`
- `tests/Unit/DeepCorrectnessBatchDTest.php`
- `tests/Unit/DeepCorrectnessBatchETest.php`
- `tests/Unit/DeepCorrectnessBatchFTest.php`

`DeepCorrectnessBatchFTest.php` also acts as the final reconciliation guard for current-head regressions, including response protocol/reason-phrase invariants, locale cache isolation, structured JSON classification, malformed quoted HTTP lists, conditional ETags, exception/error boundaries, request limits, CORS, response-cache configuration, telemetry configuration, stalled streams, and Content-Length overflow.

The source and test files are committed, but this completion statement does **not** claim that the full automated suite has been executed after the final reconciliation.

---

## Persistent-worker / performance closure

The correctness implementation preserves the Webrick 5 runtime requirements:

- request-derived values are not accumulated in unbounded static caches;
- trusted-proxy static state is explicit boot configuration and can be frozen;
- routing constraints/hosts/canonical duplicate checks remain registration/build concerns;
- runtime file fast paths use captured file metadata instead of request-path re-stat for known size;
- route-cache destructive operations canonicalize and validate their target before deletion;
- non-seekable request buffering occurs only when required to enforce an unknown body length;
- HTTP grammar helpers are small/stateless and shared where semantics overlap;
- no correctness fix intentionally adds request-path reflection, route compilation, or serialization.

---

## Deferred certification — intentionally not completed here

The following remain a separate QA/certification phase, as planned for the Webrick 5 development workflow:

- Pest full suite
- PHPProbe
- Pint
- PHPCS
- Deptrac
- PHPStan
- Psalm
- Rector dry-run
- Composer Normalize dry-run
- persistent-worker/concurrency validation
- production compiled-path performance benchmarks

No analyzer baseline, ignore, or suppression should be introduced merely to make that phase green. Any real issue discovered there should be fixed at source and treated as a QA regression.

---

## Closure rule

The Webrick 5 deep correctness implementation phase is **closed**.

Proceed to the next planned Webrick 5 development phase. Do not reopen correctness implementation merely for speculative cleanup. Reopen only for a reproducible source defect or a concrete failure discovered during later QA/certification.

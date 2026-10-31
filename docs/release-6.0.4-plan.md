# Webrick 6.0.4 audit and release plan

Audit date: 2026-10-31. Baseline: `bc009f3a2c53ed7f79e6fef6d92f84a5ce2ec08a` (`6.0.3`).
Follow `vendor/infocyph/phpforge/resources/engineering-principles.md` for implementation and acceptance.

## Release scope

A patch release is sufficient: no Webrick public API, artifact format or production dispatch behavior changes are required by the dependency upgrade. Runwire and CacheLayer remain optional host-provided integrations. Runwire owns workers, event loops, admission, cancellation, deadlines and drain; Webrick consumes the supplied context.

1. Raise InterMix to `^11.2.1`, optional development/test Runwire to `^3.0`, and optional development/test CacheLayer to `^4.0.2`. Update installation and runtime documentation; preserve the attribution of historical Runwire 2 benchmark measurements.
2. Fix Composer archive isolation. The initial archive contained 11,237 entries including local dependencies, analysis output and workspace directories. Exclude these and generated runtime artifacts explicitly. Keep every tracked runtime source and the CLI executable in the archive. Exclude the tracked route-cache directory from Git exports.
3. Validate the dependency update with the complete PHPForge checks, security analysis, PHP 8.4/8.5 tests, documentation, optional integration tests, benchmarks and an isolated production consumer.
4. Commit the final candidate and validate that exact revision in the configured hosted dependency/platform matrix and Runtime Release Certification before tagging `6.0.4`.

## Security and source review

Review covered request/header validation, trusted proxy handling, CSRF and signed URLs, authenticated cookies, shared-cache isolation and failure behavior, body/upload limits, error rendering, executable artifact trust, matcher caches, request-local state, DI scopes, cancellation, pressure handling and runtime ownership. No exploitable first-party vulnerability was confirmed in this review. This is bounded audit evidence, not a guarantee that no vulnerability exists.

Composer reports zero known security advisories. `doctrine/annotations` is an abandoned transitive development dependency required by `phpbench/phpbench` 1.7.0, supplied through PHPForge. Any shared QA dependency replacement belongs in PHPForge, rather than a consumer-local pin or removal of a required detector.

The baseline clone detector passes its configured error gate, but reports 38 warning-level clone groups. Some statement groups match unrelated validation/control-flow structures. Review semantically equivalent groups before consolidating them; centralize genuine repeated logic across all affected call sites, preserve independent provider/lifecycle boundaries, and require representative RPM measurements for changes to dispatch or adapters. This is follow-up maintenance, not a reason to weaken the detector or add broad runtime refactoring to this dependency/packaging patch.

## Verification ledger

- Baseline PHP 8.5.4: 645 tests, 2,437 assertions passed.
- Runwire 3.0 / InterMix 11.2.1 / CacheLayer 4.0.2: the same 645 tests and 2,437 assertions passed.
- Initial complete PHPForge detail suite passed, including PHPStan and Psalm security analysis.
- Fixed Composer archive: 213 entries; all tracked runtime source files and the CLI are present; local dependencies, generated output and workspace directories are absent.
- Final `composer ic:process` and `composer ic:release:guard`: passed. Active complexity limits remain class 80 / function 12; no detector scope, thresholds or suppressions changed.
- PHP 8.4.26 container: 645 tests / 2,432 assertions passed; its missing optional `pcntl` extension takes the concurrency test's portable fallback. Host PHP 8.5.4 and prepared PHP 8.5.11 with event/Swoole/pcntl: 645 tests / 2,437 assertions passed. Temporary writable Pest history mounts eliminated read-only test-history warnings.
- Strict offline Sphinx HTML build: passed (`-n -W --keep-going`).
- Isolated archive consumer: `composer install --no-dev --no-plugins --no-scripts --prefer-dist --optimize-autoloader` passed, followed by development routing, release compilation, PHP/JSON manifest loading, source-free production dispatch and HEAD checks. Runwire, CacheLayer and Opis were absent. Each production boot ran in a separate process, respecting the frozen registry lifecycle.
- `composer ic:bench:quick`: all 48 benchmark subjects completed with zero failures/errors (10 revolutions, 3 iterations). PHP 8.5.4 CLI OPcache was disabled; these are supporting microbenchmarks, not sustained-RPM acceptance. Runwire context/fixed response/request promotion/scope/stream mode values were 6.806/9.393/20.714/0.800/13.282 microseconds respectively.
- Live Runwire 3 HTTP/1 smoke: 1,000 correct responses across ten workloads at concurrency eight (static, dynamic, JSON, streaming, file, range, upload, 404, 405 and HEAD). Sampled metrics had no queued bytes or handler exceptions; the native host completed ten worker recycling events. This short smoke does not replace the release soak or load budget.
- Certification harness self-tests passed: in-load RSS peaks and command failures are retained; HTTP batches wait for their own requests and propagate failures.
- Latest hosted base-revision Security & Standards run `40152220154` passed its PHP 8.4/8.5 stable/lowest QA, analysis, benchmarks, clean install and diagnostic jobs. This predates the candidate and does not certify it. The GitHub connector's PR-only run listing was insufficient; the complete Actions listing was checked through `gh api`.
- Rebuilt archive: approximately 320 KiB, down from a 53 MiB local archive. SHA-256: `9e16a3c04a424921e24f2cbc1ac8a763d57577f103b8b45712991d0cc345f095`. Archive entry comparisons found no missing tracked runtime files.

## Open release gates

The working candidate is uncommitted. Historical hosted results cannot validate it. Require final-revision PHP 8.4/8.5 highest/lowest dependency checks, SAPI/Runwire same-run load comparison, live H1/H2 acceptance, and the configured 30-minute persistent-worker soak. Local `wrk` and `nghttp` were unavailable, so the complete workflow was not run locally. H3 and alternative host-driver acceptance require their prepared deployment environments when those capabilities are claimed. Do not describe a local microbenchmark or a shorter smoke run as satisfying sustained-RPM or release-soak certification.

Tagging, pushing and publishing are separate release actions; none is performed by this audit.

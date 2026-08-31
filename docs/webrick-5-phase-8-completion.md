# Webrick 5 — Phase 8 Completion Disposition

Date: 2026-08-31  
Branch: `webrick-5/batch-1-correctness`

This document records the implementation disposition for **Phase 8 — final benchmark/deletion/optimization pass** from [`webrick-5-major-release-plan-details.md`](./webrick-5-major-release-plan-details.md).

It records development decisions only. The consolidated PHPUnit/Pest/static-analysis/PHPForge/Composer/CI/concurrency/soak/benchmark/release-gate certification is intentionally deferred until after all feature phases.

## Completed deletion and consolidation

### Runtime and transport

- Deleted `AutoEmitter` and engine-specific legacy emitter discovery/compatibility paths.
- Persistent Swoole/OpenSwoole, RoadRunner and Workerman execution uses explicit `RuntimeAdapterInterface` implementations selected at worker/process bootstrap.
- Retained only the meaningful synchronous emitter boundary: `BaseEmitter`, `DefaultEmitter`, `CliEmitter`, `EmitterInterface`.
- Removed per-request runtime/environment discovery from the compiled path.
- Removed stale demo/documentation references to old emitter discovery.

### HTTP method/request normalization

- Deleted `NormalizeMethodMiddleware`; HTTP method normalization now occurs at the request/runtime boundary once.
- Removed duplicate native-request normalizers and stale pseudo-PSR request-normalization files.
- Removed stale `Request\Psr7\ServerRequest` references after the native `Request\Core\ServerRequest` migration.
- Reduced duplicate request/header normalization on the hot path.

### Negotiation

- Consolidated media negotiation on one canonical `Request\Http\ContentNegotiator`.
- Deleted `Response\Negotiation\ContentTypeNegotiator`.
- `NegotiationMiddleware`, `Response::auto()`, `Request::prefers()` and `ErrorHandler` now use the same negotiation semantics.
- Removed regex-heavy MIME wildcard matching from the common negotiation path.
- Corrected wildcard Accept handling so a wildcard such as `text/*` selects a concrete server-supported media type rather than leaking a wildcard into `Content-Type`.
- Preserved structured suffix shorthand such as `+json` / vendor JSON negotiation.

### Development/runtime DI ownership

- `RouterKernel` is now explicitly registrar/development-only.
- The host must supply an InterMix `Invoker`; Webrick no longer falls back to `Container::instance('intermix')` or silently selects a container.
- Removed kernel-owned application service-provider imports.
- Removed hidden/duplicate application graph ownership.
- Removed the `requestScopeEnabled` compatibility switch; development requests always execute in an explicit InterMix request scope seeded with the current `Request`.
- Removed compatibility behavior that could bind the current Request as a container singleton.
- Removed development-kernel route-cache boot and alias-only registrar fallback.
- Development route registration now uses scoped `Router` facade state rather than leaving a global current registrar installed.
- `CompiledRouterKernel` remains the strict production path and receives the host-selected `ProductionContainer` explicitly.

### Matcher cache/build plane

- Decoupled `RouteCache::build()` from `RouterKernel` and from all request/DI runtime boot.
- Matcher cache build is now a pure build-plane sequence: registrar → compiled routes → selected matcher → cache publication.
- Removed route-cache URL-service runtime binding callback.
- Removed legacy alias-fallback/runtime options from matcher-cache tooling.
- Reworked the `webrick route:cache` CLI to describe matcher-cache generation accurately.
- Deleted the obsolete root `route_cache_examples.php` compatibility/example script.
- Made `enableCacheWrite()` explicit on `MatcherInterface` because every retained matcher implements that real build lifecycle.
- Kept matcher cache distinct from the complete production `ReleaseCompiler` artifact.

### Error boundary

- Removed deprecated per-request PHP-error capture configuration; `PhpErrorBridge` is the explicit process/worker-level error conversion boundary.
- Removed arbitrary exception-property/method/numeric-code HTTP status inference.
- Only `HttpExceptionInterface` or an explicitly configured exception map may define HTTP status.
- Removed generic `Allow` discovery from arbitrary exception shapes; authoritative HTTP exception headers carry protocol metadata.
- Error representation negotiation now uses the canonical `ContentNegotiator`.
- Request IDs are sanitized and bounded.
- HEAD error responses suppress the body.
- Debug stack/file disclosure remains disabled unless debug mode is explicitly enabled.
- Removed redundant default 404/405 maps because the router exceptions already implement `HttpExceptionInterface`.

### Response/request representation cleanup

- Removed lazy/temp-stream response compatibility paths that no longer provide value for native string bodies.
- `Response::auto()` uses the canonical negotiation engine directly.
- Header/request support paths were simplified after native representation migration.
- Native stream/file/PSR interop remains only where it represents a real interoperability or transport boundary.

### Composer/autoload/dependencies

- Mandatory `infocyph/arraykit` dependency remains removed from Webrick core.
- Mandatory Composer file autoload for global helpers remains removed.
- `src/functions.php` is retained only as an explicit opt-in global `route()` helper.
- CacheLayer remains optional through a Webrick-owned adapter/contract boundary.
- PSR-7/PSR-17 packages remain optional interoperability suggestions.
- Persistent server engines and OpenTelemetry SDK/exporter packages remain optional.

### Documentation/bootstrap cleanup

- Updated root development front controller to the host-owned InterMix model.
- Updated README, installation, quick start, framework integration, router reference, middleware reference/overview, emitter/runtime reference and matcher-cache reference.
- Removed documentation for deleted `NormalizeMethodMiddleware`.
- Removed stale `AutoEmitter`, route-cache-on-development-kernel, provider-import, container-fallback, alias-fallback, request-scope-toggle and per-request PHP-error-capture guidance.

## Retained deliberately

The Phase 8 goal is to remove cost/duplication, not to delete useful abstractions mechanically.

- `GeneratedMatcher`, `FusedMatcher`, and `ShardedMatcher` remain because they represent distinct matcher/cache operating modes. Final release benchmark certification still decides whether every mode satisfies its intended value/performance envelope.
- `MatcherFactoryTrait` remains: all three matchers share the same private-constructor/`make()` factory behavior; deleting it would only duplicate code.
- `MatcherCacheLifecycleTrait` remains: it centralizes real fused/generated cache lifecycle behavior rather than compatibility forwarding.
- `MatcherCachePayloadNormalizer` remains as build/cache structural normalization.
- PSR-7 bridge/adapters remain as an explicit optional interoperability boundary, not a native request implementation.
- `AtomicCounterAdapter` for CacheLayer remains optional; Webrick's throttle API depends on its own atomic-counter contract.
- `DefaultEmitter`/`CliEmitter` remain for classic SAPI/CLI boundaries; persistent engines use runtime adapters instead.
- `PhpErrorBridge` remains because process-level PHP warning/notice conversion is a valid explicit bootstrap concern and is not on the per-request hot path.
- `src/functions.php` remains opt-in and is not part of Composer's mandatory autoload path.
- Build/dev registrar, attribute discovery and reflection paths remain because they are explicitly outside compiled production traffic.

## Phase 8 completion state

- **Implementation complete:** yes.
- **Feature/deletion pass complete:** yes.
- **Known compatibility architecture intentionally retained on the compiled request hot path:** none.
- **QA/test/static analysis/CI executed in this phase:** no, intentionally deferred.
- **Release throughput gates certified:** no, intentionally deferred.
- **Persistent-worker concurrency/soak certification executed:** no, intentionally deferred.

## Deferred consolidated certification

The next stage is the single release stabilization/certification pass defined by the main engineering plan and tracker. It must include the complete test/static-analysis/architecture/security matrix plus same-run external benchmarks and persistent-worker soak/concurrency validation before Webrick 5.0.0 is released.

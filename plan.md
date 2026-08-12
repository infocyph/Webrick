# Webrick — Finalized Major Upgrade & Hardening Draft

## 1. Objective

Finalize Webrick as a lean, high-performance, framework-neutral PHP HTTP routing kernel with:

- maximum hot-path performance;
- predictable production boot;
- safe persistent-worker behavior;
- strong route-cache semantics;
- clean InterMix 9.1 integration;
- proper CacheLayer 3.1 integration where caching is actually needed;
- minimal and intentional ArrayKit 5.1 usage;
- accurate documentation;
- reproducible performance testing;
- no unnecessary abstractions or dependency-driven rewrites.

This release should primarily be a **consolidation, correctness and performance release**, not a feature-expansion release.

The existing architecture is fundamentally sound. Preserve it and improve weak boundaries rather than redesigning the library.

---

# 2. Fixed Project Decisions

These are intentional decisions and should not be revisited during implementation unless a concrete correctness/performance problem is discovered.

## 2.1 Dependency targets

Update to:

```json
{
    "require": {
        "php": ">=8.4",
        "infocyph/arraykit": "^5.1",
        "infocyph/intermix": "^9.1",
        "psr/cache": "^3.0",
        "psr/log": "^3.0.2"
    },
    "require-dev": {
        "infocyph/cachelayer": "^3.1",
        "infocyph/phpforge": "dev-main@dev"
    }
}
```

Keep exactly:

```json
"infocyph/phpforge": "dev-main@dev"
```

Do not replace it with a tagged version.

## 2.2 CacheLayer remains optional

Keep CacheLayer:

```json
"require-dev": {
    "infocyph/cachelayer": "^3.1"
}
```

and keep the production relationship through `suggest`.

Webrick core routing must continue working without CacheLayer installed.

CacheLayer is used for optional functionality such as:

- response caching;
- default/local throttle backend;
- distributed/atomic throttle support;
- optional advanced cache features.

Do not make the HTTP router/kernel depend unconditionally on CacheLayer.

## 2.3 Keep both benchmark systems

Keep both:

```text
benchmark/
benchmarks/
```

They have different purposes.

### `benchmark/`

Standalone executable benchmark/smoke/performance runner.

Characteristics:

- independent of benchmark framework orchestration;
- directly executable;
- exercises real request/matcher paths;
- supports manual iteration/warmup/round controls;
- useful for immediate before/after performance comparison;
- useful even independently from PHPForge/PhpBench.

### `benchmarks/`

Structured benchmark suite.

Characteristics:

- benchmark-framework driven;
- reusable benchmark fixtures;
- cache lifecycle benchmarks;
- matcher benchmarks;
- kernel dispatch benchmarks;
- signed URL benchmarks;
- ETag benchmarks;
- suitable for historical/repeatable benchmark suites.

Do not merge these directories.

Add a short documentation section explaining the distinction.

---

# 3. Composer Scripts — Final Decision

Current:

```json
"scripts": {
    "benchmark": "@php benchmark/hello_matchers.php",
    "webrick": "@php webrick"
}
```

These scripts are valid and should remain.

They are **not PHPForge responsibilities**.

`benchmark` executes Webrick's standalone request/matcher benchmark rather than PHPForge's quality or benchmark orchestration.

`webrick` invokes Webrick's own CLI.

Therefore do **not** add generic wrappers such as:

```text
test
analyse
lint
quality
```

merely to proxy functionality already owned by PHPForge.

That duplicates responsibility and makes Composer configuration unnecessarily noisy.

### Preferred final Composer scripts

Keep:

```json
"scripts": {
    "benchmark": "@php benchmark/hello_matchers.php",
    "webrick": "@php webrick"
}
```

Optionally make paths explicitly relative:

```json
"scripts": {
    "benchmark": "@php ./benchmark/hello_matchers.php",
    "webrick": "@php ./webrick"
}
```

Either form is acceptable. There is no meaningful runtime advantage to changing the existing form.

### Structured benchmark execution

Do not add another Composer command merely for symmetry.

If PHPForge exposes the supported structured benchmark command, use that externally for `benchmarks/`.

Otherwise execute the benchmark framework directly according to its canonical tooling.

The project-specific Composer surface should remain small.

---

# 4. ArrayKit 5.1 Integration

## 4.1 Upgrade

Change:

```json
"infocyph/arraykit": "^4.6.1"
```

to:

```json
"infocyph/arraykit": "^5.1"
```

Run the entire test suite against 5.1.

## 4.2 Keep ArrayKit usage deliberately limited

Do **not** attempt to justify the dependency by replacing native PHP arrays throughout Webrick.

Webrick's routing, matching, headers and cache internals are latency-sensitive.

Native PHP operations such as:

```php
isset($array[$key]);
$array[$key] = $value;

foreach ($array as $key => $value) {
}
```

should remain native where they are already clear and efficient.

Do not introduce:

- `Collection`;
- `LazyCollection`;
- `ArraySingle`;
- `ArrayMulti`;
- pipelines;
- shape helpers;

inside matcher or dispatch hot paths merely for consistency with the ecosystem.

## 4.3 Preserve useful current Collection behavior

Request convenience collections are a legitimate use of ArrayKit.

Continue lazy creation of:

- query collection;
- post collection;
- cookie collection;
- server collection;
- JSON collection.

Do not eagerly allocate these collections for every request.

A request that never asks for a collection should pay no collection-construction cost.

## 4.4 Evaluate new ArrayKit APIs selectively

ArrayKit 5.1 may be useful for non-hot-path generic manipulation, including:

- configuration normalization;
- external/user-facing array utilities;
- complex non-routing transformation code.

Only replace existing Webrick code when the result is demonstrably:

1. simpler;
2. equally or more correct;
3. no slower in a relevant benchmark.

## 4.5 Do not use ArrayShape for trusted route-cache payloads

Route-cache payloads are generated by trusted Webrick build tooling and already have domain-specific validation.

Do not replace that validation with generic `ArrayShape` calls.

Domain-specific validation is more appropriate and avoids unnecessary general-purpose abstraction in cache loading.

## 4.6 Do not use ArrayKit configuration as a new Webrick configuration subsystem

Webrick does not need to absorb ArrayKit's configuration engine simply because it exists.

Keep configuration ownership with the embedding application.

---

# 5. InterMix 9.1 Integration — Highest-Priority Dependency Work

Upgrade:

```json
"infocyph/intermix": "^9.1"
```

InterMix is a genuine architectural dependency of Webrick.

Current integration covers:

- dependency injection;
- handler invocation;
- controller resolution;
- middleware resolution;
- request scopes;
- lifetimes;
- service providers;
- tagged services;
- direct factories;
- reflection helpers;
- macro behavior;
- serialization;
- compiled resolvers.

Treat the 9.1 migration as an architectural integration review, not a Composer-only upgrade.

---

# 6. Preserve Request-Scoped DI

Current request-scope architecture should remain.

The intended lifecycle is:

```text
RouterKernel::handle()
        ↓
enter InterMix request scope
        ↓
seed current Request
        ↓
execute middleware + handler
        ↓
error boundary
        ↓
leave scope
```

This is important for:

- PHP-FPM consistency;
- RoadRunner;
- Swoole;
- Workerman;
- any long-lived worker.

Keep:

```text
requestScopeEnabled = true
```

as the safe default.

`requestScopeEnabled: false` should remain an explicit integration option only when the host owns an equivalent scope lifecycle.

Never leak request-bound state between calls.

---

# 7. InterMix Tagged Middleware Integration

Current Webrick code reaches into InterMix definition/repository details in some places.

Review all usage resembling:

```php
$container->getRepository();
$repository->getFunctionReference();
$repository->getIdsByTag(...);
```

InterMix's public API includes tag-based resolution such as `findByTag()` and compiled resolver support.

## Target

Use the most stable public InterMix 9.1 API possible.

However, **do not sacrifice Webrick's lazy behavior merely to avoid repository access**.

Required middleware semantics:

- discover tagged middleware;
- do not eagerly instantiate all middleware during kernel boot;
- singleton lifetime remains singleton;
- scoped lifetime remains scoped;
- transient remains transient;
- direct factories remain lazy;
- request-dependent middleware resolves inside active request scope.

If `findByTag()` materializes services too early, do not blindly replace the existing implementation with it.

Prefer a public **definition/descriptor/tag-ID API** if 9.1 exposes one that preserves laziness.

If no suitable public API exists, isolate the unavoidable InterMix-specific access behind one small internal integration component instead of spreading repository knowledge through `RouterKernel`.

---

# 8. InterMix Compiled Resolvers

InterMix supports build-time compiled resolver maps and runtime activation.

Webrick already has tests around compiled resolution.

Expand this into a first-class production optimization.

Recommended production build:

```text
composer optimized autoload
        +
InterMix compiled DI resolver
        +
Webrick prebuilt route cache
        +
OPcache
```

The intended production path should minimize:

- reflection;
- dynamic scanning;
- definition analysis;
- route compilation;
- runtime serialization work.

## Requirements

Test:

- normal InterMix resolver;
- compiled resolver;
- request scopes;
- controller resolution;
- middleware factories;
- service providers;
- tagged middleware;
- scoped dependencies.

Benchmark compiled versus regular resolver performance before documenting the expected benefit.

Do not claim a fixed percentage improvement.

---

# 9. InterMix ValueSerializer Usage

Current Webrick route-cache direction is correct.

Preferred fast path:

```text
route
 ↓
normalize handler
 ↓
scalar/native cache payload
 ↓
var_export
 ↓
executable PHP artifact
```

Fallback:

```text
stateful/complex value
 ↓
ValueSerializer
```

Keep this model.

## ValueSerializer should be used only when necessary

Examples:

- captured closures;
- bound closures;
- stateful callable instances;
- otherwise non-native serializable route components.

Do not serialize ordinary:

- class strings;
- static class/method pairs;
- plain strings;
- scalar metadata;
- normal arrays;

through `ValueSerializer`.

No signed serialization is necessary for internal trusted route-cache artifacts unless a specific threat model requires it.

Route-cache artifacts are deployment-generated trusted executable PHP and should already be protected by deployment/filesystem policy.

---

# 10. CacheLayer 3.1 Integration

Upgrade development dependency:

```json
"infocyph/cachelayer": "^3.1"
```

Update every test and document referencing older CacheLayer APIs.

At least one current documentation page still says:

```text
Install CacheLayer 2
```

That must become CacheLayer 3.1-compatible wording.

Do not hardcode unnecessary major-version wording in normal usage documentation if:

```bash
composer require infocyph/cachelayer
```

already resolves the supported release.

Use explicit minimum/supported version in requirements/compatibility documentation instead.

---

# 11. CacheLayer Capability Utilization

CacheLayer now provides a richer cache surface including:

- tiered cache composition;
- tagged invalidation;
- stampede-safe `remember()`;
- metrics;
- compression/security controls.

Do **not** automatically wire every feature into Webrick.

The rule is:

> use CacheLayer features only when they improve an HTTP-kernel concern without increasing the default request-path cost.

---

# 12. ThrottleMiddleware — Atomic Correctness

This is one of the most important correctness areas.

Webrick already distinguishes an atomic counter path from generic PSR cache fallback, and its documentation explicitly recognizes the PSR read-modify-write fallback as non-atomic across workers.

Preserve this distinction.

## Preferred hierarchy

```text
explicit AtomicCounterStoreInterface
            ↓
CacheLayer atomic counter implementation
            ↓
generic PSR-6 fallback
```

Do not emulate "atomic" behavior by:

```text
get
+1
save
```

against an ordinary PSR cache.

## Generic PSR fallback

Keep generic PSR-6 support because it is useful for:

- development;
- tests;
- local/single-worker workloads;
- custom user-provided pools.

Document clearly that distributed correctness is not guaranteed through non-atomic read-modify-write storage.

## CacheLayer 3.1 adapter

Update the CacheLayer-specific atomic integration to its 3.1 API.

Do not keep old CacheLayer 2 API examples merely for compatibility.

---

# 13. Throttle Concurrency Tests

Sequential unit tests are insufficient for a rate limiter.

Add true concurrent/multi-process tests where the environment supports it.

Example scenario:

```text
limit:      100
workers:    8
attempts:   200+
```

Verify:

- exactly the expected number of requests are allowed;
- over-limit requests are rejected;
- counter does not lose concurrent increments;
- TTL/window reset behaves correctly;
- rejected attempts follow the intended fixed-window policy;
- different scopes do not collide;
- different client identifiers do not collide.

Use `pcntl_fork()` where practical or subprocess workers where portability is needed.

Skip gracefully when the required extension/backend is unavailable.

---

# 14. ResponseCacheMiddleware

The overall implementation is good and should remain.

Preserve:

- safe method checks;
- GET/HEAD behavior;
- query-aware keys;
- host separation;
- negotiated representation dimensions;
- Vary integration;
- personalization protection;
- `Cache-Control` handling;
- `Set-Cookie` exclusion;
- maximum body size;
- stream avoidance;
- cacheable status filtering;
- fail-open cache backend behavior.

The current backend read/write failure handling intentionally preserves a valid downstream response rather than allowing cache failure to break the request. Keep that behavior.

---

# 15. Response-Cache Key Namespace

Add an explicit Webrick namespace/schema prefix.

Example concept:

```text
webrick:http-response:v1:
```

Then hash the variant identity.

Benefits:

- prevents collisions with unrelated cache consumers;
- makes schema upgrades explicit;
- simplifies operational debugging;
- allows safe key-format changes.

Likewise use a meaningful throttle namespace rather than overly generic key prefixes.

Example:

```text
webrick:throttle:v1:
```

Do not make prefixes unnecessarily long if the underlying backend has material key-size overhead; a compact internal equivalent is acceptable as long as ownership/versioning remains clear.

---

# 16. HEAD and GET Response Cache Reuse

Review current cache identity because HTTP method is part of the cache key.

That produces separate representations for:

```text
GET /resource
HEAD /resource
```

where the HEAD response commonly represents the GET metadata without its body.

Consider normalizing HEAD cache identity to GET:

```php
$cacheMethod = $method === 'HEAD'
    ? 'GET'
    : $method;
```

Then:

```text
HEAD
 ↓
GET representation lookup
 ↓
remove body
 ↓
return headers/status
```

Only implement this after verifying Webrick's route semantics:

- explicit HEAD handlers;
- implicit GET→HEAD matching;
- route-specific differences.

If explicit HEAD routes can legitimately return metadata different from GET, preserve separate identity for those cases.

Correctness takes priority over additional hit ratio.

---

# 17. Response Cache Stampede Protection

CacheLayer supports stampede-safe `remember()`.

Do not replace Webrick's normal fast cache path unconditionally with a heavier abstraction.

Default hot-hit path should stay minimal.

Consider an **optional** stampede-protected population mode for expensive responses.

Conceptually:

```text
hit
 ↓
return immediately

miss
 ↓
optional lock/population guard
 ↓
handler
 ↓
store response
```

This feature must:

- remain opt-in or capability-driven;
- not slow ordinary hits materially;
- fail open if the cache/lock backend fails;
- never lock streamed/uncacheable responses unnecessarily.

Benchmark before enabling by default.

---

# 18. Tiered Response Cache Support

Do not add Webrick-specific tiering logic.

If a user wants:

```text
APCu L1
  ↓
Redis/Valkey L2
```

they should provide a CacheLayer tiered store.

Webrick should consume the cache abstraction and remain unaware of the number of storage tiers.

This keeps responsibilities clean.

---

# 19. Tagged Response Cache

Do not add automatic per-route tagging unless Webrick gains a real invalidation API/use case.

Generic HTTP response caching primarily needs:

- correct request identity;
- TTL;
- safe storage.

Avoid adding tag calculations to every request merely because CacheLayer supports tags.

Expose integration points only when there is an actual invalidation requirement.

---

# 20. Route Matcher Architecture

Keep all three:

```text
FusedMatcher
GeneratedMatcher
ShardedMatcher
```

Do not collapse them into one implementation.

They solve different deployment/performance profiles.

## FusedMatcher

Useful for:

- smaller applications;
- compact single-file cache;
- simple deployment.

## GeneratedMatcher

Keep as code-generated optimization strategy.

Its value must remain benchmark-proven.

## ShardedMatcher

Useful for:

- larger route collections;
- selective shard loading;
- OPcache locality;
- persistent applications.

---

# 21. Matcher Recommendation Documentation

Remove absolute rules such as:

```text
100+ routes = Sharded
<100 = Fused
```

or clearly label them as rough starting guidance.

Actual selection depends on:

- number of routes;
- static/dynamic ratio;
- prefix distribution;
- domain routes;
- route constraints;
- cache warmth;
- filesystem;
- OPcache;
- worker lifetime;
- PHP build;
- traffic distribution.

Recommended documentation:

> Start with Sharded for substantial applications, then benchmark Fused, Generated and Sharded against the application's representative route set and runtime.

---

# 22. Sharded Cache Publication

Preserve the existing safe publication architecture.

Keep:

- staging;
- complete generation directories;
- validation before publication;
- immutable generations;
- manifest/current-generation handling;
- atomic switch where supported;
- previous generations surviving long enough for existing workers.

Do not simplify to:

```text
delete active cache
write replacement
```

That would reduce deployment safety.

---

# 23. Cache Artifact Compatibility Metadata

Review cache-format compatibility checks.

Consider encoding only compatibility dimensions that actually affect executable cache safety:

```text
Webrick route-cache format version
Webrick relevant cache schema version
PHP major/minor when necessary
InterMix serialization format/schema when serializer payloads are present
```

Avoid embedding the complete Composer lock hash.

That would cause needless cache invalidation for unrelated dependency changes.

Goal:

> never silently load an executable route artifact whose serialized or compiled representation is incompatible with the currently installed runtime.

---

# 24. Route Cache Fast Path

Preserve the current optimization where ordinary cacheable handlers become native scalar descriptors instead of serialized objects.

Preferred cache artifacts should primarily contain:

- strings;
- integers;
- booleans;
- plain arrays;
- normalized handler descriptors.

Do not introduce unnecessary object reconstruction into route-cache loading.

---

# 25. Attribute Route Discovery

Attribute scanning belongs in:

```text
development/cold registration
or
build-time cache generation
```

not normal production request handling.

Preserve the ability to scan live when no cache is used.

Production documentation should strongly recommend prebuilding route artifacts.

Avoid unsupported fixed performance claims such as:

```text
1000 classes = exactly ~100ms
```

unless accompanied by reproducible benchmark metadata.

---

# 26. RouterKernel Responsibility Review

`RouterKernel` currently coordinates many concerns:

- route matching;
- registration;
- cache loading;
- DI;
- middleware;
- service providers;
- URL services;
- request scope;
- errors;
- dispatch;
- aliases.

Do not split it merely because it is large.

Extra classes themselves have cognitive and runtime costs.

However, if the 9.1 migration causes the class to exceed reasonable maintainability complexity, extract only coherent bootstrap-specific responsibilities.

Candidate:

```text
RouterKernel
    ├── boot/bootstrap state preparation
    └── request execution
```

Possible internal helper:

```text
AliasCacheLoader
```

or a narrowly scoped InterMix integration helper.

Only extract if it lowers meaningful complexity.

Do not create an interface for a private helper unless substitution is required.

---

# 27. Middleware-Free Fast Dispatch

Preserve the direct dispatch lane for routes with no applicable middleware.

Do not instantiate or compose an empty middleware pipeline.

Continue optimizing for:

```text
match
 ↓
invoke handler
 ↓
response
```

where no middleware exists.

Include this path in regression benchmarks.

---

# 28. Static Mutable State Audit

Audit all static registries/caches.

Examples include:

- router facade instance;
- middleware alias registry;
- middleware resolver registry;
- URL service registry;
- constraint registries;
- internal memoization.

Classify each one explicitly as:

```text
immutable process cache
application-global mutable state
request-local state
test-only/resettable state
```

Immutable memoization is generally fine.

Mutable global application registries require well-defined lifecycle rules.

For persistent workers ensure:

- request data is never retained;
- application bootstrap does not accumulate duplicate resolvers;
- repeated tests can reset state;
- multiple kernel boot sequences behave predictably.

---

# 29. Router Facade Global State

Keep the `Router` facade.

It is ergonomic and appropriate for normal application route files.

However, document the boundary clearly:

```text
Registrar = explicit infrastructure API
Router facade = convenient application-global API
```

Avoid making every low-level integration depend on a process-global facade.

Where multiple isolated Webrick kernels are hosted in one process, direct registrar usage should remain available.

---

# 30. Request Construction Semantics

Review whether manually constructing request objects can implicitly read:

```php
$_FILES
$_COOKIE
$_POST
$_SERVER
```

Prefer a clean invariant:

```text
Request constructor = deterministic from supplied arguments
Request::fromGlobals() = reads PHP globals
Request::fake() = explicit testing construction
```

This makes manually constructed requests easier to reason about and safer in persistent/testing environments.

Do not accidentally break `fromGlobals()` behavior while doing this.

Add tests proving both paths.

---

# 31. Lazy Request Helpers

Preserve lazy request convenience objects.

Do not eagerly:

- parse JSON;
- build ArrayKit collections;
- normalize uploaded files deeply;
- parse URL-encoded bodies not applicable to the request;
- materialize optional request representations.

A request should only pay for facilities it actually uses.

---

# 32. Request Body Behavior

Preserve valid HTTP semantics.

Do not assume GET/HEAD can never contain a body.

Continue opening/exposing the request body stream consistently while gating expensive parsing to applicable content types/methods.

Avoid automatic complete buffering of large bodies.

---

# 33. PSR Positioning

Webrick exposes PSR-7-style immutable message APIs but does not implement PSR-7 message interfaces.

Documentation already acknowledges this in places.

Make this positioning consistent everywhere.

Preferred language:

> PSR-7-style immutable HTTP messages with explicit framework adapters.

Do not imply direct interface compatibility.

---

# 34. Composer PSR Keywords

Current keywords include:

```text
psr-7
psr-15
```

Review these.

If Webrick does not implement those standards directly, remove keywords that imply standards compliance.

Suggested direction:

```json
[
    "router",
    "routing",
    "http",
    "http-kernel",
    "middleware",
    "route-cache",
    "signed-url",
    "php-router",
    "opentelemetry"
]
```

Only add:

```text
roadrunner
swoole
workerman
```

if Webrick intentionally documents/supports those environments sufficiently to justify discoverability under those keywords.

Also reconsider:

```text
framework
```

because Webrick is better positioned as a framework-neutral routing/HTTP kernel rather than a full application framework.

---

# 35. PSR Examples Audit

Search documentation for:

```text
Psr\Http\Message\
Psr\Http\Server\
PSR-7
PSR-15
PSR-17
```

For every usage, ensure one of these is true:

1. it is explicitly demonstrating an external adapter;
2. Webrick actually implements that interface;
3. it clearly says the type is PSR-style rather than PSR-compatible.

Remove misleading examples.

---

# 36. Documentation API Correctness Sweep

This needs to be a dedicated task.

There are stale/speculative examples in the current docs.

For example, tests explicitly note that Request does not have a `query()` helper, while documentation contains calls such as:

```php
$r->query('fields');
```

That documentation must use the actual Request API. 
Another example contains:

```php
Route::get('/profile', function () {
    return Response::auto($r, $payload);
});
```

where `$r` is undefined.

Also remove speculative examples such as:

```php
debug: true // If supported
```

from canonical API documentation.

Documentation should document APIs that exist, not APIs that might exist.

## Implement a full docs-code audit

Validate:

- method names;
- constructor arguments;
- named arguments;
- namespaces;
- interface types;
- route registration syntax;
- middleware configuration;
- cache APIs;
- CLI arguments.

Where possible, convert important documentation examples into executable tests/fixtures.

---

# 37. Documentation Test Framework Alignment

The actual Webrick tests use Pest-style:

```php
it(...);
test(...);
expect(...);
describe(...);
```

while substantial documentation still demonstrates PHPUnit classes and `vendor/bin/phpunit`. 
Decide whether a page is:

### Application testing guidance

Then PHPUnit examples may be acceptable if clearly framework-neutral.

### Webrick contributor/project testing guidance

Then use the actual PHPForge/Pest workflow.

Do not mix them so readers believe PHPUnit commands are necessarily Webrick's canonical internal workflow.

---

# 38. Error Boundary

Preserve the centralized error architecture.

Preferred flow:

```text
middleware/router
      ↓
throw typed HTTP/framework exception
      ↓
RouterKernel error boundary
      ↓
ErrorHandler
      ↓
Response
```

Framework-owned failures should use a coherent path for:

- 404;
- 405;
- 406;
- 413;
- 429;
- signed URL rejection;
- maintenance;
- request-limit violations;
- invalid host/proxy behavior.

Avoid each middleware inventing unrelated response formats unless intentionally configurable.

---

# 39. Broad Throwable Catch Audit

Search every:

```php
catch (\Throwable)
```

Classify it.

## Legitimate fail-open boundary

Example:

```text
optional cache backend failure
```

The response cache should continue operating uncached when persistence fails.

## Bad broad swallowing

Patterns such as:

```php
try {
    $routes->add($route);
} catch (\Throwable) {
    // assume duplicate
}
```

should catch the actual expected exception.

Unexpected programming errors must not disappear.

Rule:

```text
expected optional infrastructure failure
    → fail open/log appropriately

expected specific domain conflict
    → catch exact exception

programming/configuration failure
    → propagate
```

---

# 40. Signed URLs

Current signed URL architecture is strong.

Preserve:

- permanent signed URLs;
- temporary URLs;
- explicit expiration timestamps;
- relative payload mode;
- absolute payload mode;
- ignored query parameters;
- custom signature/expiry parameter names;
- algorithm configuration;
- verification-key rotation;
- leeway.

Keep canonicalization centralized.

Do not duplicate signature-building rules across generator and middleware.

---

# 41. Production Signing Keys

Remove insecure-looking defaults from production-oriented examples.

Avoid:

```php
$_ENV['WEBRICK_SIGN_KEY'] ?? 'change-me'
```

in production examples.

Prefer:

```php
$signKey = $_ENV['WEBRICK_SIGN_KEY']
    ?? throw new RuntimeException('WEBRICK_SIGN_KEY is required');
```

Development quick-start examples may use an explicitly labeled development key.

Make the distinction obvious.

---

# 42. Cookie Encryption Security Tests

Ensure cookie encryption test coverage includes:

- current active key;
- previous-key decryption;
- key rotation;
- unknown KID;
- malformed payload;
- modified ciphertext;
- modified authentication tag;
- modified nonce;
- truncated data;
- invalid key length;
- empty payload;
- oversized payload;
- attribute injection;
- expiry boundaries.

Preserve authenticated encryption semantics.

---

# 43. Trusted Proxy Model

Create one clear source of truth for whether forwarded headers are trusted.

Relevant headers:

```text
Forwarded
X-Forwarded-For
X-Forwarded-Proto
X-Forwarded-Host
```

Do not allow multiple middleware components to make inconsistent trust decisions.

Prefer normalized request context after trusted-proxy evaluation:

```text
client IP
effective scheme
effective host
effective port
```

Other security middleware should consume the normalized result.

Never trust proxy headers simply because they are present.

---

# 44. Host and Port Semantics

Audit the distinction between:

```text
example.com
example.com:8080
```

Domain route matching often needs hostname only.

Security, absolute URL generation and proxy normalization may need effective port information.

Make this intentional rather than an accidental consequence of URI parsing.

Test:

- HTTP default port;
- HTTPS default port;
- custom port;
- proxy-provided host;
- IPv6 host;
- malformed Host;
- duplicate/conflicting forwarded host values.

---

# 45. Input Sanitizer Positioning

Do not present `InputSanitizerMiddleware` as a general injection-prevention solution.

Input sanitization cannot replace:

- prepared SQL statements;
- contextual HTML escaping;
- shell argument escaping;
- path validation;
- authorization;
- output encoding.

Update security documentation accordingly.

Sanitization should be described as normalization/cleanup according to application policy.

---

# 46. Compression

Keep compression middleware optional and maintain the rule:

> compress either at Webrick/application level or at the edge, not both.

Do not publish universal codec claims such as:

```text
zstd is always 2–3x faster
```

without benchmark context.

Compression performance depends on:

- payload;
- compression level;
- extension implementation;
- CPU;
- response size;
- traffic profile.

Document characteristics rather than universal numbers.

---

# 47. ETag and Compression Coordination

Preserve ETag/compression coordination so validators refer to the actual wire representation according to the selected ETag strategy.

Test combinations:

- uncompressed response;
- gzip;
- Brotli;
- zstd;
- strong ETag;
- weak ETag;
- conditional GET;
- `If-None-Match`;
- Range requests;
- HEAD.

The ETag benchmark suite should remain.

---

# 48. Response Streaming

Preserve streaming as a first-class response path.

Streaming responses must avoid:

- response-cache body buffering;
- accidental full-body ETag hashing where inappropriate;
- compression modes that require unbounded buffering;
- double emission.

Document proxy buffering considerations without pretending Webrick controls the upstream server.

---

# 49. Emitter Ownership

Keep a clear response ownership rule:

```text
Standalone Webrick
    → Webrick emitter owns emission

Embedded Webrick
    → host adapter owns emission

Persistent/native integration
    → appropriate native emitter/bridge owns emission
```

Never emit a response in Webrick and then return the same response to another framework that emits it again.

---

# 50. OpenTelemetry

Keep OpenTelemetry optional.

Do not require:

```text
open-telemetry/sdk
open-telemetry/exporter-otlp
```

in the core dependency set.

Telemetry middleware should:

- detect available integration;
- work when installed;
- impose minimal/no dependency cost when unused.

Keep `suggest` entries if accurate.

---

# 51. Middleware Packaging

Keep Webrick's middleware in the main package.

Do not split middleware into multiple Composer packages merely to reduce theoretical installation size.

Composer autoloading already means unused classes are not loaded automatically.

Splitting would increase:

- versioning burden;
- dependency coordination;
- documentation complexity;
- user installation complexity.

---

# 52. Security Documentation Scope

Current security documentation contains generic application-security content that Webrick itself does not own.

Reduce or clearly separate:

```text
Webrick responsibility
application responsibility
infrastructure responsibility
```

For example:

### Webrick owns/supports

- signed URLs;
- request limits;
- middleware;
- CORS/policies;
- cookie handling;
- headers;
- trusted proxy behavior;
- HTTP error handling.

### Application owns

- authorization policy;
- database query safety;
- password lifecycle;
- business access control.

### Infrastructure owns

- TLS configuration;
- firewall;
- operating-system hardening;
- network policy.

---

# 53. Persistent PDO Recommendation

Remove the current universal recommendation:

```php
PDO::ATTR_PERSISTENT => true
```

from Webrick performance guidance.

Persistent database connections are workload/runtime/deployment dependent.

Also remove fixed claims such as:

```text
saves ~10–50ms/request
```

unless backed by a reproducible benchmark specific to that environment.

Database connection strategy is outside Webrick's routing responsibility.

---

# 54. Performance Documentation Claims

Audit all fixed numbers.

Current documentation contains claims such as:

```text
zstd 2–3x faster
response cache 10–100x
1000 attribute classes ~100ms
specific 25,000+ RPS figures
```

These should either:

1. be backed by a reproducible Webrick benchmark with exact environment;
2. be clearly labeled as example results;
3. be removed.

A performance-focused library gains credibility by being conservative with benchmark claims.

---

# 55. Reference Benchmark Results

If benchmark numbers remain in documentation, record:

- Webrick commit/version;
- PHP version/build;
- OPcache settings;
- JIT status;
- CPU model;
- core count;
- RAM;
- operating system;
- web server;
- FPM/worker configuration;
- concurrency;
- route count;
- matcher;
- middleware stack;
- benchmark command;
- warm/cold state.

Otherwise remove fixed reference numbers.

---

# 56. Generic Security/Compliance Material

Reduce generic sections for:

- GDPR;
- PCI DSS;
- HIPAA;
- generic pentest services;
- generic database security;
- generic authentication implementations.

Webrick should not appear to provide compliance simply because its documentation contains a checklist.

Keep concise links/context only where useful.

---

# 57. Security Contact Placeholders

Remove:

```text
security@example.com
example.com/.well-known/security.txt
hackerone.com/example
```

from Webrick project security documentation unless they are explicitly marked as application placeholders.

Prefer real Infocyph disclosure information or point to the repository's actual `SECURITY.md`.

---

# 58. Serverless Documentation

Keep Webrick-specific serverless guidance:

- prebuild route cache;
- immutable deployment;
- external state;
- avoid runtime route scanning;
- account for platform buffering;
- preserve signed URL query strings.

Avoid pinning volatile provider runtime versions unless actively maintained.

Link users to provider documentation for changing platform limits/runtime identifiers.

---

# 59. Documentation Scope Reduction

Webrick documentation is currently broader than the library's responsibilities.

Prioritize:

- installation;
- request API;
- response API;
- router;
- registrar;
- route groups;
- constraints;
- domains;
- attributes;
- middleware;
- signed URLs;
- cache;
- matchers;
- kernel;
- InterMix integration;
- CacheLayer integration;
- persistent workers;
- emitters;
- framework embedding;
- production deployment concerns specific to Webrick;
- troubleshooting.

Reduce generic material such as:

- application repository examples;
- generic CRUD architecture;
- generic Selenium setup;
- generic DB tutorials;
- generic OpenAPI application tutorials;
- generic compliance handbooks.

This will make Webrick's documentation smaller and more authoritative.

---

# 60. Documentation Build and Example Validation

Add an automated documentation validation stage where practical.

At minimum:

- Sphinx build must succeed;
- internal links must resolve;
- referenced classes/methods should exist;
- PHP snippets intended as complete files should parse;
- CLI examples should correspond to actual commands;
- constructor named arguments must match code.

For critical examples, convert snippets into executable integration fixtures rather than relying on manual review.

---

# 61. Current Docs — Concrete Fixes

Include these in the documentation sweep:

- replace outdated CacheLayer 2 references;
- remove nonexistent `Request::query()` examples;
- fix examples using undefined `$r`;
- remove speculative `debug: true // If supported`;
- correct PSR interface examples;
- align contributor test commands with actual tooling;
- correct production signing-key defaults;
- remove placeholder security contacts;
- remove unsupported fixed performance claims;
- remove universal persistent-PDO recommendation;
- make matcher recommendations benchmark-driven;
- audit all old middleware constructor examples;
- audit route-cache CLI examples;
- audit CacheLayer throttle examples against 3.1;
- audit InterMix examples against 9.1;
- audit ArrayKit namespaces/API against 5.1.

---

# 62. Benchmark Architecture

Preserve current separation of measured costs.

Do not combine these into one meaningless "router benchmark":

```text
route registration
route compilation
cache generation
cached boot
first cached request
steady-state route matching
kernel dispatch
middleware dispatch
signed URL generation
signed URL verification
ETag generation
```

These costs occur at different lifecycle stages.

---

# 63. Standalone Matcher Benchmark

Keep:

```text
benchmark/hello_matchers.php
```

It should remain independent and directly executable.

Preserve:

- Fused;
- Generated;
- Sharded;
- in-memory;
- cache-hot;
- warmup;
- multiple timed rounds;
- best and average metrics;
- static routes;
- dynamic routes;
- domain dynamic routes;
- signed URL operations.

Potential improvement:

separate setup/build timing even more explicitly from steady-state matching if any setup accidentally enters the measured section.

---

# 64. Structured Benchmark Suite

Keep and expand:

```text
benchmarks/MatcherBench.php
benchmarks/KernelDispatchBench.php
benchmarks/CacheLifecycleBench.php
benchmarks/SignedUrlBench.php
benchmarks/EtagBench.php
```

Do not duplicate identical scenarios unnecessarily between standalone and structured benchmarks.

Overlap is fine when:

```text
standalone = immediate human comparison
structured = repeatable benchmark suite
```

---

# 65. Route-Scale Benchmarks

Add synthetic route-set scales:

```text
10
50
100
250
500
1,000
5,000
10,000
```

Include representative distributions:

- static only;
- mostly static;
- 50/50 static/dynamic;
- constrained parameters;
- domain routes;
- deep prefix trees;
- similar/conflicting prefixes;
- worst-case misses;
- 405;
- HEAD;
- OPTIONS.

Do not run every gigantic matrix in the normal developer test suite.

Provide targeted benchmark groups.

---

# 66. Memory Benchmarks

Measure more than ops/sec.

Track when practical:

```text
route build peak memory
cache generation peak memory
cached boot memory
steady worker memory
first request allocation
matcher artifact size
```

Especially compare Fused versus Generated versus Sharded.

Sharding should be justified by both execution and memory/locality behavior.

---

# 67. InterMix Benchmarks

Expand kernel dispatch benchmarks to cover:

```text
closure handler
static controller method
instance controller
invokable class
class middleware
closure middleware
direct factory middleware
singleton middleware
scoped middleware
transient middleware
compiled resolver
regular resolver
```

Benchmark actual request handling rather than micro-benchmarking DI calls in isolation only.

---

# 68. Cache Benchmarks

Add targeted benchmarks for:

- response cache hit;
- response cache miss;
- response cache write;
- cache backend failure;
- optional stampede protection;
- throttle atomic increment;
- generic PSR fallback;
- tiered cache supplied to response middleware where applicable.

Do not bundle network latency numbers into framework-core benchmark claims without clearly identifying the backend.

---

# 69. Dependency Integration Test Matrix

Explicitly test against target ecosystem versions:

```text
ArrayKit 5.1
InterMix 9.1
CacheLayer 3.1
```

## ArrayKit

Test:

- lazy request collection creation;
- collection mutation/immutability assumptions;
- serialization/JSON behavior used by Webrick.

## InterMix

Test:

- container creation;
- handler invocation;
- static method;
- instance controller;
- invokable controller;
- constructor injection;
- service providers;
- tags;
- direct factories;
- singleton lifetime;
- scoped lifetime;
- transient lifetime;
- request scope;
- compiled resolver;
- multiple sequential requests;
- multiple kernels.

## CacheLayer

Test:

- response cache;
- local backend;
- cache failure;
- atomic throttle;
- generic fallback;
- TTL;
- namespace;
- distributed backend fixture where CI allows;
- tiered cache compatibility where worthwhile.

---

# 70. Response Cache Privacy/Security Tests

Add explicit matrix:

```text
Authorization
Cookie
Set-Cookie
Cache-Control: private
Cache-Control: no-store
Vary: Authorization
Vary: Cookie
personalized route/attribute
different hosts
same path on different domains
different query values
different query order
missing versus empty Vary header
Accept
Accept-Language
Accept-Encoding
negotiated charset
negotiated locale
GET
HEAD
```

A false cache hit across users is much worse than a cache miss.

---

# 71. Matcher Correctness Tests

Ensure all matcher implementations produce equivalent observable routing semantics.

Matrix should include:

- static;
- dynamic;
- typed constraints;
- optional parameters;
- domains;
- wildcard domains if supported;
- group prefixes;
- trailing slash behavior;
- GET;
- POST;
- PUT;
- PATCH;
- DELETE;
- HEAD;
- OPTIONS;
- ANY;
- 404;
- 405;
- route precedence;
- duplicate/conflicting route definitions.

Run equivalent cases through:

```text
Fused
Generated
Sharded
```

---

# 72. Route-Cache Parity Tests

For representative route sets verify:

```text
uncached result === cached result
```

for:

- route;
- parameters;
- middleware descriptors;
- route name;
- domain;
- URL aliases;
- signed URL generation;
- static controller handlers;
- instance handlers;
- closure handlers;
- attribute routes.

Test every matcher/cache mode.

---

# 73. Persistent Worker Tests

Simulate repeated sequential requests through the same kernel/container.

Verify no leakage of:

- Request;
- route parameters;
- authenticated/request attributes;
- scoped middleware;
- scoped services;
- URL helper temporary state;
- exception state;
- response state.

Also test bootstrap/reset behavior of static registries.

---

# 74. CLI

Keep the `webrick` binary and Composer convenience script.

Audit CLI behavior for:

- `route:cache`;
- `route:clear`;
- matcher option;
- cache paths;
- route-file validation;
- failure exit codes;
- invalid matcher names;
- inaccessible routes file;
- unwritable output;
- partial build cleanup;
- aggressive clear.

CLI errors should be deterministic and useful for CI.

---

# 75. Route Cache Failure Policy

Production documentation should continue recommending:

```text
cache build failure = deployment failure
```

Do not silently fall back to live production route regeneration when an explicitly configured prebuilt artifact is corrupt/incompatible unless the application deliberately opted into that behavior.

Executable route-cache files must be treated as trusted deployment artifacts.

---

# 76. Cache Permissions

Continue recommending:

```text
deployment/build user = write
runtime web user = read
```

where possible.

Do not recommend world-writable cache directories as production fixes.

Development-only examples using permissive permissions must be labeled clearly.

---

# 77. PHP 8.4 Optimization

Because Webrick already requires PHP 8.4+, prefer current language features when they improve code.

Examples:

- typed class constants;
- first-class callables;
- `match`;
- readonly where appropriate;
- `array_any()` / `array_all()` where cleaner and benchmark-neutral;
- modern type declarations.

Do not use newer syntax merely for novelty.

Hot-path generated code should continue favoring the fastest simple constructs.

---

# 78. Interfaces

Continue the rule:

> create an interface only for a genuine public/substitution boundary.

Good candidates already include concepts such as:

- matcher;
- emitter;
- body stream;
- cache/counter contract;
- view factory.

Do not create interfaces for internal one-implementation helpers solely to satisfy abstraction aesthetics.

---

# 79. Enums

Keep enums for meaningful public semantic domains such as:

- HTTP method;
- matcher mode;
- media type;
- status.

For small internal switches, class constants may be faster/simpler and avoid extra files.

Do not create enums for every internal state.

---

# 80. PHPDoc Cleanup

Reduce comments that merely repeat signatures.

Remove verbose private PHPDoc such as:

```text
@param string $name The name
@return string The rendered string
```

when types and method names already communicate the same information.

Keep PHPDoc when it adds:

- array shapes;
- generics;
- invariants;
- security semantics;
- cache schema;
- lifecycle requirements;
- non-obvious algorithms;
- complex callable signatures.

This should reduce noise without reducing static-analysis quality.

---

# 81. Comments

Comments should explain:

```text
why
invariant
performance reason
protocol subtlety
security requirement
```

not translate straightforward PHP into English.

Be particularly careful not to delete comments explaining HTTP RFC behavior or matcher/cache invariants.

---

# 82. File/Class Count

Do not fragment Webrick into many tiny classes while cleaning cognitive complexity.

Optimize for:

```text
clear cohesion
reasonable class complexity
minimal indirection
hot-path efficiency
```

rather than minimum lines per class.

Internal one-use helpers should remain functions/private methods where appropriate.

---

# 83. Middleware Ordering Documentation

Middleware behavior depends strongly on ordering.

Create one canonical order/ownership guide covering:

- normalization;
- gateway/proxy security;
- request limits;
- negotiation;
- throttling;
- response cache;
- route middleware;
- validators;
- compression;
- Vary accumulation;
- response linting/telemetry.

Avoid different docs recommending inconsistent orders.

Explain what happens when the application intentionally changes the order.

---

# 84. Response Linter

Keep `ResponseLinterMiddleware` development/test oriented.

Do not enable it by default in production.

Its checks can be valuable but should not add runtime cost to normal deployments.

---

# 85. Maintenance Middleware

Keep maintenance mode optional and off the normal production hot path unless enabled.

Do not register development/operational middleware globally simply because it ships with the package.

---

# 86. Default Global Middleware

Webrick should remain lightweight by default.

Do not automatically enable a large "recommended" global stack.

Let applications explicitly choose:

- hardening;
- compression;
- negotiation;
- telemetry;
- response cache;
- etc.

No middleware should mean minimal dispatch overhead.

---

# 87. Dependency Loading

Ensure optional components do not trigger class loading/fatal dependency assumptions during ordinary router bootstrap.

Without CacheLayer installed, these should still work:

- route registration;
- route matching;
- dispatch;
- request;
- response;
- signed URLs;
- emitters;
- non-cache middleware.

Optional middleware that actually requires CacheLayer may then give a clear installation error when instantiated.

---

# 88. Composer Suggest Text

Keep `infocyph/cachelayer` in `suggest`, but update wording for 3.1/current behavior if necessary.

Suggested conceptual wording:

```text
Provides backends for ResponseCacheMiddleware and default/distributed throttle storage.
```

Do not imply CacheLayer is required for the entire package.

Keep OpenTelemetry suggestions accurate and optional.

---

# 89. Composer Minimum Stability

Keep:

```json
"minimum-stability": "stable",
"prefer-stable": true
```

unless `phpforge`'s development constraint itself creates a Composer resolution reason to change it.

Do not globally lower stability merely because one explicitly constrained dev dependency uses `dev-main@dev`.

---

# 90. Composer Autoload

Keep optimized PSR-4 structure.

Review whether:

```json
"files": [
    "src/functions.php"
]
```

contains only lightweight function declarations and no expensive bootstrap side effects.

Autoloaded files execute for every Composer load, so they must remain cheap.

Same principle for:

```json
autoload-dev.files
```

during development/tests.

---

# 91. Functions API

Audit global/namespaced helpers.

Keep only helpers that provide real ergonomic value.

Avoid duplicating every facade method with a global function.

Every autoloaded global helper increases public API surface and potential naming responsibility even when execution cost is small.

---

# 92. Installation Documentation

Review extension requirements.

Only mark an extension mandatory when Webrick core actually requires it.

Compression codecs should generally be described as optional where corresponding middleware can operate without them or fall back.

Do not list optional deployment features as hard package requirements.

---

# 93. Deployment Documentation

Consolidate overlapping Nginx/Apache/container pages where they repeat identical content.

Keep platform-specific recipes where they provide real value.

Canonical shared principles:

- preserve query string;
- one compression owner;
- route through front controller;
- pass trusted scheme/host information correctly;
- disable buffering for true streaming where necessary;
- prebuild route cache;
- use OPcache;
- runtime reads route artifact rather than rebuilding it.

Avoid maintaining the same configuration in five slightly different places.

---

# 94. Docker/Kubernetes Examples

Keep them illustrative, not authoritative infrastructure prescriptions.

Remove unrelated generic DevOps material.

Do not imply:

- one FPM process model fits all workloads;
- one `pm.max_children` value is universally correct;
- one container topology is mandatory.

Focus on Webrick-specific requirements.

---

# 95. FPM Guidance

Keep the sizing formula/measurement philosophy.

Avoid universal settings such as:

```text
pm = static is always best
pm.max_children = 24
```

without context.

Webrick documentation should teach measurement rather than prescribe arbitrary server sizing.

---

# 96. OPcache Guidance

Keep OPcache as a strong production recommendation.

Retain advice to benchmark JIT instead of assuming it helps HTTP routing.

Keep immutable deployment guidance where:

```ini
opcache.validate_timestamps=0
```

is used.

Clearly state that deployment must reload/restart workers appropriately after release changes.

---

# 97. Route Cache + Persistent Workers

Document explicitly:

```text
new deployment
 ↓
new route artifacts
 ↓
atomic release switch
 ↓
restart/reload persistent workers
```

Long-lived workers must not run new code indefinitely against old route artifacts.

---

# 98. Security Tests

Maintain dedicated tests for:

- CRLF/header injection;
- invalid host;
- path traversal;
- signed URL tampering;
- cookie tampering;
- request size limits;
- CORS origin handling;
- method override restrictions;
- cache privacy;
- trusted proxies;
- malformed ranges;
- content negotiation edge cases.

---

# 99. HTTP Protocol Correctness

Audit behavior against protocol semantics for:

- HEAD;
- OPTIONS;
- 204;
- 304;
- 1xx where relevant;
- `Content-Length`;
- `Transfer-Encoding`;
- Range;
- `If-Range`;
- ETag;
- Last-Modified;
- Vary;
- Cache-Control;
- Host;
- method safety/idempotency.

Performance optimizations must not change externally correct HTTP behavior.

---

# 100. Public API Naming Consistency

Run a final naming audit across:

```text
Request
Response
Router
Registrar
Route
Matcher
Middleware
Cache
Emitter
Signed URL
Attributes
```

Remove stale aliases/deprecated naming if this major release allows cleanup.

Do not retain two names for the same concept without a strong usability reason.

---

# 101. Examples / Demo Routes

Keep example routes useful for:

- smoke tests;
- benchmarks;
- signed URLs;
- domains;
- attributes;
- middleware.

Do not let example-only behavior affect production library design.

Keep benchmark fixtures deterministic.

---

# 102. Source Exception Messages

Review exception text for:

- route conflicts;
- invalid route constraints;
- invalid cache artifact;
- unsupported matcher;
- missing optional dependency;
- signed URL configuration;
- malformed middleware;
- failed CLI build.

Messages should identify the actual configuration/problem without exposing secrets.

---

# 103. Logging

Do not log:

- signed URL keys;
- cookie encryption keys;
- authentication headers;
- full sensitive query strings;
- raw credentials.

Optional infrastructure failures such as response cache outages can be logged at an appropriate level when a logger is available, but logging must not turn an optional cache failure into a request failure.

---

# 104. Cache Metrics

CacheLayer exposes metrics facilities.

Do not automatically export them through Webrick.

If the supplied CacheLayer instance collects metrics, users can consume them through CacheLayer.

Webrick should only expose its own meaningful HTTP-level telemetry:

- response cache hit/miss;
- throttle rejection;
- route/middleware timing;

when telemetry is enabled.

Avoid duplicating CacheLayer's backend metrics layer.

---

# 105. Performance Acceptance Rule

No performance-motivated refactor should merge merely because it looks theoretically faster.

For hot-path changes:

1. establish baseline;
2. apply change;
3. benchmark standalone runner;
4. benchmark structured suite where applicable;
5. compare memory;
6. run correctness tests;
7. retain change only when the tradeoff is justified.

A tiny isolated micro-benchmark win must not justify:

- more allocations elsewhere;
- slower cold boot;
- worse memory;
- incorrect semantics;
- much higher complexity.

---

# 106. Correctness Acceptance Rule

Every cache/matcher optimization must pass the same behavioral matrix before and after.

Particularly:

```text
uncached
fused cached
generated cached
sharded cached
```

should expose equivalent routing behavior.

---

# 107. Final Dependency Philosophy

The intended ecosystem relationship is:

```text
                    Webrick
                       │
          ┌────────────┼─────────────┐
          │            │             │
          ▼            ▼             ▼
     ArrayKit 5.1  InterMix 9.1  CacheLayer 3.1
       required      required       optional
          │            │             │
   request utility     DI         response cache
   collections       scopes       throttle storage
   selective use    invocation    optional tiering
                    resolver      stampede capability
                    serializer
```

### ArrayKit

Use selectively.

Do not force into hot paths.

### InterMix

Deep integration.

Use scopes, invocation, tagged services and compiled resolvers properly.

### CacheLayer

Optional but first-class when cache behavior is requested.

Do not make it mandatory.

### PHPForge

Owns development quality/security/tooling.

Do not force unrelated Webrick runtime/standalone benchmark commands into PHPForge.

---

# 108. Implementation Priority

## P0 — Mandatory before release

- upgrade ArrayKit to 5.1;
- upgrade InterMix to 9.1;
- upgrade CacheLayer dev integration to 3.1;
- keep PHPForge exactly `dev-main@dev`;
- fix all broken dependency APIs;
- run complete PHPForge quality/test pipeline;
- preserve request scopes;
- verify InterMix lifetime behavior;
- verify route serializer/native payload behavior;
- adapt atomic throttle integration;
- add throttle concurrency coverage;
- update CacheLayer documentation;
- fix stale/nonexistent documentation APIs;
- fix PSR positioning;
- fix cache privacy tests;
- audit broad `Throwable` catches;
- verify all three matcher cache formats.

## P1 — Strong release requirements

- evaluate public InterMix tag integration;
- integrate/validate compiled InterMix resolver;
- audit static mutable state;
- deterministic request constructor behavior;
- response-cache namespace;
- evaluate HEAD→GET cache reuse;
- evaluate optional stampede protection;
- trusted-proxy normalization;
- host/port tests;
- cookie crypto matrix;
- documentation code validation;
- remove inaccurate performance claims;
- correct security guide boundaries;
- clean Composer keywords;
- document `benchmark/` versus `benchmarks/`.

## P2 — Performance/maintainability strengthening

- synthetic matcher scale matrix;
- matcher memory benchmarks;
- expanded InterMix benchmarks;
- CacheLayer performance benchmarks;
- docs consolidation;
- PHPDoc cleanup;
- reduce generic documentation;
- reduce duplicated deployment examples;
- evaluate class complexity after migration.

---

# 109. Final Regression Checklist

Before release, confirm:

## Dependencies

- [ ] ArrayKit 5.1 installed and tested
- [ ] InterMix 9.1 installed and tested
- [ ] CacheLayer 3.1 tested as optional dependency
- [ ] PHPForge remains `dev-main@dev`
- [ ] Core installation works without CacheLayer

## Routing

- [ ] Fused matcher
- [ ] Generated matcher
- [ ] Sharded matcher
- [ ] Static routes
- [ ] Dynamic routes
- [ ] Constraints
- [ ] Domains
- [ ] Groups
- [ ] Attributes
- [ ] HEAD
- [ ] OPTIONS
- [ ] 404
- [ ] 405

## Route cache

- [ ] fused build/load
- [ ] generated build/load
- [ ] sharded build/load
- [ ] closure route
- [ ] static handler
- [ ] instance handler
- [ ] middleware descriptors
- [ ] aliases
- [ ] cache clear
- [ ] corrupted artifact
- [ ] incompatible artifact
- [ ] atomic sharded publication

## InterMix

- [ ] request scope
- [ ] singleton
- [ ] scoped
- [ ] transient
- [ ] controller injection
- [ ] tagged middleware
- [ ] DirectFactory
- [ ] service providers
- [ ] compiled resolver
- [ ] repeated requests
- [ ] persistent worker isolation

## CacheLayer

- [ ] response cache hit
- [ ] response cache miss
- [ ] backend read failure
- [ ] backend write failure
- [ ] cache privacy
- [ ] atomic throttle
- [ ] generic PSR fallback
- [ ] concurrency
- [ ] TTL/reset
- [ ] tiered cache accepted as supplied store where applicable

## Request/response

- [ ] globals
- [ ] fake request
- [ ] deterministic manual construction
- [ ] uploaded files
- [ ] JSON
- [ ] cookies
- [ ] streaming
- [ ] attachments
- [ ] ranges
- [ ] HEAD
- [ ] conditional requests
- [ ] compression
- [ ] ETags

## Security

- [ ] signed URL tampering
- [ ] expiry
- [ ] key rotation
- [ ] cookie tampering
- [ ] CRLF
- [ ] trusted proxy
- [ ] Host validation
- [ ] CORS
- [ ] cache privacy
- [ ] request limits

## Documentation

- [ ] no CacheLayer 2 references
- [ ] no nonexistent `Request::query()` examples
- [ ] no undefined variables in core examples
- [ ] no speculative unsupported APIs
- [ ] PSR wording accurate
- [ ] testing commands accurate
- [ ] security contacts accurate
- [ ] benchmark claims reproducible or removed
- [ ] matcher recommendation wording corrected
- [ ] both benchmark directories explained

## Tooling

- [ ] PHPForge full pipeline passes
- [ ] standalone `composer benchmark` passes
- [ ] `composer webrick` works
- [ ] structured benchmarks run
- [ ] Composer validate passes
- [ ] Composer audit passes
- [ ] documentation build passes

---

# 110. Final Composer Direction

The final Composer structure should remain intentionally simple:

```json
{
    "require": {
        "php": ">=8.4",
        "infocyph/arraykit": "^5.1",
        "infocyph/intermix": "^9.1",
        "psr/cache": "^3.0",
        "psr/log": "^3.0.2"
    },
    "require-dev": {
        "infocyph/cachelayer": "^3.1",
        "infocyph/phpforge": "dev-main@dev"
    },
    "scripts": {
        "benchmark": "@php benchmark/hello_matchers.php",
        "webrick": "@php webrick"
    }
}
```

Do not add Composer scripts just to mirror PHPForge capabilities.

Do not remove either of the two current project-specific scripts.

---

# 111. Final Architectural Position

After this work, Webrick should remain:

> A fast, framework-neutral PHP HTTP routing kernel with route compilation/caching, middleware, signed URLs, HTTP request/response facilities and explicit integration boundaries.

It should **not** become:

- a general configuration library;
- a generic cache framework;
- a database framework;
- an application authentication framework;
- an infrastructure handbook;
- a full-stack PHP framework.

Use the Infocyph ecosystem where each library genuinely owns the concern:

```text
Webrick     → HTTP/routing/kernel
InterMix    → DI/invocation/scopes/serialization
ArrayKit    → array/collection utilities
CacheLayer  → caching/storage/cache coordination
PHPForge    → development quality/tooling
```

Keep those boundaries strong.

---

# 112. Final Release Standard

The release is ready when:

1. the entire Webrick public surface works with ArrayKit 5.1 and InterMix 9.1;
2. all optional cache features work correctly against CacheLayer 3.1;
3. Webrick installs and routes requests without CacheLayer;
4. all matcher strategies remain behaviorally equivalent;
5. route-cache generation/load is deterministic and safe;
6. request scope is leak-free under repeated/persistent execution;
7. throttle concurrency is correct when using an atomic backend;
8. response caching cannot accidentally cross personalization boundaries;
9. documentation examples correspond to real APIs;
10. performance claims are reproducible rather than aspirational;
11. standalone and structured benchmark suites both pass and retain their distinct roles;
12. PHPForge reports no unresolved quality/security defects;
13. no new abstraction is added without a concrete correctness, usability or measured performance benefit.

At that point Webrick's architecture should be considered **functionally finalized**. Future work should primarily focus on protocol correctness, measured optimization, compatibility with supported PHP/dependency releases, and narrowly justified features rather than further architectural expansion.
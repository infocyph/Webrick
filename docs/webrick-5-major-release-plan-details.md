# Webrick 5.0 — Major Release Engineering Plan

## 1. Release objective

Webrick 5.0 should be treated as a runtime-architecture release, not as a matcher-only optimization release.

The governing rule is:

> Compile and validate everything possible before traffic; during a request, execute only the capabilities that the selected route actually needs.

This follows PHPForge engineering principles: correctness, security, data integrity and operational stability come first; performance is then measured as sustainable successful throughput through the complete production-equivalent path, not isolated micro-optimizations.

The current external benchmark shows the size of the opportunity: FastRoute reaches roughly 383,790 stable RPM while Webrick fused reaches about 175,650 and sharded about 169,648. The benchmarked Webrick route is only a static closure returning `Hello World!`, yet the full Webrick request/kernel/DI/response stack is paid.

The target therefore is not simply “make the trie faster.” The common Webrick request path must become dramatically smaller.

---

## 2. Non-negotiable Webrick 5 architectural rules

1. Production is compiled. Route discovery, attributes, reflection, handler inspection, middleware alias resolution, DI planning and cache validation happen before traffic.
2. Development and production runtimes are distinct. Development may discover, reflect and mutate configuration. Production consumes finalized artifacts.
3. InterMix `10.0.2` is the minimum DI/runtime foundation. Update Webrick from `^9.2` to `^10.0.2` and use the `ContainerBuilder` / generated `ProductionContainer` workflow rather than maintaining a parallel Webrick DI compilation model.
4. InterMix environment selection and runtime selection are separate concerns. An environment such as `local`, `test` or `prod` chooses conditional bindings; it must never implicitly choose development versus production runtime.
5. The consuming composition root owns the environment, InterMix artifact paths, compilation, trusted digest source and runtime selection. Webrick accepts/delegates that choice and must not secretly create a second container when an external runtime is supplied.
6. DI is conditional, not fundamental. A static zero-argument closure must never enter InterMix.
7. Request construction is conditional. Routing must be possible without materializing the complete Webrick Request.
8. Request scope is conditional. Do not establish an InterMix request scope unless the compiled execution plan proves it is required.
9. Static/worker state is immutable after boot. No route, middleware, request, trace, transport or user state may live in mutable process-global/static state.
10. Swoole/OpenSwoole, RoadRunner and Workerman are first-class persistent runtimes, but never dependencies of the core.
11. FPM/Apache remains first-class. Persistent-worker optimization must not turn Webrick into a Swoole-only framework.
12. HTTP correctness is part of performance. Incorrect behavior at higher RPM is a regression.

---

# 3. P0 release blockers

## 3.1 Implicit OPTIONS must never execute application handlers

Current matcher behavior can synthesize OPTIONS by selecting a real application route. Replace this with an explicit match outcome:

- `FOUND`
- `AUTO_OPTIONS`
- `METHOD_NOT_ALLOWED`
- `NOT_FOUND`

`AUTO_OPTIONS` contains only the allowed-method information and no application handler.

HEAD fallback should likewise be represented explicitly so GET fallback is clear and response-body suppression is handled correctly.

Do not model HTTP control flow using fake routes.

## 3.2 Conditional request evaluation must be RFC-correct

Reimplement conditional evaluation as an explicit state machine:

1. `If-Match`
2. `If-Unmodified-Since` only when `If-Match` is absent
3. `If-None-Match`
4. `If-Modified-Since` only for GET/HEAD and only when `If-None-Match` is absent
5. `Range` / `If-Range`

A matching `If-None-Match` must produce 304 for GET/HEAD and 412 for unsafe methods.

Add a complete combinatorial test matrix.

## 3.3 Swoole emitter must be coroutine-safe

`SwooleEmitter` must not store the current native Swoole response object in reusable mutable object state.

All engine transport handles must be request-local arguments/context.

Never store native Swoole request/response handles on a long-lived emitter/runtime object.

## 3.4 Remove process-global current-request telemetry state

No `TraceContext::$request`, current-response static, static user/IP state, or equivalent request-specific process-global state.

Introduce explicit `RequestContext`. If ambient context is genuinely required, use runtime-local/coroutine-local storage behind a dedicated abstraction.

Prefer explicit request context over hidden ambient context.

## 3.5 Middleware must be stateless per invocation

Any reusable middleware instance may contain only immutable configuration or concurrency-safe shared services.

Remove mutable current-request fields such as current `EndUser`.

Sequential `finally { $state = null; }` cleanup is not sufficient under overlapping coroutines.

## 3.6 Error handling must not manipulate process-global handlers around every request

Separate PHP error conversion from HTTP exception rendering.

Do not push/pop `set_error_handler()` for every successful request under persistent workers.

Install process-level error bridging once at boot where appropriate, or disable it.

Set production/debug defaults conservatively: `debug=false` by default.

## 3.7 Redesign CSRF

Remove direct `$_SESSION` dependence from the CSRF core.

Introduce an injected token store/session abstraction.

Verification should accept:

- header token
- form/body token

A cookie may carry the token to browser JavaScript but must not by itself satisfy CSRF verification.

Query-string CSRF tokens should not be accepted by default.

## 3.8 Conservative CORS defaults

Default CORS should be disabled or deny-by-default unless explicitly configured.

Rules:

- wildcard origin implies credentials false
- credentialed CORS requires explicit origins
- only a real CORS preflight should trigger preflight handling
- split CORS and general security-header concerns
- validate route-level CORS policy at compilation

## 3.9 Trusted proxy handling must be centralized

Normalize forwarded scheme/host/client IP once at the transport trust boundary.

Never trust `X-Forwarded-*` downstream unless the immediate peer has already been validated as trusted.

Proxy-chain handling should strip trusted proxies from the right-hand side and choose the nearest remaining client address.

Vendor-specific forwarding headers must require explicit trusted-upstream configuration.

## 3.10 Strict CIDR parsing

Validate IPv4/IPv6 prefix ranges during configuration/build:

- IPv4: 0..32
- IPv6: 0..128

Runtime matching should operate on pre-parsed immutable network descriptors, not raw CIDR strings.

## 3.11 Rewrite ByteRangeStream

Represent a range using:

- immutable `start`
- immutable `length`
- request-local mutable `position`

`getSize()` returns the full range length.

`rewind()` seeks to the range start, not byte zero.

All seeks must remain inside the range window.

## 3.12 Correct file range/cache defaults

Metadata-derived file validators must be weak unless exact representation bytes are hashed.

Do not apply generic `public, max-age=31536000, immutable` to every ranged file response.

Cache policy must be explicit.

Distinguish malformed range syntax from an otherwise valid but unsatisfiable range.

## 3.13 Header combination must be field-specific

Do not run every comma-separated header through one generic canonicalizer.

HTTP methods are case-sensitive tokens; never transform `GET` into `Get` in fields such as `Access-Control-Allow-Methods` or `Allow`.

Provide field-specific merge/combine semantics.

## 3.14 Make `#[Produces]` functional end-to-end

Compile route media-production metadata into the route runtime descriptor.

Do not depend on Request attributes as an internal transport channel between dispatcher and negotiation middleware.

## 3.15 Authorization must not become pseudo-header state

Normalize only the actual `Authorization` header.

Do not manufacture compatibility pseudo-headers containing credentials that can leak through generic header enumeration or telemetry.

## 3.16 Cookie identity must include path/domain

Structured cookies cannot be keyed only by name.

Either preserve an ordered list or key by at least `(name, domain, path)`.

Enforce cookie-prefix/security invariants:

- `__Host-`: Secure, Path=/, no Domain
- `__Secure-`: Secure
- SameSite=None requires Secure
- Partitioned requires Secure when supported

---

# 4. Split build plane and runtime plane

## Build plane

Responsible for:

- registrar execution
- attribute scanning/reflection
- route normalization
- duplicate detection
- handler inspection
- argument/invocation planning
- middleware alias resolution
- middleware requirement discovery
- domain configuration
- route constraint compilation
- content-negotiation policy compilation
- CORS policy compilation
- URL alias compilation
- integration with the host-owned InterMix `10.0.2` `ContainerBuilder`
- InterMix graph validation/compilation when the host or standalone Webrick build command explicitly delegates that responsibility
- cache/artifact validation
- route matcher generation

Suggested conceptual components:

- `RouteBuilder`
- `RouteCompiler`
- `RouteGraph`
- `HandlerCompiler`
- `MiddlewareCompiler`
- `MatcherCompiler`
- `CompiledRouterArtifact`

Names are flexible; the separation is not.

Webrick must not duplicate InterMix's static DI compiler. It should contribute definitions and consume the selected InterMix runtime.

## Runtime plane

Responsible only for:

`routing input -> match -> execution plan -> response -> native transport`

No reflection.

No directory scanning.

No route registration.

No `class_exists()` probing in the normal request path.

No middleware alias parsing.

No route-cache generation.

No dynamic DI graph construction.

No environment switching.

No runtime-mode inference from an environment string.

---

# 5. Production boot from a coordinated release bundle

Webrick 5 should emit one versioned Webrick production artifact containing its runtime information:

- route/matcher data
- execution-plan table
- route capability flags
- alias index
- middleware plans/IDs
- CORS and Produces metadata
- domain-routing capability flag
- artifact format version/fingerprint

InterMix `10.0.2` separately emits its generated PHP production artifact and adjacent `.meta.json` manifest. Do not copy or embed InterMix's generated container format into Webrick's artifact.

The consuming framework/application should publish these as one coordinated release unit:

- application code/dependencies
- Webrick compiled router artifact
- InterMix generated production artifact
- InterMix `.meta.json` manifest
- trusted deployment metadata/digest when `productionPrevalidated()` is used

Provide a Webrick production boot API equivalent to:

`RouterKernel::fromCompiledArtifact(...)`

that accepts the already selected InterMix production runtime rather than constructing another one internally.

The registrar-driven boot path becomes development/build oriented.

When strict production mode is enabled and the Webrick artifact is absent/stale, fail boot. Never silently fall back to scanning/reflection in production.

When the InterMix production artifact is absent, stale, environment-mismatched or fails its own validation, allow InterMix to fail boot. Webrick should not suppress or bypass those guarantees.

---

# 6. Integrate InterMix 10.0.2 as a delegated runtime

Update dependency:

`infocyph/intermix: ^10.0.2`

InterMix `10.0.2` intentionally exposes two DI execution paths:

- dynamic `Container` from `ContainerBuilder::development()`
- generated `ProductionContainer` from `ContainerBuilder::production()` or `ContainerBuilder::productionPrevalidated()`

The environment configured through `setEnvironment()` selects conditional bindings and metadata. It does **not** select which runtime Webrick should use.

## 6.1 Composition-root ownership

The consuming framework/application owns:

- the application `ContainerBuilder`
- the environment name
- all application/framework/package DI registrations
- the InterMix compile path
- whether the runtime is development or production
- whether normal production validation or prevalidated loading is used
- the trusted SHA-256 source for prevalidated loading
- worker/process replacement after deployment

Webrick owns only its own contributed registrations and its routing/runtime behavior.

Do not read `APP_ENV` inside Webrick to decide the InterMix runtime.

Do not call `setEnvironment()` behind the consuming framework's back.

Do not call `production()` merely because the environment string is `prod`.

Do not create a private/global fallback `Container` when the framework already supplied an InterMix runtime.

## 6.2 One application-owned graph

Development, release compilation and production bootstrap must be built from the same application-owned configuration function/graph.

Conceptually:

```php
function applicationContainer(string $environment): ContainerBuilder
{
    $builder = ContainerBuilder::create('application')
        ->setEnvironment($environment);

    // Foundation/application registrations...
    Webrick::contributeTo($builder);

    return $builder;
}
```

`Webrick::contributeTo()` is a conceptual API name. The important requirement is that Webrick contributes definitions/service providers to the caller-owned builder instead of constructing a competing DI graph.

This lets Foundation, Infbyte or another consuming framework remain the composition root and delegate Webrick consistently.

## 6.3 Explicit Webrick runtime boot contract

Provide explicit boot paths conceptually equivalent to:

```php
RouterKernel::development(
    routes: $registrar,
    container: $builder->development(),
    // ...
);
```

and:

```php
RouterKernel::fromCompiledArtifact(
    artifact: $routerArtifact,
    container: $productionContainer,
    // ...
);
```

where `$productionContainer` was selected by the consuming composition root using either:

```php
$builder->production($intermixArtifact);
```

or:

```php
$builder->productionPrevalidated($intermixArtifact, $trustedSha256);
```

Names are flexible, but runtime selection must be explicit at boot and resolved once.

If Webrick uses an internal DI executor abstraction, choose the development or production executor once during boot. Do not perform `instanceof`, environment checks or runtime-mode branching on every request.

## 6.4 Development path

Development Webrick should use the dynamic InterMix `Container` supplied by the host.

Development may support:

- registrar/attribute discovery
- reflection
- mutable route/config registration before lock
- InterMix debug tracing
- dynamic/autowiring diagnostics
- route cache rebuilds
- response linter/development diagnostics

The development path should remain behaviorally equivalent to production for supported features, but it does not need production's zero-reflection hot path.

## 6.5 Production build path

During CI, image build, release packaging or an explicit cache-warm/build command:

1. recreate the application-owned `ContainerBuilder` for the target environment;
2. let all packages/framework layers, including Webrick, contribute definitions;
3. validate the InterMix graph strictly;
4. compile the InterMix production artifact;
5. inspect the InterMix `compiled`/`skipped` report and intentionally review dynamic islands;
6. compile the Webrick router/runtime artifact from the same application configuration;
7. publish both artifacts and their metadata together.

Conceptually:

```php
$builder = applicationContainer('prod');
$builder->validate(strict: true);
$intermixReport = $builder->compile($intermixPath);
$webrickReport = $routerCompiler->compile($routerPath);
```

Webrick should not attempt to reproduce InterMix's `StaticRuntimeGenerator` or interpret its private generated format.

## 6.6 Production bootstrap

Production boot recreates the same host configuration graph so InterMix dynamic compatibility islands retain their fallback definitions, then loads the generated runtime once at process/worker bootstrap.

Conceptually:

```php
$builder = applicationContainer('prod');
$container = $builder->production($intermixPath);
$kernel = RouterKernel::fromCompiledArtifact($routerPath, $container);
```

For a deployment whose immutable artifacts were already validated by a trusted control plane:

```php
$builder = applicationContainer('prod');
$container = $builder->productionPrevalidated($intermixPath, $trustedSha256);
$kernel = RouterKernel::fromCompiledArtifact($routerPath, $container);
```

Webrick production boot should never invoke `ContainerBuilder::compile()` itself during request handling or worker traffic startup unless the caller explicitly selected a standalone build/warm command.

## 6.7 Prevalidated trust boundary

`productionPrevalidated()` is only appropriate when the digest comes from trusted immutable deployment metadata.

Do not source the trusted digest from:

- the same runtime-writable artifact directory
- a mutable cache controlled by the worker
- an arbitrary environment variable without a trusted deployment guarantee
- application request data

If the trust boundary is uncertain, use normal `production()` validation.

The same principle applies to Webrick's own compiled artifact prevalidation.

## 6.8 Environment/artifact alignment

InterMix records the compiled environment in its companion manifest and rejects a fallback graph configured for a different environment.

Webrick must preserve this model:

- environment selected before compilation
- one finalized artifact set per environment when bindings/metadata differ
- no environment switching after production finalization
- configuration mutation requires recompilation/redeployment

Webrick should include its own relevant environment/config fingerprint in the router artifact so route-level environment-dependent configuration cannot drift from the DI artifact.

## 6.9 Dynamic islands are valid compatibility boundaries

InterMix `10.0.2` can retain closures, direct factories, unusual callables, runtime parameters, custom runtime attributes and other non-static behavior in narrow dynamic fallback islands.

Webrick should:

- compile/direct-call what it can prove statically
- allow InterMix to own DI fallback islands
- surface build reports so unexpected skipped definitions are visible
- never force the entire application back to dynamic DI because one route/service requires a fallback island

## 6.10 Route execution kinds

Compile every route into an execution kind, conceptually:

- `DIRECT_ZERO_ARG`
- `DIRECT_ROUTE_ARGS`
- `DIRECT_REQUEST`
- `COMPILED_INVOKE`
- `MIDDLEWARE_PIPELINE`

### DIRECT_ZERO_ARG

Runtime should be equivalent to:

```php
$response = $handler();
```

No Request.
No argument map.
No Invoker.
No DI lookup.
No scope.

### DIRECT_ROUTE_ARGS

When all parameters are route variables, invoke directly using the matched variables.

### DIRECT_REQUEST

When the handler requires Request but not DI, materialize Request once and call directly.

### COMPILED_INVOKE

Use the already selected InterMix runtime only when dependency injection is required.

In production, prefer generated `ProductionContainer` resolution/invocation. In development, use the supplied dynamic `Container`.

Do not route every handler through InterMix simply because InterMix is available.

## 6.11 Scope rule and request seeds

Both InterMix development and generated production runtimes provide explicit scope handling. InterMix `10.0.2` isolates active scoped state between concurrent PHP Fibers and Swoole/OpenSwoole coroutines when each work item owns its scope.

Open a Webrick request scope only when:

- a scoped service is required
- Request is injected through DI
- middleware requires scoped services
- another compiled dependency requires it

Prefer `withinScope()` so exceptions cannot strand the execution-local scope.

Seed already-created request context rather than rebinding definitions per request, conceptually:

```php
$container->withinScope(
    $scopeId,
    $handler,
    [Request::class => $request],
);
```

Singleton services/configuration remain shared and must themselves be concurrency-safe.

Never mutate the builder, switch environments, attach fallbacks or deoptimize a production container while concurrent work is in flight.

## 6.12 Standalone Webrick behavior

Webrick may still offer a standalone convenience bootstrap for applications that do not provide a framework composition root.

Rules:

- standalone development may create its own `ContainerBuilder` explicitly
- standalone production must require an explicit compiled-runtime choice/artifact
- never guess production runtime from `APP_ENV`
- never silently compile at first request
- document standalone ownership separately from framework delegation

This preserves Webrick's usability as a router while allowing Foundation/Infbyte to own the real application graph.

## 6.13 Development/production parity tests

Every DI-sensitive Webrick feature must run through both paths:

- application graph + `development()`
- same graph + `compile()` + `productionPrevalidated()` using the build report SHA in tests

Compare observable behavior, not object identity.

Cover:

- scoped Request injection
- controller construction
- method invocation
- middleware services
- tags
- environment bindings
- contextual bindings
- lifecycle hooks
- dynamic islands
- Swoole/Fiber scope isolation

Unexpected InterMix skipped definitions in the production compilation report should fail or explicitly annotate release-readiness tests.

---

# 7. Rewrite Dispatcher around execution plans

Do not clone Request to attach duplicate route-param aliases such as:

- `route_params`
- `route.params`
- `params`

Route variables belong to the match/execution context.

Pass them directly to the compiled handler plan.

If a full Request needs route parameters, attach/expose one canonical route-parameter bag only when Request is materialized.

The same applies to:

- CORS metadata
- Produces metadata
- Vary state
- trace state

Internal framework metadata belongs in compiled route metadata or request context, not arbitrary Request attributes.

---

# 8. Introduce lightweight routing preflight

Create a compact immutable routing input from the native runtime:

- normalized method
- path
- host only when domain routing exists
- scheme only when required
- optional minimal transport flags

Then:

1. match
2. inspect route capability mask
3. materialize only the request data needed by that route

If the compiled router has no domain routes, skip host normalization entirely during routing.

A static zero-argument route should never open `php://input`, hydrate uploaded files, parse headers, build a complete Uri, copy cookies, or instantiate a full Request.

---

# 9. Normalize method/path/host once

Normalize at the transport/request trust boundary and trust the internal representation afterward.

Consider standard HTTP methods as compact internal IDs/bitmasks.

Benefits:

- cheap method dispatch
- cheap Allow generation
- natural GET/HEAD relationship
- automatic OPTIONS without rebuilding string sets
- no repeated `strtoupper()` / enum normalization

Unknown extension methods can use a slower string fallback.

---

# 10. Matcher architecture

Do not blindly copy FastRoute.

Build one canonical routing IR and let all matcher implementations consume the same IR.

This ensures identical semantics for FOUND, 404, 405, HEAD and OPTIONS across all modes.

## Static routes

Static lookup must occur before:

- trim
- explode
- segment counting
- trie traversal
- dynamic result allocation

Benchmark exact hash lookup vs generated switch vs fused lookup.

## Dynamic routes

Move runtime structural validation to compilation/artifact load.

Benchmark:

- generated segment tests
- literal-prefix buckets
- segment-count buckets
- compact trie
- FastRoute-style combined regex chunks

The compiler may select different strategies for different bucket sizes.

## Callable constraints

Resolve and validate callable constraints at build time.

Do not repeatedly call `is_callable()` while matching.

## Match result representation

Benchmark:

- compact positional arrays
- tiny readonly result object
- integer route ID plus param payload

Do not choose an object merely for aesthetics if it costs throughput.

---

# 11. Matcher modes

Retain matcher modes only when each serves a measured workload.

Add the generated/compiled backend to the external Benchmark suite.

Recommended positioning after the rewrite:

- **Compiled/Generated** — intended production default, subject to benchmark evidence
- **Fused** — compact portable/in-memory option
- **Sharded** — large-route-set/memory/startup-oriented option

All must share one canonical IR and semantic implementation.

If one mode no longer wins any meaningful workload, remove it in the major release.

---

# 12. Route compilation redesign

`CompiledRoute` should become the definitive runtime descriptor.

Add compiled metadata such as:

- deterministic route ID
- handler execution plan ID
- middleware pipeline ID
- capability mask
- request-required flag
- request-scope-required flag
- method mask
- domain requirement
- CORS policy ID
- Produces/media policy ID

Remove process-global auto-increment route identity.

Assign deterministic IDs in the compiler.

Do not use object identity as persistent route identity.

---

# 13. Collection indexing

The build-time Collection may remain developer-friendly.

The production graph should use canonical composite keys for route identity and duplicate detection.

Named routes should use a separate alias index.

Runtime must not depend on ambiguous path-only indexing.

---

# 14. Attribute routing

Keep attributes, but make reflection/scanning build-time only.

Compile values from:

- Route
- Group
- Middleware
- Cors
- Produces

into the route IR.

For deterministic production artifacts, middleware should normalize to:

- middleware service IDs
- class strings
- serializable immutable configuration descriptors

Do not serialize arbitrary middleware instances into production artifacts.

---

# 15. Closure route caching

Use InterMix `10.0.2` `ClosureSerializer` as the single closure serialization abstraction.

Recommended hierarchy:

- class/function handler: native scalar descriptor
- closure handler: InterMix ClosureSerializer
- signed closure serialization: only when the artifact crosses an untrusted boundary

A trusted local production route artifact does not need per-closure HMAC merely because a closure is serialized.

Do not serialize arbitrary values when only closures need serialization.

---

# 16. URL alias compilation

Compile URL aliases directly:

`name => [path, domain, parameter metadata]`

Do not reconstruct dummy Route objects solely for URL generation.

The alias map belongs inside the primary production artifact.

---

# 17. Signed URLs

Define one deterministic canonical query grammar shared by generation and verification.

Repeated/duplicate query keys must either be supported explicitly or rejected explicitly.

Do not rely on PHP parsing behavior that can alter duplicate-key representation.

Use an injected/request-anchored clock instead of scattered `time()` calls.

---

# 18. Request architecture

Webrick should have an explicitly native internal Request model optimized for its runtime.

PSR HTTP-message abstractions should live at real interoperability boundaries.

Provide PSR-7/PSR-17 bridges when applications/libraries require them.

Adapt once at the boundary.

Do not force the internal hot path through PSR abstractions merely because some classes currently live under a `Psr7` namespace.

Either implement the PSR interfaces at a real public boundary or rename/move pseudo-PSR classes.

---

# 19. Re-evaluate mandatory ArrayKit in Request core

ArrayKit Collection is currently used heavily for convenience façades around query/post/server/cookie/JSON/XML request values.

For Webrick 5, strongly consider returning arrays/scalars directly from the core Request API and removing mandatory ArrayKit if no other core subsystem justifies it.

Do not replace it with another elaborate bag abstraction.

Collection helpers can remain optional convenience APIs.

---

# 20. Correct JSON/XML request semantics

Do not use “empty parsed collection” as equivalent to “not JSON/XML.”

Track parsing state explicitly:

- not applicable
- not parsed
- parsed successfully, including empty
- invalid

A valid empty JSON object/array must not fall through to POST data.

Invalid JSON/XML needs an explicit policy.

---

# 21. Native body representation

Do not turn every string into a `php://temp` stream.

Introduce body variants conceptually equivalent to:

- `StringBody`
- `StreamBody`
- `FileBody`
- `IterableBody` / `GeneratorBody`

Normal text/HTML/JSON responses remain native PHP strings.

Create a stream/resource only when genuinely streaming or when an interoperability bridge requires one.

Fix stream string-conversion semantics for non-seekable streams.

---

# 22. Response architecture

Keep the public immutable API, but internally:

- validate headers once at trust boundary
- share immutable header storage between clones
- copy only when headers actually change
- keep string bodies as strings
- compute known body length directly
- avoid Response reconstruction when merely appending metadata

`LazyJsonStream` should become a lazy JSON body and must not create another temp stream after encoding.

---

# 23. Remove hidden DI from Response

`Response` must not reach into a global InterMix container to render views.

Move view rendering to a dedicated responder/helper/service using `ViewFactoryInterface` resolved by the caller-selected InterMix runtime where needed.

Response remains a value/output type.

---

# 24. Runtime adapter architecture

Replace emitter-centric engine discovery with one runtime/server adapter selected at bootstrap.

Conceptual responsibilities:

- create routing input
- materialize Request when required
- hold/provide request-local native transport context
- write Response
- expose runtime capabilities

Implementations:

- `SapiRuntimeAdapter`
- `SwooleRuntimeAdapter`
- `RoadRunnerRuntimeAdapter`
- `WorkermanRuntimeAdapter`

OpenSwoole can share the Swoole capability layer where practical.

---

# 25. Swoole/OpenSwoole runtime

Use Hyperf as an execution-model reference, not an architecture to copy.

## Worker boot

At worker start:

- receive/load the InterMix `10.0.2` production runtime selected by the host composition root
- load compiled route artifact
- freeze middleware/constraint/header registries
- resolve runtime capabilities
- initialize optional telemetry adapter
- initialize immutable shared config/caches

Do not recompile the InterMix graph or switch its environment at worker start.

## Request handling

On every request:

- use native Swoole request data
- never copy request state into process-global PHP superglobals
- create routing preflight
- match
- execute compiled plan
- open an InterMix scope only when the route capability plan requires it
- use `withinScope()`/execution-local scope state for DI-backed requests
- write using the native response object passed for that request

InterMix `10.0.2` already isolates scoped state between active Swoole/OpenSwoole coroutines; Webrick must preserve that by using explicit scopes and by keeping all non-DI request state request-local as well.

## Response fast paths

For ordinary string body, use native `end($body)`.

For streaming, use native write semantics with error/backpressure handling.

For FileBody, use native optimized file/sendfile behavior where supported.

If transport-level compression is enabled, tell the response pipeline so userland middleware does not recompress.

## Context rules

No:

- static current Request
- static current Response
- static current user/IP
- static trace state
- mutable request fields on reusable middleware
- per-coroutine `set_error_handler`
- request-specific superglobal/session state
- builder/container definition mutation while concurrent requests are active
- environment switching on the shared InterMix runtime

Worker recycling (`max_request`) is an operational safety bound, not a substitute for fixing state leaks.

---

# 26. RoadRunner adapter

Resolve RoadRunner bridge types once at worker startup.

Keep worker/request transport handles request-local.

Adapt Webrick Response once at the boundary.

No AutoEmitter discovery per request.

Use the host-selected InterMix runtime; do not construct a worker-local competing graph.

Add long-worker leak tests.

---

# 27. Workerman adapter

Resolve compatibility once at adapter construction:

- method support
- callable methods
- API-version behavior

Do not repeatedly perform `method_exists`, `is_callable`, `call_user_func`, or ReflectionMethod checks in the request emit path.

Use the host-selected InterMix runtime and explicit request scopes when required.

---

# 28. SAPI/FPM adapter

For Apache/FPM:

- read only minimal routing fields from `$_SERVER` before route selection
- copy globals only if Request is materialized
- small string Response should reduce to status/header calls plus `echo`
- avoid generic streaming machinery for small known bodies
- keep artifact loading OPcache-friendly
- never fall back to registrar/reflection in strict production mode
- consume the host-selected InterMix runtime rather than deciding runtime style from environment variables

---

# 29. Replace EmitterInterface semantics

`emit(Response, ?Request)` is insufficient for native engine transports.

Replace it with a response-writer/runtime contract that receives explicit request-local transport context.

SAPI can retain a portable base writer, but async engines should not inherit output-buffer machinery blindly.

---

# 30. Middleware compiler

Move to build/boot:

- alias parsing
- class/service resolution
- global/route merge and ordering
- invocation mode
- DI requirement
- request-scope requirement

Each route points to a prepared pipeline plan.

No middleware means pipeline ID zero and virtually no middleware machinery.

Middleware service IDs should resolve through the caller-selected InterMix runtime; Webrick should not duplicate service lifetimes or maintain another service locator.

---

# 31. Freeze mutable registries after boot

Freeze worker/bootstrap configuration such as:

- Router facade state
- URL generator registry
- middleware aliases
- route constraints
- HeaderPolicy registry
- Request macros
- default provider registries

Lifecycle:

`configure -> compile/freeze -> serve`

Mutation after freeze should throw in strict production mode.

Do not reset global route registries per request under coroutine servers.

For production DI changes, rebuild/recompile the application-owned InterMix graph and replace worker processes. Do not treat InterMix deoptimization as the normal deployment mechanism.

---

# 32. Atomic throttling only for production

A PSR cache read/change/write fallback is not a correct concurrent/distributed rate limiter.

Define an atomic counter contract for production throttling.

CacheLayer may supply an adapter, but should not become mandatory for core routing.

Any non-atomic fallback must be explicitly named/documented as approximate/development behavior.

---

# 33. Central cache policy

Replace ambiguous status-only cacheability helpers with a standards-aware `CachePolicy` decision layer considering:

- method
- status
- request/response directives
- authorization/private/public semantics
- explicit/heuristic freshness

Response caching should use native body representation without unnecessary stream copies.

---

# 34. Cache validators and static files

Do not perform global filesystem discovery/hash work on ordinary application requests.

Static resources should preferably be handled by:

- native web server
- runtime adapter
- explicit FileBody/static-resource handler

Application cache validators should consume already-known resource metadata.

---

# 35. Compression

Keep portable compression middleware, but:

- parse Accept-Encoding correctly
- explicit `q=0` must override wildcard acceptance
- negotiate identity correctly
- pre-resolve available codecs at bootstrap
- do not repeatedly probe functions/extensions
- avoid full-body copies where possible
- bypass userland compression when transport already owns it
- update Content-Length correctly
- preserve correct ETag representation semantics

Portable middleware should be the fallback, not always the engine-optimal implementation.

---

# 36. Vary accumulation

Move Vary accumulation into request-local context.

Do not clone Request merely to transport internal Vary tokens.

At response finalization, merge accumulated values with actual Response Vary once.

---

# 37. Maintenance mode

Do not stat filesystem metadata on every request.

Use:

- worker-local cached state with bounded refresh
- explicit in-memory control state
- server/control-plane notifications where available

A filesystem sentinel may remain as a portable source, but not as repeated hot-path I/O.

---

# 38. Request limits

Use two layers:

## Transport enforcement

Use Swoole/server/FPM/request-stream controls where available.

## Portable application fallback

Retain RequestLimits middleware for runtimes where transport-level enforcement is unavailable.

Do not fail a request merely because Content-Length is absent; body-capable HTTP requests can be chunked/streamed.

---

# 39. Input sanitization

Do not present blanket mutation as security.

Prefer:

validation -> explicit domain normalization -> sink-specific output encoding

If InputSanitizer remains, it should be an explicit application transformation utility.

Ensure any computed upload-name/type transformations are actually applied.

---

# 40. Telemetry

Disabled telemetry must have near-zero cost.

At bootstrap:

- detect optional OTel support
- build a typed/internal adapter
- resolve dynamic SDK compatibility once

At request time:

- no repeated `method_exists`
- no repeated callable/reflection discovery
- no process-global current Request

Validate incoming request/correlation IDs:

- bounded length
- safe visible-character grammar
- no control characters

Do not echo/log arbitrary user-provided IDs verbatim.

---

# 41. Content negotiation

Negotiation should only run on routes that declare/use it.

Fix:

- q-value precedence
- q=0 exclusions
- wildcard precedence
- Accept-Charset failure behavior
- route Produces propagation
- error-response Accept negotiation
- empty supported-language configuration
- invalid q-values

Compile route media policies so routes without negotiation never invoke negotiation parsers.

---

# 42. Header representation

Create one internal header representation with:

- lowercase lookup key
- preserved/canonical output name
- ordered values
- Set-Cookie as distinct lines
- single validation boundary

Use field-specific combiners for structured/list fields.

Do not apply one generic comma-merger to every header.

---

# 43. Uploaded files

`UploadedFile::moveTo()` should not create arbitrary target directories using permissive 0777 semantics.

Caller/runtime owns directory provisioning and permissions.

Check every copy/move return value.

Keep uploaded-file hydration lazy.

---

# 44. URI/path handling

Runtime adapters already know scheme/host/port/path/query. Construct Uri from validated components rather than rebuilding a full URI string only to pass it through generic parsing.

Define routing normalization for:

- double slashes
- encoded slashes
- dot segments

URL signing and URL verification must use identical normalization.

---

# 45. Error rendering

Error handling is a cold path: correctness wins.

Rules:

- debug default false
- only `HttpExceptionInterface` and explicit configured maps define HTTP status
- do not inspect arbitrary exception properties/methods for status
- proper Accept negotiation
- bounded request ID
- no per-request process-global error handler under persistent engines
- HEAD errors suppress body
- no stack/file leakage outside debug mode

---

# 46. Security-header design

Split CORS from general security headers.

Scheme/security state must come from normalized trusted request context.

Never inspect raw forwarded headers later in the middleware stack.

Security defaults should be conservative and explicit.

---

# 47. Cookie encryption

Keep optional.

Persistent-worker requirements:

- immutable key/configuration
- no mutable current-request state
- request-local decoded values
- deterministic key-rotation configuration loaded at boot

Routes not using cookies must not pay cookie-crypto cost.

---

# 48. Response linter

Keep as development/test tooling only.

Do not register in production.

Review rules that incorrectly assume any strong ETag plus Content-Encoding is invalid; a strong ETag may validly identify the exact selected encoded representation.

---

# 49. ETag utilities

`xxh128` is a fast fingerprint, not a cryptographic hash.

Documentation should use correct terminology.

Strong ETag means byte-for-byte representation identity.

When hashing streams, restore the original pointer inside `finally`.

---

# 50. Range subsystem rewrite

Rewrite together:

- RangeParser
- ConditionalValidator
- RangeResponder
- ByteRangeStream

Test:

- `bytes=0-0`
- open-ended ranges
- suffix ranges
- zero-byte resource
- malformed syntax
- unsatisfiable ranges
- multiple-range policy
- If-Range strong tag
- weak ETag with If-Range
- date If-Range
- HEAD
- interaction with If-Match / If-None-Match

Where supported, FileBody + range metadata should use the runtime's native partial-file/sendfile path.

---

# 51. Remove mandatory global helper autoload

Do not force `src/functions.php` into every request simply for a global `route()` helper.

Make global helpers opt-in or prefer `Router::urlFor()` / injected UrlGenerator.

Do not mechanically merge source files merely to reduce included-file count; only make source-layout changes that benchmark positively.

---

# 52. Request macro behavior

If MacroMix remains on Request, macro registration must complete before worker freeze.

Dynamic macro mutation after serving begins should be disabled or explicitly unsupported in concurrent production mode.

---

# 53. Dependency plan

Recommended core dependencies:

- PHP `>=8.4`
- InterMix `^10.0.2`
- `psr/log`
- `psr/cache` only while portable cache middleware exposes PSR cache integration

Strongly evaluate removing mandatory ArrayKit from core Request.

Keep optional:

- CacheLayer adapter
- OpenTelemetry SDK/exporter
- Opis Closure indirectly through InterMix ClosureSerializer
- Swoole/OpenSwoole
- RoadRunner
- Workerman

No engine runtime should become mandatory for the router core.

Webrick should depend on InterMix directly rather than inventing a lowest-common-denominator PSR-11 abstraction for invocation/scoping; however, the consuming framework must remain the owner of the InterMix builder and runtime selection.

---

# 54. Cache artifact security

A PHP route cache is executable code.

Do not rely on post-`require` runtime hashing as a security boundary.

Correct model:

`build -> validate -> atomically publish -> trusted deployment ownership/permissions`

Production prevalidated mode should avoid expensive repeated validation when the deployment system has already established trust.

For InterMix `productionPrevalidated()`, use only a digest originating from trusted immutable deployment metadata. Otherwise use normal `production()` validation.

Artifact format/version/environment mismatch should fail boot quickly.

---

# 55. Atomic artifact publication

Extend existing atomic route-cache publication guarantees to the entire Webrick compiled runtime artifact.

At deployment level, publish the Webrick artifact and InterMix artifact/manifest as one coordinated release. Replace workers/processes only after the complete release set is ready.

A worker/request must observe either:

- the old complete release artifact set
- the new complete release artifact set

Never partial mixed state.

---

# 56. Closure routes

Continue supporting production closure routes, but treat them as the exceptional serialized handler form.

Use InterMix `10.0.2` ClosureSerializer.

Class methods/functions remain the preferred deterministic scalar artifact form without banning closures.

---

# 57. File-by-file source disposition

## Constants

- `HttpMethodEnum.php` — keep public semantics; add internal method ID/bitmask conversion.
- `MatcherModeEnum.php` — align modes with measured production use cases.
- `MediaTypeEnum.php` — retain; capability compilation keeps it off trivial routes.
- `StatusEnum.php` — retain statuses; move cache decisions to CachePolicy.

## Exceptions

- `HttpException.php` — retain and validate once.
- `HttpExceptionInterface.php` — authoritative HTTP exception contract.
- `MethodNotAllowedException.php` — public/error boundary only; matcher returns an outcome internally.
- `RouteNotFoundException.php` — same principle.

## Interfaces

- `BodyStream.php` — make a true interoperability boundary or replace internal use with native body types.
- `RouteInterface.php` — keep build-facing; runtime consumes compiled descriptors.

## Middleware

- `CacheValidatorsMiddleware.php` — no global filesystem probing.
- `CompressionMiddleware.php` — fix negotiation; native runtime bypass.
- `CookieAttributeApplier.php` — retain only where cookies exist.
- `CookieEncryptionMiddleware.php` — optional, worker-safe.
- `CorsAndPoliciesMiddleware.php` — split CORS/security concerns.
- `GatewayHardeningMiddleware.php` — remove mutable EndUser/current-request state.
- `InputSanitizerMiddleware.php` — explicit transformation, not blanket security.
- `MaintenanceModeMiddleware.php` — remove per-request filesystem polling.
- `NegotiationMiddleware.php` — consume compiled route policy.
- `NormalizeMethodMiddleware.php` — likely remove; normalize method at request boundary once.
- `RequestLimitsMiddleware.php` — portable fallback only.
- `ResponseCacheMiddleware.php` — CachePolicy + native bodies.
- `ResponseLinterMiddleware.php` — dev/test only.
- `TelemetryMiddleware.php` — RequestContext, compiled OTel adapter.
- `ThrottleMiddleware.php` — atomic backend required for production.
- `VaryAccumulatorMiddleware.php` — request-local context, no Request cloning.
- `VerifySignedUrlMiddleware.php` — deterministic canonical query + clock.

## Request/Core

- `Message.php` — consolidate header normalization/copies.
- `Stream.php` — no longer default for string bodies; fix non-seekable behavior.
- `UploadedFile.php` — strict move/copy semantics, no 0777 auto-dir.
- `UploadedFileCollection.php` — keep lazy if useful.
- `Uri.php` — construct from validated components.
- `UriServerParams.php` — move SAPI extraction to runtime adapter/trust boundary.

## Request/Http

- `ContentNegotiator.php` — correct parser, capability-gated.
- `Csrf.php` — rewrite around token-store abstraction.
- `EndUser.php` — fix proxy chain and trusted proxy config.
- `RequestHeaders.php` — no pseudo credential headers; fix weighted negotiation.
- `UAParser.php` — explicit User-Agent/request input; no hidden globals.

## Request/Psr7

- `HttpFactory.php` — actual PSR bridge or rename.
- `ServerRequest.php` — major lazy simplification; consider removing ArrayKit; fix empty JSON/XML semantics.
- `ServerRequestHeaderNormalizer.php` — consolidate into single header boundary.
- `UploadedFilesNormalizer.php` — strict validation + lazy hydration.

## Request

- `Request.php` — lightweight façade over request-local data/context; freeze macros after boot.

## Request/Support

- `HeaderBag.php` — immutable sharing; validate once.
- `IpCidr.php` — strict prefix parsing at boot.

## Response/Conditional

- `ConditionalValidator.php` — RFC rewrite.
- `Outcome.php` — retain concept, potentially typed enum/result.

## Response/Cookies

- `Cookie.php` — enforce modern prefix/security invariants.
- `CookieJar.php` — support same-name cookies with different path/domain.

## Response/Emitter

- `AutoEmitter.php` — replace/demote in favor of boot-time runtime selection.
- `BaseEmitter.php` — SAPI/portable only.
- `CliEmitter.php` — keep.
- `DefaultEmitter.php` — SAPI response writer.
- `EmitterInterface.php` — replace with transport-context-aware writer/runtime contract.
- `RoadRunnerEmitter.php` — startup capability resolution and request-local transport.
- `SwooleEmitter.php` — full stateless rewrite.
- `WorkermanEmitter.php` — no hot-path reflection/call_user_func.

## Response/Headers

- `CacheControl.php` — retain parser/value object; decisions go to CachePolicy.
- `ContentDisposition.php` — retain, add edge-case tests.
- `HeaderPolicy.php` — field-specific combiners, frozen registry.
- `Language.php` — strict list/q-value handling.
- `Range.php` — align with rewritten range semantics.
- `RateLimit.php` — retain helper, validate values.
- `SecurityHeaders.php` — consume trusted normalized scheme only.
- `Vary.php` — retain.

## Response/Internal

- `LazyJsonStream.php` — replace with lazy JSON body without temp stream.
- `Utils.php` — correct ETag terminology; deterministic time source where needed.

## Response/Negotiation

- `ContentTypeNegotiator.php` — consolidate with one correct negotiation engine.

## Response/Range

- `ByteRangeStream.php` — rewrite.
- `RangeParser.php` — distinguish invalid/unsupported/unsatisfiable.
- `RangeResponder.php` — explicit caching, correct validators, native FileBody path.

## Response

- `Response.php` — native body representation; shared immutable headers; no hidden InterMix locator.

## Response/View

- `ViewFactoryInterface.php` — keep.

## Router/Constraint

- `Registry.php` — freeze at boot, validate constraints once, strengthen IP/IPv6 behavior.

## Router/Definition/Attribute

- `AttributeRouteLoader.php` — build/dev only.
- `Cors.php` — retain and validate at compile.
- `Group.php` — retain; normalize middleware objects to service descriptors.
- `Middleware.php` — same production normalization rule.
- `Produces.php` — retain and make end-to-end functional.
- `Route.php` — retain build-facing API; compiler owns normalization.

## Router/Definition

- `GroupScope.php` — build-only helper.
- `Registrar.php` — retain ergonomic build API; never execute on strict compiled production boot.
- `registrar_functions.php` — optimize only if measured.

## Router/Dispatch

- `Dispatcher.php` — major rewrite around ExecutionPlan and the already selected InterMix development/production runtime.
- `MiddlewareAliases.php` — resolve/freeze before serving.
- `MiddlewarePipeline.php` — consume prepared invokables; zero-cost no-middleware path.

## Router/Facade

- `Router.php` — build/bootstrap convenience; freeze before serve.

## Router/Kernel

- `ErrorHandler.php` — split PHP error bridge, fix defaults/negotiation.
- `RouterKernel.php` — major compiled runtime rewrite; explicit development/compiled boot paths; accept externally selected InterMix runtime; no unconditional Request/Invoker/scope and no implicit environment-to-runtime mapping.

## Router/Matching

- `AbstractMatcher.php` — remove runtime structural validation.
- `CachedHandlerNormalizer.php` — build-time only.
- `FusedMatcher.php` — consume canonical IR; static fast path.
- `GeneratedMatcher.php` — rewrite static-before-segmentation; production-default candidate.
- `MatcherCacheLifecycleTrait.php` — simplify strict artifact lifecycle.
- `MatcherCachePayloadNormalizer.php` — build-only; InterMix closure serializer.
- `MatcherFactoryTrait.php` — keep if useful.
- `MatcherInterface.php` — explicit match outcomes, not fake routes/exceptions for normal control flow.
- `ShardedCacheGeneration.php` — preserve atomic publication if sharded remains.
- `ShardedMatcher.php` — canonical semantics.
- `matcher_functions.php` — move validation/callability out of runtime; keep free functions only where measured faster.

## Router/Route

- `Collection.php` — build-only; correct composite indexing.
- `CompiledCollection.php` — retain lightweight compile container.
- `CompiledRoute.php` — execution/capability descriptor + deterministic IDs.
- `CompiledRouteCachePayload.php` — version new artifact schema; validate only at boundary.
- `Route.php` — retain user-facing definition.
- `RouteCoreAccessors.php` — align with new metadata.

## Router/Url

- `SignedUrlConfig.php` — deterministic canonical query + clock.
- `UrlGenerator.php` — consume alias map directly.
- `UrlGeneratorRegistry.php` — immutable/frozen boot state.

## Support

- `Etag.php` — strong/weak semantics + stream restoration.
- `HttpUtils.php` — retain small stateless helper or inline if measured.
- `InputSanitizer.php` — explicit transformation utility.
- `OpenTelemetryHandler.php` — precompiled SDK adapter.
- `RouteCache.php` — compiler/artifact publisher; coordinate build metadata with the host release, not InterMix internals.
- `RouteCacheBindUrlServicesCallback.php` — replace route hydration with direct alias binding.
- `RouteCacheBuildRegistrarCallback.php` — tooling/build only.
- `StreamUtil.php` — genuine streams only.
- `TelemetryOptions.php` — immutable config; validate header names/limits once.
- `TelemetrySupport.php` — stateless/request-context driven.
- `TraceContext.php` — full rewrite; no static Request.

## Root source

- `functions.php` — remove from mandatory Composer autoload; global helper opt-in.

---

# 58. Test architecture

Add five dedicated test classes/suites.

## Protocol correctness

Test:

- conditional requests
- ranges
- HEAD
- OPTIONS
- Allow
- content negotiation
- cache semantics
- CORS
- trusted proxies
- cookies
- signed URLs

## Compiler/runtime parity

For every supported route feature:

`development registrar result == compiled runtime result`

For every DI-sensitive route feature, run the same application-owned InterMix graph through:

- `$builder->development()`
- `$builder->compile()` + `$builder->productionPrevalidated()` in test-controlled trusted mode

Verify observable parity for controllers, middleware, scopes, environment bindings, contextual bindings, tags, lifecycle hooks and dynamic islands.

Inspect the InterMix compilation report so skipped definitions are intentional, not invisible production fallbacks.

## Engine conformance

Run equivalent route behavior through:

- SAPI
- Swoole
- OpenSwoole where supported
- RoadRunner
- Workerman

## Persistent-worker concurrency

Interleave requests with unique:

- route params
- request IDs
- trace IDs
- cookies
- EndUser/IP values
- headers
- native response handles
- scoped DI service/request seeds

Assert zero cross-request contamination.

Especially test Swoole with concurrently suspended/resumed coroutines while sharing one InterMix `10.0.2` runtime and explicit per-request scopes.

## Long-worker soak

Large request-count test asserting:

- no retained Request references
- no retained Response/native transport references
- RequestContext cleared
- InterMix scopes closed
- no InterMix builder/runtime mutation during traffic
- bounded matcher/middleware caches
- no monotonic unbounded memory growth

---

# 59. Benchmark matrix

Keep the independent Benchmark repository as the external full HTTP benchmark.

Add `webrick-compiled` alongside fused/sharded during development.

Internally benchmark each layer.

## Matcher

Cases:

- static hit
- first dynamic hit
- deep dynamic hit
- miss
- method mismatch
- HEAD fallback
- OPTIONS
- host route
- wildcard host

Route counts:

- 10
- 100
- 1,000
- 10,000

## Dispatch

- zero-arg closure
- route-parameter closure
- Request handler
- controller without DI
- controller with dynamic InterMix development runtime
- controller with InterMix `10.0.2` generated production runtime
- one middleware
- five middleware
- scoped service

## Response

- 12-byte text
- 1 KB text
- JSON array
- JsonSerializable
- 1 MB body
- stream
- file
- range

## Full runtime

- FPM/Apache
- Swoole
- RoadRunner
- Workerman where practical

Production benchmarks must build artifacts before traffic and must not compile InterMix or Webrick during the measured request run.

---

# 60. Performance release gates

Do not use one absolute RPM target across machines.

Use same-run ratios against controls.

For the current static benchmark, set release floor:

**Webrick compiled static endpoint >=80% of FastRoute sustainable stable RPM in the same run with zero validation/error failures.**

Stretch target:

**>=85% FastRoute** for trivial static/no-middleware route.

Additional gates:

- no >5% sustainable regression against Webrick 4 on feature-heavy representative route without explicitly accepted correctness tradeoff
- dynamic matcher materially improves over current Webrick matcher
- production DI routes use InterMix `10.0.2` generated runtime when supplied by the host
- no production request-time DI compilation or environment switching
- no concurrency leak failures
- persistent-worker memory plateaus after warm caches
- disabled diagnostics have near-zero overhead
- compiled production boot materially cheaper than registrar boot

Reject optimizations that win microbenchmarks but lower sustainable successful full HTTP throughput.

---

# 61. Implementation order

## Phase 1 — correctness/security blockers

Fix:

- OPTIONS
- conditional requests
- CIDR
- CSRF
- CORS
- proxy trust
- range stream/range response
- header policy
- cookie identity
- debug default
- coroutine-global state

Add regression tests first.

## Phase 2 — compiler/runtime foundation

Introduce:

- route IR
- deterministic route IDs
- compiled handler plans
- compiled middleware plans
- route capability masks
- explicit match outcomes
- strict compiled production artifact
- InterMix `^10.0.2`
- caller-owned `ContainerBuilder` contribution path
- explicit development `Container` boot path
- explicit generated `ProductionContainer` boot path
- coordinated Webrick/InterMix release artifact publication
- no environment-to-runtime inference

Wire Webrick so Foundation/Infbyte can own one application graph and delegate the selected InterMix runtime into Webrick.

## Phase 3 — direct dispatch path

Implement:

- routing preflight
- lazy Request
- conditional DI
- conditional scope
- direct closure invocation
- direct route-parameter invocation
- no-request success path
- InterMix request seeding only for plans that require scoped/injected context

This phase should produce the first major external benchmark jump.

## Phase 4 — matcher rewrite

Implement:

- generated static fast path
- shared routing semantics
- dynamic strategy benchmarks
- removal of runtime structural validation

## Phase 5 — HTTP representation

Implement:

- native string body
- header normalization once
- Response rewrite
- Request lazy materialization
- Uri component construction
- optional PSR bridge
- evaluate removing ArrayKit

## Phase 6 — persistent runtimes

Implement:

- Swoole/OpenSwoole runtime
- RoadRunner runtime
- Workerman runtime
- native streaming/file paths
- engine capability compilation
- delegated InterMix production runtime at worker bootstrap
- explicit per-request scopes where required
- concurrency/soak suite

## Phase 7 — middleware optimization

Optimize on the new runtime:

- compression
- cache validators
- response cache
- throttle
- maintenance
- telemetry
- negotiation
- request limits

## Phase 8 — final benchmark and deletion pass

Benchmark all old/new subsystems.

Delete:

- compatibility layers without measured value
- redundant matcher code
- unused bags/adapters
- duplicated negotiation logic
- unnecessary global state
- duplicate/hidden Webrick DI graph ownership
- implicit environment/runtime selection
- unnecessary Composer autoload files

Then release 5.0.0.

---

# 62. What Webrick 5 must deliberately not become

Do not turn Webrick into Hyperf.

Use Hyperf's lessons about:

- coroutine isolation
- worker lifecycle
- preboot initialization
- avoiding request-global state
- minimizing request lifecycle overhead

but keep Webrick runtime-neutral.

Do not:

- make Swoole mandatory
- require CacheLayer for core routing
- make every route PSR-7 internally
- route every handler through InterMix
- duplicate InterMix `10.0.2` production compilation inside Webrick
- create a second private container when the host supplied one
- infer development/production DI runtime from `APP_ENV` or the InterMix environment name
- compile an InterMix artifact during live request handling
- create a Request when the route cannot observe it
- create a stream for a tiny string
- create middleware machinery when none exists
- parse negotiation headers on routes without negotiation
- normalize method/host/header repeatedly
- validate trusted compiled route structures recursively on every request
- retain three matcher algorithms merely because they already exist
- trade HTTP correctness or coroutine safety for benchmark numbers

---

# 63. Target Webrick 5 request path

For the benchmark-style static route:

```text
native method/path
        ↓
compiled static matcher
        ↓
route id
        ↓
DIRECT_ZERO_ARG plan
        ↓
closure()
        ↓
string-backed Response
        ↓
native SAPI writer
```

The route must not pay for:

```text
full Request
Uri reconstruction
header extraction
php://input
uploaded files
InterMix scope
container Request binding
request clone
route-attribute aliases
Invoker
middleware pipeline
php://temp body
AutoEmitter engine detection
```

unless the selected route actually needs those capabilities.

For a complex DI-backed route in development:

```text
host composition root
        ↓
application ContainerBuilder + selected environment
        ↓
ContainerBuilder::development()
        ↓
Webrick development kernel
        ↓
routing preflight
        ↓
match / capability plan
        ↓
materialize required Request state
        ↓
withinScope() only when required
        ↓
dynamic InterMix invocation only when required
        ↓
Response
```

For the same route in production:

```text
build/deploy stage
    application ContainerBuilder + environment
        ├─ InterMix validate/compile → production artifact + manifest
        └─ Webrick compile          → router artifact

worker/process boot
    same application graph
        ↓
InterMix production()/productionPrevalidated()
        ↓
generated ProductionContainer
        ↓
Webrick compiled kernel receives that runtime

request
    routing preflight
        ↓
compiled match
        ↓
execution capability plan
        ↓
materialize required request portions
        ↓
withinScope() only when required
        ↓
compiled middleware
        ↓
InterMix generated invocation only when required
        ↓
Response
        ↓
runtime-native writer
```

The consuming framework is therefore free to own one environment/runtime policy and delegate it into Webrick without Webrick creating or guessing another policy.

---

# 64. Final Webrick 5 release definition

Webrick 5.0 is ready only when all of the following are true:

- protocol P0 issues are fixed
- no request-specific process-global state remains
- concurrent Swoole requests cannot contaminate one another
- production routing is artifact-driven and reflection-free
- dependency minimum is InterMix `^10.0.2`
- Webrick contributes to/consumes the application-owned InterMix graph rather than owning a hidden competing graph
- development uses the caller-selected InterMix dynamic `Container`
- production uses the caller-selected generated `ProductionContainer`
- environment selection is independent from development/production runtime selection
- Foundation/Infbyte or another consuming framework can explicitly delegate environment, runtime, artifact paths and prevalidation policy into Webrick
- Webrick never calls `setEnvironment()` or changes runtime mode implicitly behind the composition root
- InterMix and Webrick artifacts are built before traffic and published as one coordinated release set
- InterMix dynamic islands remain supported without deoptimizing unrelated compiled routes
- zero-argument routes bypass DI
- Request construction is lazy/capability-driven
- InterMix request scope is opened only when the compiled plan requires it
- InterMix scope seeds are used instead of per-request definition rebinding where contextual objects must be injected
- middleware is precompiled
- static routes do not pay dynamic-path segmentation
- generated/compiled matcher is included in the independent Benchmark
- ordinary string responses do not touch `php://temp`
- range handling is correct
- cache semantics conform to HTTP caching standards
- OPTIONS never executes business handlers
- conditional requests follow correct precondition precedence
- CORS/security defaults are conservative
- production debug defaults off
- persistent-worker dev/prod parity, concurrency and soak tests pass
- Webrick compiled reaches the agreed FastRoute-relative performance gate
- no measured regression is hidden behind architectural complexity

The defining architecture is simple:

> The consuming framework owns application composition and runtime policy. InterMix `10.0.2` owns DI development/production execution. Webrick owns routing and HTTP execution. More work happens once before traffic; far less work happens per request.

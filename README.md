# Webrick

A fast, framework-neutral HTTP routing kernel for PHP 8.4+ with deploy-time
compilation, persistent-runtime adapters, signed URLs, native response bodies,
and production-grade middleware.

[![Security & Standards](https://github.com/infocyph/Webrick/actions/workflows/security-standards.yml/badge.svg)](https://github.com/infocyph/Webrick/actions/workflows/security-standards.yml)
![Packagist Downloads](https://img.shields.io/packagist/dt/infocyph/webrick?color=green)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
![Packagist Version](https://img.shields.io/packagist/v/infocyph/webrick)
![Packagist PHP Version](https://img.shields.io/packagist/dependency-v/infocyph/webrick/php)

## Webrick 5 architecture

Webrick has two explicit planes:

- **Development/build plane** — `Registrar`, `RouteCompiler`, matcher-cache tools,
  reflection and route discovery are allowed here.
- **Compiled production plane** — `CompiledRouterKernel` consumes a verified
  Webrick artifact plus the **host-selected** InterMix `ProductionContainer`.

The application owns its InterMix graph. Webrick never silently selects a
container, imports application providers into a hidden graph, or infers a DI
runtime from an environment string.

### Highlights

- Three measured matcher modes: Fused as the general default, Generated as a low-thousands/simple-topology specialization, and Sharded for lazy cache residency.
- Explicit match outcomes for FOUND / 404 / 405 / automatic OPTIONS handling.
- Lazy request promotion and capability-driven compiled dispatch.
- Native string and file response bodies; stream objects only at interop boundaries.
- SAPI/CLI emitters plus dedicated Swoole/OpenSwoole, RoadRunner and Workerman runtime adapters.
- Request-local state and explicit InterMix scopes for persistent workers.
- Signed/temporary URLs, range requests, conditional responses and cache policy handling.
- Negotiation, compression, response cache, throttling, telemetry, request limits,
  CORS/security policies and optional cookie encryption.
- Optional PSR-7 and CacheLayer interoperability without making either part of the hot core.

## Requirements

- PHP 8.4+
- Composer 2.x
- InterMix `^10.0.3`

Install:

```bash
composer require infocyph/webrick
```

CacheLayer remains optional:

```bash
composer require infocyph/cachelayer
```

Use it only when an application chooses the CacheLayer-backed response-cache or
atomic-counter integration. Webrick core does not initialize CacheLayer.

## Development boot

The host creates the InterMix graph and passes an `Invoker` to the registrar
kernel:

```php
<?php

declare(strict_types=1);

use Infocyph\InterMix\DI\Invoker;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Emitter\DefaultEmitter;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Facade\Router as Route;
use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Infocyph\Webrick\Router\Matching\GeneratedMatcher;
use Infocyph\Webrick\Webrick;
use Psr\Log\NullLogger;

require __DIR__ . '/vendor/autoload.php';

$builder = Webrick::standaloneDevelopment();
// Register the application's services/providers on $builder here.
$container = $builder->development();
$invoker = Invoker::with($container);

$kernel = RouterKernel::bootWithRegistrar(
    log: new NullLogger(),
    matcher: GeneratedMatcher::make(),
    register: static function (Registrar $registrar): void {
        Route::get('/', static fn() => Response::plaintext('Hello Webrick', 200));
        Route::get('/users/{id:int}', static fn(string $id) => Response::json([
            'id' => (int) $id,
        ]), 'users.show');
    },
    invoker: $invoker,
);

$request = Request::fromGlobals();
(new DefaultEmitter())->emit($kernel->handle($request), $request);
```

`RouterKernel` is intentionally the development/registrar path. It always uses
request scoping and never stores the active request as a container singleton.

## Production compilation

Production uses coordinated Webrick + InterMix release artifacts. The host owns
one `ContainerBuilder`; Webrick contributes to that same graph rather than
creating a second one.

```php
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\Webrick\Router\Build\ReleaseCompiler;

$builder = ContainerBuilder::create('app');
// Register every application definition/provider before compilation.

$manifest = (new ReleaseCompiler())->compile(
    builder: $builder,
    register: $register,
    environment: 'production',
    configFingerprint: $configFingerprint,
    intermixPath: __DIR__ . '/var/intermix.php',
    routerPath: __DIR__ . '/var/webrick.php',
    releaseManifestPath: __DIR__ . '/var/release.json',
    registrarOptions: $registrarOptions,
    preGlobal: $preGlobal,
    postGlobal: $postGlobal,
);
```

`ReleaseCompiler` publishes both the JSON tooling manifest and an OPcache-friendly
PHP runtime manifest next to it. Deploy the InterMix artifact, Webrick artifact
and both manifests as one immutable release set, then replace workers only after
the full set is available.

## Compiled production boot

The host recreates the same builder configuration, loads the coordinated release
metadata, chooses the InterMix production runtime, and gives it to Webrick
explicitly:

```php
use Infocyph\Webrick\Response\Emitter\DefaultEmitter;
use Infocyph\Webrick\Router\Build\ReleaseManifestLoader;
use Infocyph\Webrick\Router\Kernel\CompiledRouterKernel;
use Infocyph\Webrick\Router\Matching\GeneratedMatcher;
use Psr\Log\NullLogger;

$release = (new ReleaseManifestLoader())->load(__DIR__ . '/var/release.json');

$container = $builder->productionPrevalidated(
    $release['intermix']['path'],
    $release['intermix']['digest'],
);

$kernel = CompiledRouterKernel::fromPrevalidatedArtifact(
    log: new NullLogger(),
    matcher: GeneratedMatcher::make(),
    container: $container,
    artifactPath: $release['webrick']['path'],
    trustedArtifactFingerprint: $release['webrick']['fingerprint'],
    environment: $release['environment'],
    configFingerprint: $release['config_fingerprint'],
);

$response = $kernel->handle();
(new DefaultEmitter())->emit($response);
```

`ReleaseManifestLoader` prefers the generated PHP runtime manifest and falls back
to JSON when the PHP manifest is not present. The InterMix `digest`, Webrick
`digest`, and Webrick artifact `fingerprint` are xxh128 deployment identities;
there is no SHA-256 release-metadata compatibility path in Webrick 5.1.

For standalone compiled SAPI applications, prefer `handle()` without eagerly
constructing `Request::fromGlobals()`. `CompiledRouterKernel` promotes globals to
a full `Request` only when the matched execution plan actually needs one. The
synchronous emitter also accepts a nullable request and falls back to SAPI globals
for request-method-sensitive emission such as HEAD.

Pass an explicit Webrick `Request` when the host already owns/adapts the request
or when application code requires it. Use `fromCompiledArtifact()` instead when
a trusted external artifact fingerprint is not available. Prevalidated mode is
only appropriate when release metadata comes from an immutable deployment
boundary outside runtime-writable artifact paths.

## Persistent runtimes

Swoole/OpenSwoole, RoadRunner and Workerman use `RuntimeAdapterInterface` and
`RuntimeServer`. Runtime choice is explicit at worker bootstrap; Webrick does
not perform per-request environment or extension discovery.

Runtime adapters own native request/response handles, transport compression,
request-size capabilities, streaming and sendfile behavior. Request state is
never stored in static current-request/current-response fields.

Classic synchronous SAPIs use `DefaultEmitter`; CLI uses `CliEmitter`. If a host
framework owns response emission, return/adapt the Webrick response instead of
emitting it twice.

## Matcher cache tooling

`route:cache` now means **matcher cache only**. It is a build-plane optimization
and does not boot a request kernel or DI container:

```bash
php ./webrick route:cache --matcher=generated --cache=.route-cache/generated.php --routes=routes.php
php ./webrick route:cache --matcher=fused --cache=.route-cache/fused.php --routes=routes.php
php ./webrick route:cache --matcher=sharded --cache=.route-cache --routes=routes.php
```

Clear matcher cache artifacts with:

```bash
php ./webrick route:clear --matcher=sharded --cache=.route-cache --aggressive=1
```

Compiled production release artifacts are a separate concern and are produced
through `ReleaseCompiler`.

## URL generation

```php
use DateTimeImmutable;
use Infocyph\Webrick\Router\Facade\Router as Route;
use Infocyph\Webrick\Router\Url\SignedUrlConfig;

$url = Route::urlFor('users.show', ['id' => 42]);
$signed = Route::signedUrlFor('users.show', ['id' => 42]);
$temp = Route::temporaryUrlFor('users.show', ['id' => 42], ttl: 900);
$until = Route::temporaryUrlUntil(
    'users.show',
    new DateTimeImmutable('+15 minutes'),
    ['id' => 42],
);

$absolute = Route::signedUrlFor(
    'users.show',
    ['id' => 42],
    absolute: true,
    payloadMode: SignedUrlConfig::MODE_ABSOLUTE,
);
```

Signing and verification share deterministic URI/query normalization. Key
rotation, ignored query parameters, leeway and absolute/relative payload modes
are configured through `SignedUrlConfig`.

## Middleware

Compiled production middleware is resolved and prepared before traffic. Empty
middleware routes retain a direct zero-pipeline path. Direct closures/functions
avoid DI scope work when their compiled execution plan does not require it.

Input sanitization is explicit transformation, not blanket security. Cookie
encryption, OpenTelemetry and CacheLayer-backed features remain optional.
`ResponseLinterMiddleware` is development/test tooling and should not be
registered in production.

## Interoperability

Webrick's native `Request` and `Response` are optimized internal HTTP types. PSR
interop is explicit through `Interop\Psr7`; installing PSR HTTP interfaces and a
factory is optional.

When embedding Webrick in another framework:

```php
$webrickRequest = $requestAdapter->toWebrick($frameworkRequest);
$webrickResponse = $kernel->handle($webrickRequest);

return $responseAdapter->fromWebrick($webrickResponse);
```

The host remains responsible for its application lifecycle, container,
configuration, logging and final response emission.

## Benchmarks

- `benchmark/` contains the standalone matcher/control runner.
- `benchmarks/` contains structured PhpBench benchmarks for matcher, dispatch,
  cache, ETag and signed-URL behavior.

Use same-run ratios against controls and judge sustainable successful full HTTP
throughput, not isolated microbenchmark wins.

## Security

Do not disclose suspected vulnerabilities in a public issue, discussion or pull
request. Follow [SECURITY.md](SECURITY.md) and use GitHub private vulnerability
reporting.

Webrick uses PHPForge for automated tests, static/taint analysis, dependency
auditing, architecture checks and release-readiness gates.

## Documentation

Full documentation: https://docs.infocyph.com/projects/webrick/

Webrick is MIT licensed. See [LICENSE](LICENSE).

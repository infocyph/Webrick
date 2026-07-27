# Route Cache and Warmup

Build route artifacts ahead of time so production requests do not scan
attributes, compile routes, or write cache files. Cache build time may be
slower when that produces a smaller and more predictable request path.

## Choose an artifact

| Mode | Cache path | Artifact shape |
| --- | --- | --- |
| `sharded` | Directory | Root, alias, and route shard PHP files |
| `fused` | File | One PHP routing data file |
| `generated` | File | One PHP file containing generated matching code |

Start with sharded mode. Choose fused when a single file simplifies artifact
publication. Evaluate generated mode with representative routes and production
runtime settings.

## Build in CI or deployment

```bash
vendor/bin/webrick route:cache \
  --matcher=sharded \
  --cache=var/cache/webrick/routes \
  --routes=routes/web.php
```

Equivalent one-file builds:

```bash
vendor/bin/webrick route:cache \
  --matcher=fused \
  --cache=var/cache/webrick/routes-fused.php \
  --routes=routes/web.php

vendor/bin/webrick route:cache \
  --matcher=generated \
  --cache=var/cache/webrick/routes-generated.php \
  --routes=routes/web.php
```

A package checkout can use `php ./webrick`; an installed dependency exposes
`vendor/bin/webrick`.

## Boot from the same path

```php
$routeCache = __DIR__ . '/../var/cache/webrick/routes';

$kernel = RouterKernel::bootWithRegistrar(
    log: $logger,
    matcher: ShardedMatcher::make(),
    register: static function (Registrar $registrar): void {
        require __DIR__ . '/../routes/web.php';
    },
    routeCache: $routeCache,
    registrarOptions: [
        'signKey' => $_ENV['WEBRICK_SIGN_KEY'] ?? null,
        'signedDefaultTtl' => 900,
        'urlBaseUri' => $_ENV['APP_URL'] ?? '',
    ],
);
```

Use `FusedMatcher::make()` or `GeneratedMatcher::make()` and its exact build
file for the other modes. Matcher factories are always zero-argument.

The registrar callback remains required as the cold-path definition source.
With a valid artifact, the matcher boots from cache and does not rebuild its
route table.

## Programmatic build

Use the API when the build requires application-owned closures, attribute
inputs, middleware metadata, or a custom logger:

```php
use Infocyph\Webrick\Support\RouteCache;

$artifact = RouteCache::build([
    'matcher' => 'sharded',
    'cache' => __DIR__ . '/../var/cache/webrick/routes',
    'register' => static function (Registrar $registrar): void {
        require __DIR__ . '/../routes/web.php';

        AttributeRouteLoader::registerFromDirs($registrar, [
            'App\\Http\\' => __DIR__ . '/../src/Http',
        ]);
    },
    'signKey' => $_ENV['WEBRICK_SIGN_KEY'] ?? null,
    'signedDefaultTtl' => 900,
    'urlBaseUri' => $_ENV['APP_URL'] ?? '',
    'fallbackAliasesFromRegistrar' => true,
    'logger' => $logger,
]);
```

The builder clears only the selected matcher output before rebuilding. Named
fused and generated files can coexist with sharded artifacts in one parent
directory.

## Build and runtime parity

Keep these inputs equivalent:

- route files and programmatic registration;
- attribute directories and classes;
- handler and middleware descriptors;
- automatic slash behavior;
- signed URL key, default TTL, configuration, and base URI;
- alias fallback behavior.

Class handlers and string middleware generate the leanest artifacts. Closure or
object-backed routes remain supported through the serializer fallback.

## Publish atomically

A safe deployment sequence is:

1. Install the exact locked dependencies for the release.
2. Build route artifacts in a release-local cache path.
3. Run route and application tests against those artifacts.
4. Mark the artifacts read-only for the web process when practical.
5. Switch the release symlink or image atomically.
6. Restart or reload persistent workers so they use matching code and cache.

Do not overwrite the cache currently used by live workers one file at a time.

## Clear

```bash
vendor/bin/webrick route:clear \
  --matcher=sharded \
  --cache=var/cache/webrick/routes
```

Normal sharded clear removes known Webrick PHP artifacts. Aggressive sharded
clear recursively removes entries below the cache directory but preserves a
root `.gitignore`:

```bash
vendor/bin/webrick route:clear \
  --matcher=sharded \
  --cache=var/cache/webrick/routes \
  --aggressive=1
```

For fused or generated mode, pass the exact file and mode.

## Permissions

The deployment user needs write access while building or clearing. The runtime
web user only needs read access when live cache generation is disabled. This
separation prevents a web request from changing executable cache artifacts.

Do not solve ownership problems with world-writable permissions. Build in a
release directory owned by the deploy process, then grant the runtime identity
read and traversal access.

## Failure policy

Treat cache build failure as deployment failure. Do not fall through to a live
request rebuild in production.

Verify at least:

- the artifact exists at the configured path;
- runtime PHP and required extensions match the build environment;
- the web or worker identity can read every artifact;
- a representative static route, dynamic route, method rejection, and named URL
  work from cache;
- persistent workers restart after publication.

Route caches are executable trusted PHP. Build them only from trusted
application definitions and never load user-provided artifacts.

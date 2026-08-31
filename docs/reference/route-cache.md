# Matcher Cache Reference

`RouteCache` builds matcher cache artifacts only. It is a build-plane optimization for `GeneratedMatcher`, `FusedMatcher`, or `ShardedMatcher`; it does not boot `RouterKernel`, create URL services, or initialize an InterMix runtime.

The complete production application artifact is a separate concern handled by `RouteCompiler`, `RouterArtifactCompiler`, and `ReleaseCompiler`.

## Modes

| Matcher | Cache location | Output | Intended role |
| --- | --- | --- | --- |
| `fused` | PHP file | compact fused matcher data | default baseline for normal applications |
| `generated` | PHP file | generated matcher code/data | throughput specialization after benchmark validation |
| `sharded` | directory | immutable generation + manifest/shards | very large route sets where working-set or memory reduction is measurable |

Prefer an explicit `matcher` value in deployment tooling. Start from `fused` unless representative production-like measurements justify one of the specialized modes.

Generated should be selected for a demonstrated matcher-throughput advantage after accounting for generated artifact size, OPcache footprint and worker boot cost. Sharded should be selected when a very large route table benefits measurably from loading relevant route groups rather than keeping one consolidated routing structure in the worker; include first-use shard loading, filesystem behavior and worker lifetime in that comparison.

## PHP API

```php
use Infocyph\Webrick\Support\RouteCache;

$artifact = RouteCache::build([
    'matcher' => 'fused',
    'cache' => __DIR__ . '/../.route-cache/fused.php',
    'routes' => __DIR__ . '/../routes.php',
    'attributeDirs' => [
        'App\\Http\\' => __DIR__ . '/../src/Http',
    ],
]);
```

Supported build options:

| Key | Meaning |
| --- | --- |
| `matcher` | `generated`, `fused`, or `sharded` |
| `cache` | required output file/directory |
| `routes` | route file; use this or `register` |
| `register` | registration callable; use this or `routes` |
| `registrarOptions` | build-time registrar options such as `autoSlashRedirect` |
| `signKey` | optional build-time signing key exposed to legacy route files |
| `signedDefaultTtl` | optional registrar signing default |
| `signedUrlConfig` | optional `SignedUrlConfig`/array |
| `urlBaseUri` | optional registrar base URI |
| `attributeDirs` | namespace → directory attribute-discovery map |
| `attributeClasses` | explicit attribute route classes |
| `logger` | optional PSR-3 build logger |

There are no runtime middleware, alias-fallback, DI-container, or URL-service binding options. Those concerns do not belong to matcher-cache generation.

## CLI

```bash
php ./webrick route:cache --matcher=fused --cache=.route-cache/fused.php --routes=routes.php
php ./webrick route:cache --matcher=generated --cache=.route-cache/generated.php --routes=routes.php
php ./webrick route:cache --matcher=sharded --cache=.route-cache --routes=routes.php
```

Optional CLI inputs include `--signkey`, `--ttl`, `--attr-dirs`, and `--attr-classes`.

## Build flow

Matcher-cache generation is intentionally simple:

1. create a build-only `Registrar` and `Collection`;
2. execute the route registration input inside a scoped `Router` facade binding;
3. compile route definitions once;
4. feed the compiled routes into the selected matcher;
5. enable cache-write mode and finalize the matcher;
6. atomically publish the matcher's cache format.

No request kernel, request scope, controller invocation, middleware pipeline, application container, or response emitter is created.

## Cache contents

Depending on matcher mode, cache artifacts contain the matcher structures required to reconstruct routing state, including route descriptors, alias metadata, middleware alias requirements, constraints and generated matching code.

They do not contain application service instances, current request state, resolved middleware objects, native runtime handles, or an InterMix runtime.

## Clearing

```bash
php ./webrick route:clear --matcher=fused --cache=.route-cache/fused.php
php ./webrick route:clear --matcher=generated --cache=.route-cache/generated.php
php ./webrick route:clear --matcher=sharded --cache=.route-cache
```

For sharded cache, `--aggressive=1` recursively clears generated artifacts while preserving the root `.gitignore`.

## Production release artifacts

Do not confuse matcher cache with the strict Webrick production artifact. `ReleaseCompiler` coordinates:

- the host-owned InterMix compiled runtime;
- Webrick compiled routes;
- execution plans and capabilities;
- route aliases;
- global middleware descriptors/tags;
- environment and configuration fingerprints;
- a release manifest containing trusted artifact digests.

`CompiledRouterKernel` consumes that release artifact with the host-selected `ProductionContainer`.

## Deployment rules

- Build artifacts during CI/deployment, never during ordinary requests.
- Treat generated PHP cache/artifact files as trusted executable deployment data.
- Publish complete release sets atomically from the deployment layer.
- Keep runtime artifacts read-only to serving workers where possible.
- Rebuild matcher caches and production release artifacts after a Webrick major upgrade or route-schema change.

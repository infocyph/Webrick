Route Cache Warmup
==================

Matcher cache is a deploy-time optimization. Build it before traffic so request handling does not need to discover or persist routing structure on the hot path.

Matcher cache is not the compiled production application artifact. The strict production runtime is built separately through ``RouteCompiler`` / ``ReleaseCompiler`` and booted with ``CompiledRouterKernel`` plus the application-owned InterMix ``ProductionContainer``.

CLI warmup
----------

Use the bundled command for a route file that performs registration:

.. code:: bash

   php ./webrick route:cache \
       --matcher=fused \
       --cache=.route-cache/fused.php \
       --routes=routes.php

Supported matcher modes are ``fused``, ``generated`` and ``sharded``. Use the matcher selected by measurement for the deployed route set; ``fused`` is the general production default.

Development runtime
-------------------

Do not pass matcher-cache paths into ``RouterKernel``. The registrar kernel receives a matcher instance and a host-owned InterMix ``RuntimeContainerInterface``:

.. code:: php

   use Infocyph\Webrick\Router\Definition\Registrar;
   use Infocyph\Webrick\Router\Kernel\RouterKernel;
   use Infocyph\Webrick\Router\Matching\FusedMatcher;

   $kernel = RouterKernel::bootWithRegistrar(
       log: $logger,
       matcher: FusedMatcher::make(),
       register: static function (Registrar $registrar): void {
           require __DIR__ . '/../routes/web.php';
       },
       invoker: $invoker,
       registrarOptions: [
           'urlBaseUri' => $_ENV['APP_URL'] ?? '',
       ],
   );

Matcher cache generation remains independent of this request kernel.

Build through the API
---------------------

Use ``RouteCache::build()`` when build-time registration requires application-owned closures, attribute inputs, signing metadata or a custom logger:

.. code:: php

   use Infocyph\Webrick\Router\Attributes\AttributeRouteLoader;
   use Infocyph\Webrick\Router\Definition\Registrar;
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
       'logger' => $logger,
   ]);

The builder accepts either a ``register`` callable or a ``routes`` file, plus optional attribute directories/classes and registrar/signing inputs. Unknown options are not part of the contract and should not be used.

The builder stages and validates the selected matcher output before activating it. Named fused and generated files can coexist with sharded artifacts in one parent directory.

Route-first production graph enrichment
---------------------------------------

Hosts that need route-referenced controllers or middleware in the InterMix graph should use ``ReleaseCompiler::compile(..., enrichGraph: ...)``. Webrick discovers and compiles the route table first, passes that exact ``RouterBuildResult`` to the host, then validates and compiles InterMix and emits the router artifact from the same result.

.. code:: php

   use Infocyph\InterMix\DI\ContainerBuilder;
   use Infocyph\Webrick\Router\Build\ReleaseCompiler;
   use Infocyph\Webrick\Router\Build\RouterBuildResult;

   $manifest = (new ReleaseCompiler())->compile(
       builder: $builder,
       register: $registerRoutes,
       environment: 'production',
       configFingerprint: $configFingerprint,
       intermixPath: $containerArtifact,
       routerPath: $routerArtifact,
       releaseManifestPath: $releaseManifest,
       enrichGraph: static function (ContainerBuilder $builder, RouterBuildResult $routes): void {
           // Inspect $routes->plans / $routes->routes and add host-owned
           // controller or middleware definitions before strict validation.
       },
   );

The registration callback runs once. Do not invoke route discovery again from ``enrichGraph``; the supplied build result is the source of truth for graph enrichment and router artifact generation.

Build and runtime parity
------------------------

Keep the routing inputs used for build and runtime equivalent:

- route files and programmatic registration;
- attribute directories and classes;
- handler and middleware descriptors;
- automatic slash behavior supplied through ``registrarOptions`` when relevant;
- signed URL key, TTL/configuration and base URI where those values affect compiled route metadata.

Do not invent compatibility/fallback options at cache build time. Webrick 5 deliberately has no legacy alias-fallback switch.

Safe deployment
---------------

Generate matcher cache into the release/staging location, validate it, then activate the release. Do not delete a currently active artifact before a replacement has been successfully built.

For ``sharded`` mode, the cache path is a directory and the returned artifact is its ``__manifest.php``. Fused/generated modes return the generated cache file path.

Compiled artifact trust boundary
-------------------------------

Compiled route and DI artifacts are executable PHP. Deploy them only from a
trusted, immutable release directory owned by the deployment operator; runtime
workers should not have permission to rewrite those files or the manifest.
Build and activate artifacts atomically, then drain old workers before deleting
an active generation.

``ReleaseCompiler`` writes SHA-256 byte digests of both the compiled InterMix
artifact and the Webrick router artifact to its coordinated release manifest.
``ReleaseArtifactLoader`` checks those digests before the PHP artifacts are
loaded. This prevents a modified artifact from being executed when its trusted
release manifest is still intact. Protect the manifest and its deployment
directory as part of the same release: the manifest is **not** signed, and
``release_fingerprint`` is a consistency fingerprint, not a MAC.

For compatibility, older manifests without ``source_sha256`` use their
``xxh128`` digest. Likewise, standalone ``RouterArtifactLoader`` validates
a sidecar ``xxh128`` checksum. These checks detect accidental change but
**do not authenticate untrusted PHP** if an adversary can also update the
manifest or sidecar. Do not load untrusted shared-cache directories or other
tenant-writable PHP. The release manifest must come from a trusted build and
activation process; externally provided filenames, user content and remote
URLs are never authority to load executable PHP.

Rewriting artifact bytes between digest verification and ``require`` is
outside Webrick's application-level integrity guarantee. Filesystem
ownership, immutable generations, and worker restart on deployment close
that operational gap.

Production release
------------------

After matcher tooling, compile the full production graph with ``ReleaseCompiler``. That coordinated release artifact binds the Webrick execution plan to the application-owned compiled InterMix runtime and release manifest. Production traffic should boot ``CompiledRouterKernel`` from that verified artifact rather than rebuilding route definitions or DI state.

See :doc:`../reference/route-cache` for the cache and compiled-artifact reference.

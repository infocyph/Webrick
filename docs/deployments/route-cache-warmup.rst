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

Do not pass matcher-cache paths into ``RouterKernel``. The registrar kernel receives a matcher instance and a host-owned InterMix ``Invoker``:

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

Production release
------------------

After matcher tooling, compile the full production graph with ``ReleaseCompiler``. That coordinated release artifact binds the Webrick execution plan to the application-owned compiled InterMix runtime and release manifest. Production traffic should boot ``CompiledRouterKernel`` from that verified artifact rather than rebuilding route definitions or DI state.

Route-first graph enrichment
----------------------------

Hosts that need route-referenced controller or middleware definitions can enrich the InterMix graph without discovering routes twice. ``ReleaseCompiler`` finalizes the ``RouterBuildResult`` first, exposes it to ``enrichGraph``, then performs strict InterMix validation and compilation.

.. code:: php

   use Infocyph\InterMix\DI\ContainerBuilder;
   use Infocyph\Webrick\Router\Build\ReleaseCompiler;
   use Infocyph\Webrick\Router\Build\RouterBuildResult;

   $manifest = (new ReleaseCompiler())->compile(
       builder: $builder,
       register: $registerRoutes,
       environment: 'production',
       configFingerprint: $configFingerprint,
       intermixPath: $releaseDir . '/container.php',
       routerPath: $releaseDir . '/router.php',
       releaseManifestPath: $releaseDir . '/release.json',
       enrichGraph: static function (
           ContainerBuilder $builder,
           RouterBuildResult $routes,
       ): void {
           AppRouteGraph::contribute($builder, $routes);
       },
   );

The callback runs exactly once after route compilation and before ``ContainerBuilder::validate(strict: true)`` / ``compile()``. It receives finalized route and execution-plan descriptors, so host code can contribute deterministic DI definitions based on what the release actually references.

Keep the callback build-only and deterministic. It should mutate the host ``ContainerBuilder`` only; do not rediscover route files, mutate the compiled route result, resolve runtime services, or perform request-time work there.

See :doc:`../reference/route-cache` for the cache and compiled-artifact reference.

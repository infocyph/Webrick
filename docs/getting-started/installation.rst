Installation
============

Webrick 5 requires PHP 8.4+ and Composer 2.x.

Install
-------

.. code:: bash

   composer require infocyph/webrick

Core runtime dependencies are intentionally small:

- PHP 8.4+
- InterMix ``^10.0.2``
- PSR cache/log contracts used by portable integrations

ArrayKit is not a mandatory Webrick dependency. CacheLayer, PSR-7 interfaces/factories, OpenTelemetry SDK/exporters and persistent-server packages remain optional.

Suggested application layout
----------------------------

.. code:: text

   your-app/
   ├─ public/
   │  └─ index.php
   ├─ routes/
   │  └─ web.php
   ├─ src/
   ├─ var/
   │  ├─ cache/
   │  └─ release/
   └─ vendor/

The host application owns configuration, its InterMix ``ContainerBuilder``, deployment paths and runtime selection.

Development bootstrap
---------------------

.. code:: php

   use Infocyph\InterMix\DI\Invoker;
   use Infocyph\Webrick\Response\Response;
   use Infocyph\Webrick\Router\Definition\Registrar;
   use Infocyph\Webrick\Router\Facade\Router as Route;
   use Infocyph\Webrick\Router\Kernel\RouterKernel;
   use Infocyph\Webrick\Router\Matching\GeneratedMatcher;
   use Infocyph\Webrick\Webrick;
   use Psr\Log\NullLogger;

   $builder = Webrick::standaloneDevelopment();
   $invoker = Invoker::with($builder->development());

   $kernel = RouterKernel::bootWithRegistrar(
       log: new NullLogger(),
       matcher: GeneratedMatcher::make(),
       register: static function (Registrar $registrar): void {
           Route::get('/health', static fn() => Response::json(['ok' => true]));
       },
       invoker: $invoker,
   );

A framework/real application should normally supply its existing builder rather than use the standalone development convenience.

Optional integrations
---------------------

Install only what the application actually uses. Examples:

.. code:: bash

   composer require infocyph/cachelayer
   composer require psr/http-message psr/http-factory

Persistent server engines and OpenTelemetry packages are also application choices; Webrick core does not force them into ordinary SAPI installations.

Signing and cookie keys
-----------------------

Keep secrets in the host configuration/secrets system. Signed URLs use ``SignedUrlConfig``; cookie encryption is optional and requires correctly sized keys. Do not hard-code production secrets in route files or cache artifacts.

Matcher cache directory
-----------------------

``webrick route:cache`` creates matcher cache only. The deploy user needs write access during the build/publish step; serving workers should normally receive read-only artifacts.

.. code:: bash

   php ./webrick route:cache \
     --matcher=generated \
     --cache=var/cache/webrick/generated.php \
     --routes=routes/web.php

Do not make the runtime web user broadly writable merely to support cache generation. Build artifacts during deployment.

Production release
------------------

Use ``Router\Build\ReleaseCompiler`` to create the coordinated InterMix + Webrick release artifacts and manifest, then boot ``CompiledRouterKernel`` with the host-selected ``ProductionContainer``.

See:

- `Quick Start <quickstart.md>`__
- `Framework Integration <framework-integration.md>`__
- `Matcher Cache Reference <../reference/route-cache.rst>`__
- `Deployments <../deployments/index.rst>`__

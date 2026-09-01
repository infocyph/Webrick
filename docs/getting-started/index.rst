Getting Started
===============

Start here for the current Webrick development bootstrap and the boundary between development routing, matcher cache tooling and compiled production.

What you'll set up
------------------

1. Install Webrick with Composer.
2. Build or reuse an application-owned InterMix graph.
3. Give ``RouterKernel`` the host ``Invoker``.
4. Register routes through ``Infocyph\Webrick\Router\Facade\Router``.
5. Configure URL/signing services when needed.
6. Compile matcher cache and the production application artifact as separate deployment concerns.

Install
-------

.. code:: bash

   composer require infocyph/webrick

Development bootstrap
---------------------

For a standalone development application:

.. code:: php

   use Infocyph\InterMix\DI\Invoker;
   use Infocyph\Webrick\Request\Request;
   use Infocyph\Webrick\Response\Emitter\DefaultEmitter;
   use Infocyph\Webrick\Response\Response;
   use Infocyph\Webrick\Router\Definition\Registrar;
   use Infocyph\Webrick\Router\Facade\Router as Route;
   use Infocyph\Webrick\Router\Kernel\RouterKernel;
   use Infocyph\Webrick\Router\Matching\FusedMatcher;
   use Infocyph\Webrick\Webrick;
   use Psr\Log\NullLogger;

   $builder = Webrick::standaloneDevelopment();
   $container = $builder->development();
   $invoker = Invoker::with($container);

   $kernel = RouterKernel::bootWithRegistrar(
       log: new NullLogger(),
       matcher: FusedMatcher::make(),
       register: static function (Registrar $registrar): void {
           unset($registrar);
           Route::get('/', static fn() => Response::plaintext('Hello Webrick', 200), 'home');
       },
       invoker: $invoker,
   );

   $request = Request::fromGlobals();
   (new DefaultEmitter())->emit($kernel->handle($request), $request);

A framework integration should reuse its existing ``ContainerBuilder`` and create the ``Invoker`` from that application-owned graph instead of calling ``standaloneDevelopment()``.

URL services
------------

Expose URL services through registrar options when named URL generation/signing is required. Prefer an explicit ``SignedUrlConfig`` for signing keys, verification keys and TTL policy. See :doc:`../guides/urls` for the complete setup.

Matcher cache
-------------

Matcher cache can be built independently of a request kernel:

.. code:: bash

   php ./webrick route:cache --matcher=fused --cache=.route-cache/fused.php --routes=routes.php

This prepares matcher state only. It is **not** the compiled production application artifact.

Production
----------

Use ``RouteCompiler`` / ``ReleaseCompiler`` to compile Webrick execution plans together with the application-owned InterMix production runtime, then boot ``CompiledRouterKernel`` with the host-selected ``ProductionContainer``.

Next steps
----------

- :doc:`quickstart` — fuller standalone development bootstrap, aliases and signed URLs.
- :doc:`framework-integration` — embedding Webrick in an application/framework-owned runtime.
- :doc:`../guides/routing` — route registration and grouping.
- :doc:`../guides/urls` — URL generation and signing.
- :doc:`../reference/route-cache` — matcher cache versus compiled production artifacts.
- :doc:`../reference/router` — development and production kernel reference.

.. toctree::
   :maxdepth: 2
   :hidden:
   :caption: Getting Started

   installation
   quickstart
   framework-integration

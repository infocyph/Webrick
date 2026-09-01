Webrick Documentation
=====================

Webrick is a framework-neutral HTTP routing kernel for PHP 8.4+. Use it as a standalone front controller, a routed sub-application inside another framework, or behind a persistent worker/runtime.

What you get
------------

- Routing: named routes, groups, domains, resources, constraints and attribute discovery.
- Matchers: Fused as the general production default, Generated for benchmark-proven simple-route wins into the low thousands, and Sharded for large cold-start/working-set tradeoffs.
- Signed URLs: relative or absolute signing, TTL or explicit expiry, ignored query parameters and key rotation.
- Error boundary: typed HTTP exceptions, negotiated error rendering and an optional process-level ``PhpErrorBridge``.
- Middleware: explicit pre-global/post-global stacks, aliases, tagged application middleware and compiled execution plans.
- Responses: JSON, plaintext, redirects, streaming, ranged files/downloads and views.
- Runtime boundaries: synchronous emitters, explicit interoperability bridges and compiled production kernels for long-lived workers.
- DI lifecycle: the application owns InterMix; Webrick receives the host ``Invoker`` in development and the host ``ProductionContainer`` in compiled production.
- Deployment artifacts: matcher caches are independent from the strict compiled Webrick + InterMix production release artifact.

Install
-------

.. code:: bash

   composer require infocyph/webrick

Development boot
----------------

``RouterKernel`` is the registrar/development kernel. The host owns the InterMix graph and gives Webrick an ``Invoker``.

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

Framework integrations should contribute Webrick services to their existing application-owned ``ContainerBuilder`` rather than creating a standalone builder.

Production runtime
------------------

Production does **not** boot the registrar kernel from a matcher cache. Compile the Webrick execution artifact together with the application-owned InterMix runtime using ``RouteCompiler`` / ``ReleaseCompiler`` and boot ``CompiledRouterKernel`` with the host-selected ``ProductionContainer``.

Matcher cache remains useful as a deploy-time matcher optimization, but it is not the complete production application artifact.

Signed URL example
------------------

.. code:: php

   use Infocyph\Webrick\Router\Facade\Router as Route;

   $href = Route::temporaryUrlFor('file.download', ['file' => 'report.pdf'], ttl: 900);

Operational notes
-----------------

- Compile production artifacts during build/deploy rather than on request paths.
- Instantiate kernels once per application/worker lifecycle.
- Register middleware aliases before route compilation when string aliases are used.
- Configure a stable signing key and ``urlBaseUri`` when absolute signed URLs are required.
- Preserve query strings at proxy/gateway layers because signed URL verification depends on canonical query data.
- When another framework owns response emission, adapt/return the Webrick response instead of emitting it directly.
- Persistent workers should keep all request-local state on the ``Request``/request scope; Webrick intentionally avoids process-global current-request state.

Where to start
--------------

- `Getting Started <./getting-started/index.rst>`__
- `Framework Integration <./getting-started/framework-integration.rst>`__
- `Routing <./guides/routing.rst>`__
- `Middleware <./middleware/index.rst>`__
- `Error Rendering <./guides/error-rendering.rst>`__
- `Matcher Reference <./reference/matcher.rst>`__
- `Route Cache and Production Artifacts <./reference/route-cache.rst>`__
- `Response Emitters <./reference/emitters.rst>`__

.. toctree::
   :maxdepth: 2
   :hidden:
   :caption: Contents

   getting-started/index
   guides/index
   middleware/index
   deployments/index
   reference/index
   recipes/index
   advanced/performance
   advanced/security
   advanced/testing

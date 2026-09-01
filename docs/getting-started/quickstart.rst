Quick Start
===========

This quick start uses the Webrick 5 development boundary: the application owns InterMix and gives Webrick an ``Invoker``. Production compilation is a separate step.

1. Build the application graph
------------------------------

.. code:: php

   use Infocyph\InterMix\DI\Invoker;
   use Infocyph\Webrick\Webrick;

   $builder = Webrick::standaloneDevelopment();
   // Add application providers/definitions to $builder here.
   $container = $builder->development();
   $invoker = Invoker::with($container);

A framework integration should use its existing application-owned ``ContainerBuilder`` instead of ``standaloneDevelopment()``.

2. Register routes and boot development
---------------------------------------

.. code:: php

   use Infocyph\Webrick\Request\Request;
   use Infocyph\Webrick\Response\Emitter\DefaultEmitter;
   use Infocyph\Webrick\Response\Response;
   use Infocyph\Webrick\Router\Definition\Registrar;
   use Infocyph\Webrick\Router\Facade\Router as Route;
   use Infocyph\Webrick\Router\Kernel\RouterKernel;
   use Infocyph\Webrick\Router\Matching\GeneratedMatcher;
   use Psr\Log\NullLogger;

   $kernel = RouterKernel::bootWithRegistrar(
       log: new NullLogger(),
       matcher: GeneratedMatcher::make(),
       register: static function (Registrar $registrar): void {
           Route::get('/ping', static fn() => Response::plaintext('pong', 200), 'ping');
           Route::get('/hello/{name}', static fn(string $name) => Response::json([
               'hello' => $name,
           ]), 'hello');
       },
       invoker: $invoker,
   );

   $request = Request::fromGlobals();
   (new DefaultEmitter())->emit($kernel->handle($request), $request);

``RouterKernel`` is registrar/development-only. It always enters a request scope and never creates or selects an InterMix container itself.

3. Middleware aliases
---------------------

.. code:: php

   use Infocyph\Webrick\Middleware\ThrottleMiddleware;
   use Infocyph\Webrick\Router\Dispatch\MiddlewareAliases;

   MiddlewareAliases::register(
       'throttle',
       static fn(...$params) => new ThrottleMiddleware(
           (int) ($params[0] ?? 60),
           (int) ($params[1] ?? 60),
       ),
   );

   Route::get('/limited', static fn() => Response::json(['ok' => true]), [
       'middleware' => ['throttle:30,60'],
   ]);

Register aliases before route compilation. Production resolves/validates the supported middleware graph before traffic.

4. Signed URLs
--------------

Configure URL services through registrar options:

.. code:: php

   use Infocyph\Webrick\Router\Url\SignedUrlConfig;

   $signed = new SignedUrlConfig(
       generationKey: $_ENV['WEBRICK_SIGN_KEY'],
       verificationKeys: [$_ENV['WEBRICK_SIGN_KEY']],
       defaultTtl: 900,
   );

   $kernel = RouterKernel::bootWithRegistrar(
       log: $logger,
       matcher: GeneratedMatcher::make(),
       register: $register,
       invoker: $invoker,
       registrarOptions: [
           'exposeUrlServices' => true,
           'signedUrlConfig' => $signed,
           'urlBaseUri' => 'https://example.com',
       ],
   );

Then use ``Route::urlFor()``, ``Route::signedUrlFor()``, ``Route::temporaryUrlFor()`` or ``Route::temporaryUrlUntil()``.

5. Matcher cache tooling
------------------------

Matcher caches can be generated independently of a request kernel:

.. code:: bash

   php ./webrick route:cache --matcher=generated --cache=.route-cache/generated.php --routes=routes.php

This is not the compiled production application artifact. It only prepares matcher state.

6. Production
-------------

Use ``RouteCompiler`` / ``ReleaseCompiler`` to compile the Webrick artifact together with the application-owned InterMix runtime, then boot ``CompiledRouterKernel`` with that ``ProductionContainer``.

See `Framework Integration <framework-integration.rst>`__, `Route Cache <../reference/route-cache.rst>`__, and `Response Emitters and Runtime Adapters <../reference/emitters.rst>`__.

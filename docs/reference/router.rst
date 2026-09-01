Router API Reference
====================

Webrick 5 separates route registration from strict compiled production execution.

Development/registrar kernel
----------------------------

``RouterKernel`` is the explicit development path. The host supplies the InterMix ``Invoker``; Webrick does not create or select a container.

.. code:: php

   use Infocyph\InterMix\DI\Invoker;
   use Infocyph\Webrick\Response\Response;
   use Infocyph\Webrick\Router\Definition\Registrar;
   use Infocyph\Webrick\Router\Kernel\RouterKernel;
   use Infocyph\Webrick\Router\Matching\GeneratedMatcher;
   use Psr\Log\NullLogger;

   $invoker = Invoker::with($applicationBuilder->development());

   $kernel = RouterKernel::bootWithRegistrar(
       log: new NullLogger(),
       matcher: GeneratedMatcher::make(),
       register: static function (Registrar $routes): void {
           $routes->get('/users/{id:int}', [UserController::class, 'show'], 'users.show');
       },
       invoker: $invoker,
   );

``RouterKernel::bootWithRegistrar()``
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

+----------------------------------------+--------------------------------------------------------------------------+
| Argument                               | Purpose                                                                  |
+========================================+==========================================================================+
| ``log``                                | PSR-3 logger                                                             |
+----------------------------------------+--------------------------------------------------------------------------+
| ``matcher``                            | matcher instance for development routing                                 |
+----------------------------------------+--------------------------------------------------------------------------+
| ``register``                           | ``Closure(Registrar): void`` route registration                          |
+----------------------------------------+--------------------------------------------------------------------------+
| ``invoker``                            | **required host-owned** InterMix invoker                                 |
+----------------------------------------+--------------------------------------------------------------------------+
| ``registrarOptions``                   | slash behavior, signing and URL-base options                             |
+----------------------------------------+--------------------------------------------------------------------------+
| ``preGlobal`` / ``postGlobal``         | explicit global middleware descriptors                                   |
+----------------------------------------+--------------------------------------------------------------------------+
| ``invokerOnMiddleware``                | opt into Invoker-mediated middleware calls in the development dispatcher |
+----------------------------------------+--------------------------------------------------------------------------+
| ``errorHandler``                       | optional custom top-level error renderer                                 |
+----------------------------------------+--------------------------------------------------------------------------+
| ``bindUrlServices``                    | optional explicit development URL-service binding hook                   |
+----------------------------------------+--------------------------------------------------------------------------+
| ``preGlobalTags`` / ``postGlobalTags`` | application-owned InterMix middleware tags                               |
+----------------------------------------+--------------------------------------------------------------------------+
| ``debug``                              | enable diagnostic error detail for development                           |
+----------------------------------------+--------------------------------------------------------------------------+

There is no ``routeCache``, provider-import, container-selection, request-scope toggle, alias-fallback, or per-request PHP-error switch on the v5 development kernel. Every ``handle()`` executes inside an InterMix request scope seeded with the active ``Request``.

Compiled production kernel
--------------------------

Production traffic uses ``CompiledRouterKernel``. It accepts a host-selected ``ProductionContainer`` and a verified Webrick artifact.

.. code:: php

   $kernel = CompiledRouterKernel::fromPrevalidatedArtifact(
       log: $logger,
       matcher: GeneratedMatcher::make(),
       container: $productionContainer,
       artifactPath: $release['webrick']['path'],
       trustedSha256: $release['webrick']['sha256'],
       environment: $release['environment'],
       configFingerprint: $release['config_fingerprint'],
   );

Use ``fromCompiledArtifact()`` when an external trusted digest is unavailable. Environment/configuration values validate the artifact; they never select a Webrick or InterMix runtime.

Route registration
------------------

Use ``Registrar`` directly or the ``Router`` facade while a registrar is active:

.. code:: php

   use Infocyph\Webrick\Router\Facade\Router as Route;

   Route::get('/users', [UserController::class, 'index'], 'users.index');
   Route::post('/users', [UserController::class, 'store'], 'users.store');
   Route::put('/users/{id:int}', [UserController::class, 'update'], 'users.update');
   Route::patch('/users/{id:int}', [UserController::class, 'patch'], 'users.patch');
   Route::delete('/users/{id:int}', [UserController::class, 'destroy'], 'users.destroy');
   Route::options('/users', [UserController::class, 'options']);
   Route::head('/health', [HealthController::class, 'head']);

When an explicit OPTIONS route is absent, matcher control flow can produce an automatic OPTIONS response with the canonical ``Allow`` set. HEAD falls back to GET when appropriate while suppressing the response body at the boundary.

Parameters and constraints
--------------------------

.. code:: php

   Route::get('/users/{id:int}', static fn(string $id) => Response::json(['id' => (int) $id]));
   Route::get('/posts/{slug:slug}', $handler);
   Route::get('/objects/{id:uuid}', $handler);
   Route::get('/colors/{color:hex}', $handler);

Custom constraints are registered and frozen at boot. Runtime matching consumes the compiled/canonical constraint representation rather than rediscovering structure per request.

Groups
------

.. code:: php

   Route::group(
       prefix: '/api',
       domain: 'api.example.com',
       middleware: ['auth', 'throttle:120,60'],
       namePrefix: 'api.',
       callback: static function (): void {
           Route::get('/users', [UserController::class, 'index'], 'users.index');
       },
   );

Nested groups compose prefix/domain/name/middleware metadata at registration/compile time.

Middleware
----------

Per-route middleware descriptors can be class strings, supported callables/objects, or registered aliases:

.. code:: php

   Route::get('/private', [PrivateController::class, 'show'], [
       'as' => 'private.show',
       'middleware' => ['auth', 'throttle:30,60'],
   ]);

Compiled production resolves and validates middleware descriptors before traffic and keeps a zero-pipeline fast path for routes without middleware.

URL generation
--------------

Named routes drive URL generation:

.. code:: php

   $url = Route::urlFor('users.show', ['id' => 42]);
   $absolute = Route::urlFor('users.show', ['id' => 42], absolute: true);
   $signed = Route::signedUrlFor('users.show', ['id' => 42]);
   $temp = Route::temporaryUrlFor('users.show', ['id' => 42], ttl: 900);

Signing configuration is represented by ``SignedUrlConfig``. Generation and verification share canonical path/query normalization.

Error boundary
--------------

Framework rejection paths should throw ``HttpExceptionInterface`` implementations. Only that authoritative contract or an **explicit custom ``exceptionMap``** may define an HTTP status. Arbitrary exception properties, methods and numeric exception codes are not treated as HTTP status metadata.

Error response format negotiation uses the same canonical ``ContentNegotiator`` as normal response negotiation. HEAD errors have no body, request IDs are bounded, and stack/file detail is emitted only when debug mode is enabled.

Matcher cache vs compiled artifact
----------------------------------

``RouteCache::build()`` / ``webrick route:cache`` creates matcher cache only. It does not boot ``RouterKernel`` or InterMix.

The full production graph is built by ``ReleaseCompiler``, which coordinates Webrick execution plans with the host application's InterMix compiled runtime and release manifest.

See `Matcher <matcher.rst>`__, `Matcher Cache <route-cache.rst>`__, `Middleware <middleware.rst>`__, and `Emitters/Runtime Adapters <emitters.rst>`__.

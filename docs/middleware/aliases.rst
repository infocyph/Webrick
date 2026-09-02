Middleware Aliases
==================

Middleware aliases keep route definitions declarative while deferring runtime-backed middleware construction until the matched request executes inside its InterMix request scope.

.. code:: php

   Route::get('/admin', [AdminController::class, 'index'], [
       'middleware' => ['auth:admin', 'throttle:30,60'],
   ]);

An alias descriptor is split at the first ``:``. Comma-separated values after it are trimmed and kept as deterministic string parameters.

Register a known alias
----------------------

Register aliases during application or worker bootstrap:

.. code:: php

   use Infocyph\Webrick\Router\Dispatch\MiddlewareAliases;

   MiddlewareAliases::register(
       'throttle',
       [ThrottleAliasFactory::class, 'resolve'],
   );

The alias name is case-insensitive. Its resolver may return:

- a callable middleware;
- a middleware object;
- a middleware class-string.

For compiled production, prefer class/function/static-method resolver descriptors. Serializable Closures remain supported, but a stable callable descriptor makes the artifact boundary explicit and avoids capturing process objects accidentally.

Registering the same alias again replaces its resolver. This is useful for tests and deliberate worker reconfiguration before the production registries are frozen.

Register a class-string
-----------------------

.. code:: php

   MiddlewareAliases::register('signed', VerifySignedUrlMiddleware::class);

With no alias parameters, Webrick keeps the class-string so InterMix can construct it and honor DI lifetimes. With parameters, Webrick preserves the class-string plus those parameters as a runtime middleware descriptor. InterMix constructs it inside the active ``webrick.request`` scope rather than Webrick instantiating it during route compilation.

This means parameterized middleware can still use scoped or injected dependencies. Keep route parameters limited to deterministic scalar configuration; keep service lookup and request state in DI.

Register a lazy alias family
----------------------------

An integration package or host framework may own a family such as ``auth``, ``auth:admin`` and ``auth:verified``. Register one resolver instead of eagerly registering or loading every middleware:

.. code:: php

   MiddlewareAliases::registerResolver(
       supports: static fn(string $alias): bool => $alias === 'auth',
       resolve: [AuthMiddlewareResolver::class, 'resolve'],
       name: 'host.auth',
   );

``supports`` receives the normalized alias name without parameters. The runtime resolver receives that name followed by each parsed string parameter. It must return a callable, object, or string.

The build plane may call ``supports`` to classify an alias, but it does not execute the runtime ``resolve`` callback. A non-empty ``name`` gives the family a stable identity; registering that name again replaces the previous resolver instead of appending another one.

Use direct aliases for a small known set. Use a resolver for optional modules, host-framework middleware registries, or another package's namespace of aliases.

Resolution lifecycle
--------------------

Development and compiled production preserve the same runtime semantics but prepare them differently.

Development:

1. Webrick matches the request to a route.
2. The dispatcher prepares that route's reusable middleware pipeline.
3. Alias descriptors that need runtime resolution stay lazy inside the pipeline.
4. Each matched request resolves the descriptor through InterMix inside ``webrick.request``.
5. Scoped dependencies therefore remain request-local even though the pipeline itself is memoized.

Compiled production:

1. ``HandlerCompiler`` classifies aliases during route compilation without invoking runtime factories/resolvers.
2. Parameterized or resolver-backed aliases become artifact-safe runtime middleware descriptors containing a resolver spec and exportable parameters.
3. ``ArtifactValueCodec`` persists and restores those descriptors with the route execution plan.
4. ``CompiledMiddlewarePipeline`` resolves only the matched route's runtime descriptor inside the active ``webrick.request`` scope.
5. The prepared production pipeline remains reusable while scoped middleware state is fresh per execution context.

This keeps authentication, database, cache, telemetry, or other optional middleware off routes that do not use them and prevents build-time middleware construction from leaking request/runtime state into artifacts.

Direct middleware remains supported
-----------------------------------

Aliases are optional:

.. code:: php

   Route::get('/download', [DownloadController::class, 'show'], [
       'middleware' => [
           VerifySignedUrlMiddleware::class,
       ],
   ]);

Use a class-string when DI can provide its dependencies. Use an object only for intentionally process-owned runtime configuration. Class strings and alias strings are the most cache-friendly route descriptors.

Testing and persistent workers
------------------------------

Reset the static registry in isolated tests or before deliberately rebuilding a persistent worker's middleware configuration:

.. code:: php

   MiddlewareAliases::reset();

   MiddlewareAliases::register(
       'auth',
       [AllowAuthenticatedTestMiddlewareFactory::class, 'resolve'],
   );

``reset()`` clears direct aliases and family resolvers. Do not reset per request; route pipelines and compiled artifacts are designed to remain stable for the worker lifecycle. Production freezes the registry before traffic starts.

There is no public alias-enumeration API. Test observable dispatch behavior or use ``MiddlewareAliases::has('auth')`` for a specific normalized alias.

Guidelines
----------

- Register aliases and resolvers once during bootstrap/build composition.
- Prefer stable class/function/static-method resolver descriptors for compiled production.
- Give family resolvers stable names in persistent workers.
- Keep parameter syntax small, positional and exportable, for example ``throttle:30,60``.
- Keep secrets, services and request state in configuration/DI, not route strings.
- Put middleware only on routes that require it.
- Never construct request-bound middleware in the build plane or retain it in process-global alias state.

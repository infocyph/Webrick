Middleware Aliases
==================

Middleware aliases keep route definitions declarative while deferring middleware construction until a route pipeline is needed.

.. code:: php

   Route::get('/admin', [AdminController::class, 'index'], [
       'middleware' => ['auth:admin', 'throttle:30,60'],
   ]);

An alias descriptor is split at the first ``:``. Comma-separated values after it are trimmed and passed to the alias resolver as strings.

Register a known alias
----------------------

Register aliases during application or worker bootstrap:

.. code:: php

   use Infocyph\Webrick\Router\Dispatch\MiddlewareAliases;

   MiddlewareAliases::register(
       'throttle',
       static fn(string ...$parameters) => new ThrottleMiddleware(
           max: (int) ($parameters[0] ?? 60),
           window: (int) ($parameters[1] ?? 60),
           pool: $cache,
       ),
   );

The alias name is case-insensitive. Its factory may return:

- a callable middleware;
- a middleware object;
- a middleware class-string.

Registering the same alias again replaces its factory. This is useful for tests and deliberate worker reconfiguration.

Register a class-string
-----------------------

.. code:: php

   MiddlewareAliases::register('signed', VerifySignedUrlMiddleware::class);

With no alias parameters, Webrick keeps the class-string so InterMix can construct it and honor DI lifetimes. Parameterized class aliases are preserved as runtime middleware descriptors instead of being instantiated while routes are compiled.

For middleware with typed or service dependencies, prefer a factory or an InterMix registration rather than putting service state into serialized route parameters.

Register a lazy alias family
----------------------------

An integration package or host framework may own a family such as ``auth``, ``auth:admin`` and ``auth:verified``. Register one resolver instead of eagerly registering or loading every middleware:

.. code:: php

   MiddlewareAliases::registerResolver(
       supports: static fn(string $alias): bool => $alias === 'auth',
       resolve: static fn(string $alias, string ...$parameters) =>
           $authBridge->resolve($alias, $parameters),
       name: 'host.auth',
   );

``supports`` receives the normalized alias name without parameters. ``resolve`` receives that name followed by each parsed string parameter. It must return a callable, object, or string.

The resolver is consulted only when Webrick encounters a potential alias. A non-empty ``name`` gives the family a stable identity; registering that name again replaces the previous resolver instead of appending another one.

Use direct aliases for a small known set. Use a resolver for optional modules, host-framework middleware registries, or another package's namespace of aliases.

Build and runtime boundary
--------------------------

Route compilation never executes runtime-backed alias factories. Webrick preserves the resolver specification and parsed positional parameters in an artifact-safe ``RuntimeMiddlewareDescriptor``. The router artifact transports that descriptor unchanged to the compiled runtime.

During request dispatch, Webrick resolves the descriptor through the active InterMix runtime while ``webrick.request`` is active, then invokes the resolved middleware with ``request`` and ``next``. This keeps request-scoped dependencies and concurrent execution contexts isolated correctly and prevents route discovery from constructing middleware.

The same deferred rule is used by the development dispatcher: a pipeline may be memoized for the route, but a runtime-backed alias is resolved inside each active request scope rather than memoizing the resolved middleware object process-wide.

Resolution lifecycle
--------------------

Alias registration does not construct middleware. During dispatch:

1. Webrick matches the request to a route.
2. The dispatcher compiles that route's middleware pipeline on first use.
3. Direct aliases are checked before family resolvers.
4. Runtime-backed aliases are resolved through InterMix inside the active request scope.
5. The route pipeline structure is memoized, while request-scoped middleware resolution remains request-local.

This keeps authentication, database, cache, telemetry, or other optional middleware off routes that do not use them.

Direct middleware remains supported
-----------------------------------

Aliases are optional:

.. code:: php

   Route::get('/download', [DownloadController::class, 'show'], [
       'middleware' => [
           VerifySignedUrlMiddleware::class,
       ],
   ]);

Use a class-string when DI can provide its dependencies. Use an object for one-off runtime configuration. Class strings and alias strings are the most cache-friendly route descriptors.

Testing and persistent workers
------------------------------

Reset the static registry in isolated tests or before deliberately rebuilding a persistent worker's middleware configuration:

.. code:: php

   MiddlewareAliases::reset();

   MiddlewareAliases::register(
       'auth',
       static fn() => new AllowAuthenticatedTestMiddleware(),
   );

``reset()`` clears direct aliases and family resolvers. Do not reset per request; pipelines are designed to remain stable for the worker lifecycle.

There is no public alias-enumeration API. Test observable dispatch behavior or use ``MiddlewareAliases::has('auth')`` for a specific normalized alias.

Guidelines
----------

- Register aliases and resolvers once during bootstrap.
- Give family resolvers stable names in persistent workers.
- Keep parameter syntax small and positional, for example ``throttle:30,60``.
- Keep secrets and service lookup in configuration or DI, not route strings.
- Put middleware only on routes that require it.
- Avoid returning request-bound middleware objects from a process-global factory unless their state is safely scoped.

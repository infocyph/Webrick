Maintenance Mode
================

Webrick provides two maintenance-mode integration points:

- ``MaintenanceModeMiddleware`` for applications that already need the full request/middleware pipeline.
- ``MaintenancePreRoutingGate`` for compiled or persistent runtimes that only need lightweight routing data and maintenance state.

For performance-sensitive compiled runtimes, prefer the pre-routing gate. It evaluates after Webrick has normalized method, host, and path, but before route matching, full ``Request`` materialization, middleware resolution, handler resolution, or request-scope entry.

--------------

Maintenance state
-----------------

Both integration points use ``MaintenanceStateInterface`` and therefore share the same control-plane state.

In-memory state
~~~~~~~~~~~~~~~

.. code:: php

   use Infocyph\Webrick\Middleware\Maintenance\MemoryMaintenanceState;

   $state = new MemoryMaintenanceState();

   $state->enable('Deploying database changes.');
   $state->disable();

File-backed state
~~~~~~~~~~~~~~~~~

.. code:: php

   use Infocyph\Webrick\Middleware\Maintenance\FileMaintenanceState;

   $state = new FileMaintenanceState(
       file: __DIR__ . '/storage/framework/down',
       refreshMilliseconds: 1000,
   );

``FileMaintenanceState`` keeps worker-local state and only refreshes the filesystem after the configured interval. It does not perform filesystem I/O on every request while the refresh interval is still valid.

--------------

Compiled/persistent runtime
---------------------------

Use ``MaintenancePreRoutingGate`` when maintenance decisions require only the canonical routing path and maintenance state.

.. code:: php

   use Infocyph\Webrick\Middleware\Maintenance\MaintenancePreRoutingGate;
   use Infocyph\Webrick\Router\Kernel\CompiledRouterKernel;

   $gate = new MaintenancePreRoutingGate(
       file: __DIR__ . '/storage/framework/down',
       retryAfter: 300,
       refreshMilliseconds: 1000,
       bypassPaths: ['/health', '/ready'],
   );

   $kernel = CompiledRouterKernel::fromCompiledArtifact(
       log: $logger,
       matcher: $matcher,
       container: $container,
       artifactPath: $artifactPath,
       environment: 'production',
       configFingerprint: $configFingerprint,
       preRoutingGate: $gate,
   );

``fromPrevalidatedArtifact()`` accepts the same optional ``preRoutingGate`` argument.

The gate is runtime composition only. It is not encoded into route artifacts and does not change matcher or execution-plan formats.

When maintenance is inactive, ``evaluate()`` returns ``null`` and normal routing continues. When maintenance is active, the gate returns a complete Webrick ``Response`` immediately.

On a requestless compiled route, both the inactive and active gate paths remain requestless: the gate does not call ``RuntimeRequestContext::request()`` and does not enter ``webrick.request`` scope.

--------------

Development/registrar runtime
-----------------------------

``RouterKernel::bootWithRegistrar()`` accepts the same optional gate contract for semantic parity:

.. code:: php

   $kernel = RouterKernel::bootWithRegistrar(
       log: $logger,
       matcher: $matcher,
       register: $routes,
       invoker: $invoker,
       preRoutingGate: $gate,
   );

The development kernel already receives/materializes a ``Request`` before its routing pipeline, so this mode provides behavior parity rather than the compiled runtime's request-materialization saving.

--------------

Middleware integration
----------------------

Use ``MaintenanceModeMiddleware`` when the maintenance decision must remain inside the normal middleware pipeline.

.. code:: php

   use Infocyph\Webrick\Middleware\Maintenance\MaintenanceModeMiddleware;

   $middleware = new MaintenanceModeMiddleware(
       file: __DIR__ . '/storage/framework/down',
       retryAfter: 300,
       refreshMilliseconds: 1000,
   );

The middleware and the pre-routing gate share the same maintenance response policy. Do not configure both for the same compiled production path after migrating to the pre-routing gate; doing so only adds redundant work.

--------------

Response semantics
------------------

The default active response is ``503 Service Unavailable`` with the maintenance message and the same core response metadata used by the middleware path:

- ``Retry-After``
- ``Content-Type``
- ``Cache-Control: no-store``
- ``X-Content-Type-Options: nosniff``
- ``Vary: Accept``

The default body is plain text:

.. code:: text

   503 Service Unavailable
   Deploying database changes.

If the state returns an empty message, Webrick falls back to ``Service is down for maintenance.``

``HEAD`` preserves the status and headers, including the body-derived ``Content-Length`` when available, but returns an empty response body.

--------------

Bypass paths
------------

The pre-routing gate intentionally supports only exact path bypasses because it operates before full request construction.

.. code:: php

   $gate = new MaintenancePreRoutingGate(
       state: $state,
       bypassPaths: ['/health', '/ready'],
   );

Bypass paths are:

- normalized once at boot with Webrick's canonical path normalization;
- exact matches, not prefixes or patterns;
- limited to 32 configured entries;
- not allowed to contain query strings or fragments.

IP/CIDR rules, cookies, arbitrary headers, authentication, authorization, sessions, route parameters, or user/tenant information do not belong in the pre-routing gate. Keep those decisions at the gateway or in the normal request/middleware pipeline.

--------------

Migration guidance
------------------

For a compiled production runtime currently using maintenance as global middleware:

1. Reuse the same maintenance state source.
2. Configure one ``MaintenancePreRoutingGate`` at kernel/process boot.
3. Preserve required exact health/readiness bypass paths.
4. Verify active ``503`` and ``HEAD`` behavior.
5. Verify inactive and active compiled paths do not materialize a full request when the selected route is otherwise requestless.
6. Remove the global maintenance middleware from that compiled path.
7. Re-run representative throughput tests before claiming an application-level performance improvement.

Existing applications that do not configure ``preRoutingGate`` keep their current routing behavior.

--------------

Operational notes
-----------------

- Select the gate once during boot; do not discover or resolve it per request.
- Keep attached state immutable or concurrency-safe for persistent workers.
- ``MemoryMaintenanceState`` instances should be owned deliberately by the host/control plane; do not use request-specific state in the gate.
- Use a non-zero file refresh interval in persistent workers unless immediate per-request filesystem observation is explicitly required.
- The gate does not replace gateway hardening, authentication, authorization, throttling, CORS, telemetry, or route middleware.

--------------

Testing
-------

Webrick's WB-5 tests cover inactive and active requestless execution, exact bypasses, ``HEAD`` semantics, response parity, and Fiber isolation. ``benchmarks/PreRoutingGateBench.php`` provides the component benchmark fixture; application-level throughput must still be measured by the consuming runtime.

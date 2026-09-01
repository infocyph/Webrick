Middleware Overview
===================

Webrick middleware is split between portable request/response policy and the compiled production execution plan. Development can register class strings, objects, aliases and callables; production resolves and validates the supported graph before traffic.

Typical portable stack
----------------------

Pre-global
~~~~~~~~~~

- **Gateway Hardening** — trusted request/security normalization.
- **Telemetry** — request IDs and tracing when enabled.
- **Maintenance Mode** — cached/explicit maintenance state.
- **Request Limits** — portable fallback when transport limits are unavailable.
- **Throttle** — production requires an atomic counter backend.
- **Cookie Encryption** — optional; routes without cookies should not pay crypto cost.
- **Negotiation** — media type, charset and locale selection.
- **Response Cache** — shared-cache policy and representation reuse.
- **Cache Validators** — conditional request handling without global filesystem probing.

Post-global
~~~~~~~~~~~

- **Compression** — string-body compression when the transport does not own it.
- **CORS & Policies** — route policy and security headers.
- **Vary Accumulator** — one request-local ``Vary`` context.
- **Response Linter** — development/test only.

``InputSanitizerMiddleware`` is an explicit compatibility/transformation tool, not a blanket security layer. HTTP method normalization happens once at the request/runtime boundary; Webrick 5 has no method-normalization middleware.

Development wiring
------------------

.. code:: php

   $kernel = RouterKernel::bootWithRegistrar(
       log: $logger,
       matcher: GeneratedMatcher::make(),
       register: $register,
       invoker: $applicationInvoker,
       preGlobal: [
           GatewayHardeningMiddleware::class,
           RequestLimitsMiddleware::class,
           NegotiationMiddleware::class,
           ResponseCacheMiddleware::class,
           CacheValidatorsMiddleware::class,
       ],
       postGlobal: [
           CompressionMiddleware::class,
           CorsAndPoliciesMiddleware::class,
           VaryAccumulatorMiddleware::class,
       ],
   );

The application owns the InterMix builder/container. ``RouterKernel`` receives an ``Invoker``; it does not create a container or import providers.

Production wiring
-----------------

Compile global and route middleware through ``RouteCompiler`` / ``ReleaseCompiler``, then boot ``CompiledRouterKernel`` with the host-selected ``ProductionContainer``.

Production preparation provides:

- alias resolution before traffic;
- prepared invocation modes;
- per-route pipelines built at boot;
- a zero-pipeline path for routes with no middleware;
- request scopes only for execution plans that require scoped/injected context;
- route policy attributes attached only when a ``Request`` is materialized.

Ordering guidance
-----------------

1. Hardening and transport/request limits first.
2. Throttle before expensive application work.
3. Negotiation before handlers that use negotiated attributes.
4. Cache/validator short-circuits before body generation where applicable.
5. Compression after representation creation.
6. CORS/policies and ``Vary`` finalization at the response boundary.

Persistent workers
------------------

Middleware instances shared by a worker must not hold current-request state. Request IDs, trace data, ``Vary``, decoded cookies, IP information and native transport handles remain request-local. Webrick runtime adapters expose transport capabilities so portable middleware can bypass work already owned by Swoole/OpenSwoole, RoadRunner or Workerman.

Deep dives
----------

- :doc:`compression`
- :doc:`cache-validators`
- :doc:`vary-accumulator`
- :doc:`cors-and-policies`
- :doc:`throttle`
- :doc:`request-limits`
- :doc:`cookie-encryption`
- :doc:`maintenance-mode`
- :doc:`telemetry`
- :doc:`input-sanitizer`
- :doc:`negotiation`
- :doc:`response-cache`
- :doc:`response-linter`

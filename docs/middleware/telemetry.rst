Telemetry Middleware
====================

``TelemetryMiddleware`` provides request correlation, W3C Trace Context handling, timing headers, access logging and optional OpenTelemetry server spans. The middleware keeps tracing state on the active Webrick ``Request``; it does not expose process-global current-request state.

Runtime modes
-------------

Minimal mode
~~~~~~~~~~~~

Minimal mode is the default. It has no OpenTelemetry dependency and:

- accepts a valid incoming W3C ``traceparent`` when ``respectIncomingTraceparent`` is enabled;
- creates a trace ID/span ID when a usable parent is not available;
- derives or preserves a request ID according to configuration;
- attaches an immutable ``RequestContext`` to the request;
- optionally emits response timing, request-ID, trace-ID and ``traceparent`` headers;
- optionally emits Network Error Logging headers;
- writes the access log through the configured PSR-3 logger.

OpenTelemetry mode
~~~~~~~~~~~~~~~~~~

OpenTelemetry is **opt-in**. Set ``enableOtelIntegration: true`` and install an OpenTelemetry SDK that provides ``OpenTelemetry\\API\\Globals`` and ``OpenTelemetry\\API\\Trace\\SpanKind``. When both conditions are true, Webrick delegates the request to ``OpenTelemetryHandler`` and creates a server span.

If OpenTelemetry integration is disabled, or the required API classes are unavailable, Webrick stays on the minimal path.

Basic configuration
-------------------

.. code:: php

   use Infocyph\Webrick\Middleware\TelemetryMiddleware;

   $telemetry = new TelemetryMiddleware(
       log: $logger,
       addXResponseTime: true,
       addServerTiming: true,
       emitRequestId: true,
       emitTraceIdHeader: true,
       respectIncomingTraceparent: true,
   );

Put telemetry early in ``preGlobal`` when you want it to measure the middleware/application work that follows it:

.. code:: php

   use Infocyph\Webrick\Router\Kernel\RouterKernel;
   use Infocyph\Webrick\Router\Matching\FusedMatcher;

   $kernel = RouterKernel::bootWithRegistrar(
       log: $logger,
       matcher: FusedMatcher::make(),
       register: $register,
       invoker: $invoker,
       preGlobal: [
           $telemetry,
           // Other pre-global middleware...
       ],
   );

The ``Invoker`` belongs to the host application. Webrick does not create or select an InterMix container in ``RouterKernel``.

Constructor options
-------------------

``TelemetryMiddleware`` currently accepts:

+-------------------------------+----------------------+--------------------------------------------------------------+
| Option                        | Default              | Purpose                                                      |
+===============================+======================+==============================================================+
| ``log``                       | ``NullLogger``       | PSR-3 access logger.                                         |
+-------------------------------+----------------------+--------------------------------------------------------------+
| ``addXResponseTime``          | ``true``             | Emit ``X-Response-Time``.                                    |
+-------------------------------+----------------------+--------------------------------------------------------------+
| ``addServerTiming``           | ``true``             | Emit ``Server-Timing``.                                      |
+-------------------------------+----------------------+--------------------------------------------------------------+
| ``emitRequestId``             | ``true``             | Emit a request-ID response header.                           |
+-------------------------------+----------------------+--------------------------------------------------------------+
| ``requestIdHeader``           | ``X-Request-Id``     | Request-ID header name.                                      |
+-------------------------------+----------------------+--------------------------------------------------------------+
| ``respectExistingRequestId``  | ``true``             | Reuse an accepted incoming request ID when possible.         |
+-------------------------------+----------------------+--------------------------------------------------------------+
| ``nelGroup``                  | ``null``             | Network Error Logging group; ``null`` disables NEL.          |
+-------------------------------+----------------------+--------------------------------------------------------------+
| ``nelEndpoint``               | ``null``             | NEL reporting endpoint.                                      |
+-------------------------------+----------------------+--------------------------------------------------------------+
| ``nelTtlSeconds``             | ``86400``            | NEL policy lifetime.                                         |
+-------------------------------+----------------------+--------------------------------------------------------------+
| ``nelIncludeSubdomains``      | ``true``             | Include subdomains in the NEL policy.                        |
+-------------------------------+----------------------+--------------------------------------------------------------+
| ``nelCollectSuccesses``       | ``false``            | Allow successful-response NEL sampling.                      |
+-------------------------------+----------------------+--------------------------------------------------------------+
| ``emitTraceIdHeader``         | ``true``             | Emit the configured trace-ID header.                         |
+-------------------------------+----------------------+--------------------------------------------------------------+
| ``traceIdHeader``             | ``Trace-Id``         | Trace-ID response header name.                               |
+-------------------------------+----------------------+--------------------------------------------------------------+
| ``respectIncomingTraceparent``| ``true``             | Continue a valid incoming W3C trace when possible.           |
+-------------------------------+----------------------+--------------------------------------------------------------+
| ``emitTraceparentHeader``     | ``false``            | Emit the current W3C ``traceparent`` response header.        |
+-------------------------------+----------------------+--------------------------------------------------------------+
| ``enableOtelIntegration``     | ``false``            | Opt into OpenTelemetry integration when its API is present.  |
+-------------------------------+----------------------+--------------------------------------------------------------+
| ``otelServiceName``           | ``webrick-app``      | Service name attached to OpenTelemetry spans.                |
+-------------------------------+----------------------+--------------------------------------------------------------+
| ``otelServiceVersion``        | ``1.0.0``            | Service version supplied to the OpenTelemetry integration.   |
+-------------------------------+----------------------+--------------------------------------------------------------+

``TelemetryOptions`` can also hold the same configuration and be converted with ``TelemetryMiddleware::fromOptions()``. ``$middleware->options()`` returns the current option set.

Request-local trace context
---------------------------

Webrick deliberately does **not** provide ``TraceContext::getTraceId()``-style global accessors. Global request state is unsafe for persistent workers, concurrent runtimes and coroutines.

``TelemetryMiddleware`` attaches ``RequestContext`` to the ``Request``. Read it explicitly from the request:

.. code:: php

   use Infocyph\Webrick\Request\Request;
   use Infocyph\Webrick\Response\Response;
   use Infocyph\Webrick\Support\TraceContext;

   final class UserController
   {
       public function show(Request $request, int $id): Response
       {
           $context = TraceContext::require($request);

           $this->logger->info('Fetching user', [
               'user_id' => $id,
               ...$context->logArray(),
           ]);

           return Response::json(['id' => $id]);
       }
   }

Use ``TraceContext::fromRequest($request)`` when telemetry context is optional:

.. code:: php

   $context = TraceContext::fromRequest($request);

   if ($context !== null) {
       $logger->debug('Request context', $context->logArray());
   }

``TraceContext::require()`` throws ``LogicException`` if the request does not carry a Webrick ``RequestContext``.

RequestContext API
~~~~~~~~~~~~~~~~~~

The immutable ``RequestContext`` exposes:

- ``request()`` — the request carrying the context;
- ``traceId()`` / ``spanId()`` / ``parentSpanId()``;
- ``requestId()``;
- ``flags()`` / ``traceState()``;
- ``traceParent()``;
- ``sampled()``;
- ``otelAvailable()``;
- ``all()`` — complete scalar context array;
- ``logArray()`` — non-null trace/span/request IDs for structured logging;
- ``logContext()`` — compact text suitable for logs/comments;
- ``propagationHeaders()`` — trace/request headers for an outgoing request.

For example:

.. code:: php

   $context = TraceContext::require($request);

   $traceId = $context->traceId();
   $spanId = $context->spanId();
   $requestId = $context->requestId();
   $sampled = $context->sampled();
   $otel = $context->otelAvailable();

   $logger->info('Payment request', $context->logArray());

Propagating context
-------------------

Use the request-local context for outgoing service calls:

.. code:: php

   $context = TraceContext::require($request);

   $response = $http->post('https://payments.example/charge', [
       'headers' => $context->propagationHeaders(),
       'json' => ['amount' => $amount],
   ]);

``propagationHeaders()`` can include ``traceparent``, ``tracestate``, ``X-Trace-Id`` and ``X-Request-Id`` when those values are available. Pass ``false`` to ``propagationHeaders(false)`` when the outgoing request should omit the request ID.

Structured logging
------------------

Because context is request-local, logger processors should be given the active request/context explicitly rather than querying a process-global helper. A simple application service can enrich records like this:

.. code:: php

   $context = TraceContext::fromRequest($request);

   $logger->info(
       'User login successful',
       $context?->logArray() ?? [],
   );

For database diagnostics:

.. code:: php

   $context = TraceContext::fromRequest($request);
   $comment = $context?->logContext() ?? '';

   $sql = $comment === ''
       ? 'SELECT * FROM users WHERE id = ?'
       : '/* ' . $comment . ' */ SELECT * FROM users WHERE id = ?';

Do not place sensitive user data, credentials or authorization headers into trace/log attributes.

W3C behavior
------------

On the minimal path, Webrick accepts an incoming ``traceparent`` only when it has the supported four-part version-00 shape and valid non-zero trace/span IDs plus two hexadecimal flag characters. Invalid input is not continued; Webrick creates a new trace instead. ``tracestate`` is retained only when the accompanying ``traceparent`` is accepted.

The middleware creates a new local span ID for the current request and retains the accepted parent span ID in request attributes. ``RequestContext::traceParent()`` rebuilds the current version-00 header from the request-local trace ID, span ID and flags.

OpenTelemetry integration
-------------------------

Install/configure the OpenTelemetry packages in the host application, then opt in:

.. code:: bash

   composer require open-telemetry/sdk open-telemetry/exporter-otlp

.. code:: php

   $telemetry = new TelemetryMiddleware(
       log: $logger,
       enableOtelIntegration: true,
       otelServiceName: 'checkout-api',
       otelServiceVersion: '2.4.0',
   );

Webrick obtains the global tracer provider through the OpenTelemetry API, creates a server span, activates its scope for the request, records request/response attributes, records thrown exceptions, sets span status from the HTTP result and ends/detaches the span in ``finally``.

Useful span attributes include the HTTP method/target/scheme/host, URL, user agent, request/response content metadata, peer/server network information, route name and selected application request attributes such as ``auth.user_id``, ``auth.role``, ``client.type`` and ``api.version`` when present.

The host application remains responsible for configuring exporters, processors and sampling in the OpenTelemetry SDK.

Response headers
----------------

With the default minimal configuration, responses may include:

.. code:: text

   X-Response-Time: 45.2ms
   Server-Timing: app;dur=45.2
   X-Request-Id: 1a2b3c...
   Trace-Id: a4c9e2b8f1d3a7e5c2b1f8e3d4a5c6b7

``emitTraceparentHeader`` is off by default. Enable it only when exposing the W3C response context is useful for your boundary.

Network Error Logging
---------------------

NEL is disabled when ``nelGroup`` or ``nelEndpoint`` is absent. When both are configured, Webrick can add the appropriate reporting headers using the configured TTL, subdomain and success-collection options.

Performance-sensitive configuration
-----------------------------------

For the lowest telemetry surface while retaining request-local trace correlation, disable response-only features you do not need and leave OpenTelemetry integration off:

.. code:: php

   $telemetry = new TelemetryMiddleware(
       log: $logger,
       addXResponseTime: false,
       addServerTiming: false,
       emitRequestId: false,
       emitTraceIdHeader: false,
       emitTraceparentHeader: false,
       enableOtelIntegration: false,
   );

Do not rely on fixed middleware-overhead numbers from documentation. Measure the actual application, logger/exporter, sampling configuration and runtime model that will be deployed.

Ordering
--------

Telemetry belongs in ``preGlobal`` when it should wrap application processing. Place it before the middleware whose latency and failures you want included. In compiled production, keep that ordering in the compiled middleware graph; do not rebuild middleware stacks per request.

Persistent workers
------------------

The request-local design is intentional:

- no process-global current request;
- no static mutable trace context;
- each request receives its own immutable ``RequestContext``;
- outgoing propagation is derived from the active request explicitly.

This is the safe model for long-lived workers and concurrent runtimes.

Troubleshooting
---------------

If context is unexpectedly absent, verify that ``TelemetryMiddleware`` ran before the component reading it and that the component received the **request instance forwarded by the middleware**. ``TraceContext::fromRequest()`` returns ``null`` on an uninstrumented request; ``TraceContext::require()`` makes that failure explicit.

If OpenTelemetry mode does not activate, verify both ``enableOtelIntegration: true`` and availability/configuration of the OpenTelemetry API/SDK in the host application.

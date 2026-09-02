Error Rendering
===============

Webrick keeps HTTP error semantics separate from presentation. Routing and middleware failures become typed HTTP exceptions where appropriate, while ``ErrorHandler`` owns application-exception rendering policy. The compiled production kernel keeps ordinary routing-control outcomes (404/405/automatic OPTIONS) on a direct path unless the host explicitly opts into custom routing-error rendering.

Default boundary
----------------

``RouterKernel`` creates a default ``ErrorHandler`` when none is supplied. The handler:

- maps ``HttpExceptionInterface`` instances to their declared HTTP status and headers;
- supports an explicit application ``exceptionMap`` for additional exception classes;
- does not infer HTTP status from arbitrary exception codes, properties or methods;
- negotiates the error representation through the same content-negotiation rules used by normal responses;
- suppresses response bodies for ``HEAD`` requests;
- bounds request IDs before reflecting them;
- exposes stack/file details only when ``debug`` is enabled.

Set ``debug: false`` for public production traffic. ``debug`` may be passed to ``RouterKernel::bootWithRegistrar()`` or directly to a custom ``ErrorHandler``. Process-level PHP warning/error conversion is deliberately separate from the per-request HTTP renderer: install ``PhpErrorBridge`` once at host/process bootstrap when that behavior is desired, and leave an existing host error handler in control otherwise.

Custom boundary renderer
------------------------

Pass your own ``ErrorHandler`` into ``RouterKernel::bootWithRegistrar(...)`` when you want custom application-exception output.

.. code:: php

   use Infocyph\Webrick\Exceptions\HttpExceptionInterface;
   use Infocyph\Webrick\Request\Request;
   use Infocyph\Webrick\Response\Response;
   use Infocyph\Webrick\Router\Kernel\ErrorHandler;
   use Infocyph\Webrick\Router\Kernel\RouterKernel;
   use Psr\Log\NullLogger;
   use Throwable;

   $errorHandler = new ErrorHandler(
       logger: new NullLogger(),
       debug: false,
       requestIdHeader: 'X-Request-Id',
       responseRenderer: static function (Request $request, Throwable $e, int $status, array $headers): ?Response {
           if (!str_starts_with($request->getUri()->getPath(), '/api/')) {
               return null;
           }

           $message = $e instanceof HttpExceptionInterface
               ? $e->getPublicMessage()
               : 'HTTP Error';

           return Response::json([
               'error' => $message,
               'status' => $status,
               'path' => $request->getUri()->getPath(),
           ], $status, $headers);
       },
   );

   $kernel = RouterKernel::bootWithRegistrar(
       log: new NullLogger(),
       matcher: $matcher,
       register: $register,
       invoker: $invoker,
       errorHandler: $errorHandler,
   );

Returning ``null`` from ``responseRenderer`` delegates back to Webrick's default renderer. Return a ``Response`` to take ownership of that error response.

Compiled routing-control policy
-------------------------------

``CompiledRouterKernel`` treats route misses and method misses as routing-control outcomes rather than application exceptions. Supplying a custom ``ErrorHandler`` does **not** automatically move normal 404/405 traffic onto the application exception path.

By default:

- 404 and 405 responses use ``RoutingControlRenderer`` directly;
- automatic ``OPTIONS`` remains a direct routing response;
- no full ``Request`` is materialized solely to render a default routing miss when the runtime input is otherwise sufficient;
- application exceptions still use the configured ``ErrorHandler``.

If the application deliberately wants its custom ``ErrorHandler`` to render 404/405 responses, opt in explicitly when constructing the compiled kernel:

.. code:: php

   use Infocyph\Webrick\Router\Kernel\CompiledRouterKernel;

   $kernel = CompiledRouterKernel::fromCompiledArtifact(
       log: $logger,
       matcher: $matcher,
       container: $productionContainer,
       artifactPath: $routerArtifact,
       environment: 'production',
       configFingerprint: $configFingerprint,
       errorHandler: $errorHandler,
       routeErrorsThroughErrorHandler: true,
   );

The same option is available on ``fromPrevalidatedArtifact()``. Keep it ``false`` unless custom routing-error presentation is required; the direct path is the production default because routing misses are expected control flow, not exceptional application failures.

Process-level PHP error conversion
----------------------------------

``PhpErrorBridge`` converts PHP warnings/notices covered by ``error_reporting()`` into ``ErrorException`` instances. It is intentionally process-scoped, not request-scoped.

.. code:: php

   use Infocyph\Webrick\Router\Kernel\PhpErrorBridge;

   $phpErrors = new PhpErrorBridge();
   $phpErrors->install(); // Once during process/worker bootstrap.

Install it only when Webrick/the host should own PHP warning conversion. Do not push and restore it around each request in a persistent worker. ``restore()`` is available when the host intentionally relinquishes that process-level handler.

Exception mapping
-----------------

Use ``exceptionMap`` when application exceptions need explicit HTTP semantics without implementing ``HttpExceptionInterface``:

.. code:: php

   $errorHandler = new ErrorHandler(
       logger: $logger,
       exceptionMap: [
           DomainNotFound::class => 404,
           DomainConflict::class => 409,
       ],
   );

Keep this mapping explicit. Numeric exception codes and arbitrary exception members are not HTTP metadata.

Security
--------

Keep ``debug`` disabled for public production traffic. Error renderers should expose only intentionally public messages and headers; application logs can retain richer diagnostic context.

Error Rendering
===============

Webrick now treats framework-owned HTTP failures as exceptions first, then converts them into a final ``Response`` at the kernel boundary.

That gives you two clean layers:

- middleware, matchers and routing can reject with exceptions
- the top-level ``ErrorHandler`` decides how the final HTTP body, headers and status are rendered

Controllers and user middleware can still return ``Response`` objects with explicit status codes directly. This guide is only about framework failures and custom boundary rendering.

What throws now
---------------

Common framework paths that now throw typed HTTP exceptions:

- signed URL verification
- throttling
- request limits
- maintenance mode
- invalid Host handling
- unacceptable content negotiation
- route ``404`` / ``405``

The final status still becomes ``400``, ``403``, ``404``, ``405``, ``406``, ``410``, ``429``, ``503`` and so on, but only when the kernel renders the exception.

Default behavior
----------------

If you do nothing, ``RouterKernel`` uses ``ErrorHandler`` with built-in rendering.

.. code:: php

   use Infocyph\Webrick\Router\Kernel\RouterKernel;

   $kernel = RouterKernel::bootWithRegistrar(
       // ...
       invoker: $invoker,
   );

Default rendering behavior:

- resolves HTTP status from ``HttpExceptionInterface``
- preserves exception headers like ``Allow`` or ``Retry-After``
- preserves public framework messages
- negotiates the final response type at the boundary
- retains diagnostic exception details for compatibility
- converts PHP warnings/notices inside the request boundary for compatibility

Set ``debug: false`` for public production traffic. ``debug`` may be passed to ``RouterKernel::bootWithRegistrar()`` or directly to a custom ``ErrorHandler``. Process-level PHP warning/error conversion is deliberately separate from the per-request HTTP renderer: install ``PhpErrorBridge`` once at host/process bootstrap when that behavior is desired, and leave an existing host error handler in control otherwise.

The logger may be a PSR-3 instance or a zero-argument closure returning one. The closure is invoked only after an exception reaches the boundary, so a successful request does not construct an error-only logger. A custom response renderer is likewise called only while rendering an error.

Custom boundary renderer
------------------------

Pass your own ``ErrorHandler`` into ``RouterKernel::bootWithRegistrar(...)`` when you want custom output.

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
       capturePhpErrors: true,
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
       errorHandler: $errorHandler,
       invoker: $invoker,
   );

Important behavior:

- return a ``Response`` to override the built-in renderer
- return ``null`` to fall back to the default renderer
- exception headers passed into the callback should usually be preserved

Example: API-only JSON errors
-----------------------------

The demo app now includes exactly this pattern:

- ``index.php``
- ``tests/IntegrationBootstrap.php``
- route: ``/api/error-demo``

The route throws:

.. code:: php

   use Infocyph\Webrick\Exceptions\HttpException;

   throw HttpException::forbidden('API token missing');

The custom boundary renderer converts that into JSON for ``/api/*`` requests:

.. code:: json

   {
     "error": "API token missing",
     "status": 403,
     "path": "/api/error-demo"
   }

When to throw vs when to return
-------------------------------

Use exceptions for framework-owned rejection paths:

- invalid signed link
- too many requests
- maintenance mode
- malformed request/host

Return ``Response`` directly for normal application flow:

- successful responses
- redirects
- validation responses chosen by your controller
- custom business responses where the controller owns the outcome

That keeps the boundary clean:

- framework rejections are normalized centrally
- application responses remain explicit

Recommendations
---------------

- Keep exception messages public only when they are safe to expose
- Preserve headers like ``Retry-After``, ``Allow`` and cache directives
- Scope custom renderers by path or content type instead of forcing one format globally
- For APIs, prefer returning ``null`` for non-API paths so browser-facing HTML errors still use the default renderer

Related docs
------------

- `Signed & Temporary URLs <./urls.rst>`__
- `Troubleshooting <../deployments/troubleshooting.rst>`__
- `Error Handling Recipe <../recipes/error-handling.rst>`__

Response Emitters and Runtime Adapters
======================================

Webrick 5 separates classic SAPI emission from persistent-server transport adapters. Runtime selection is explicit at bootstrap; there is no ``AutoEmitter`` and no per-request environment/extension discovery.

Classic emitters
----------------

+--------------------+----------------------------------------------------------------------------------+
| Class              | Target                                                                           |
+====================+==================================================================================+
| ``DefaultEmitter`` | PHP-FPM, FrankenPHP, LiteSpeed, Nginx Unit, Apache and generic synchronous SAPIs |
+--------------------+----------------------------------------------------------------------------------+
| ``CliEmitter``     | CLI/phpdbg                                                                       |
+--------------------+----------------------------------------------------------------------------------+

``DefaultEmitter`` accepts an optional ``Request``:

.. code:: php

   use Infocyph\Webrick\Response\Emitter\DefaultEmitter;

   (new DefaultEmitter())->emit($response, $request);

When the application already has a Webrick request, pass it explicitly. When a standalone compiled SAPI uses ``CompiledRouterKernel::handle()`` without creating a full request, the emitter can remain requestless too:

.. code:: php

   $response = $kernel->handle();
   (new DefaultEmitter())->emit($response);

With no explicit request, ``DefaultEmitter`` consults SAPI globals for method-sensitive behavior such as HEAD and still suppresses bodies for no-content response statuses. Do not construct ``Request::fromGlobals()`` solely to satisfy the emitter.

``DefaultEmitter`` can be configured with an explicit finish mode for FastCGI, FrankenPHP or LiteSpeed. The application chooses that mode at bootstrap; Webrick does not infer it for every request.

Compiled SAPI fast path
-----------------------

A standalone compiled synchronous application should preserve lazy request promotion end to end:

.. code:: php

   $response = $compiledKernel->handle();
   (new DefaultEmitter())->emit($response);

``CompiledRouterKernel`` first derives lightweight routing input from globals. A full Webrick ``Request`` is promoted only when the matched execution plan requires request-dependent behavior. Eagerly calling ``Request::fromGlobals()`` before ``handle()`` is valid when the application requires that object, but it intentionally bypasses this optimization.

Persistent runtimes
-------------------

Swoole/OpenSwoole, RoadRunner and Workerman use the runtime API under ``Infocyph\Webrick\Runtime\Http``:

- ``RuntimeAdapterInterface``
- ``RuntimeServer``
- ``SwooleRuntimeAdapter``
- ``RoadRunnerRuntimeAdapter``
- ``WorkermanRuntimeAdapter``

The adapter owns native request extraction and response writing. Native response handles are request-local and never stored in static current-response fields.

.. code:: php

   $server = new RuntimeServer($adapter, $compiledKernel);
   $server->run();

The concrete host/bootstrap supplies the adapter's native server callbacks/objects as required by that runtime.

Runtime capabilities
--------------------

Adapters expose transport capabilities so Webrick can avoid duplicate work. Depending on the runtime, the transport may own:

- request-size enforcement;
- response compression;
- native file/sendfile paths;
- chunk/stream framing;
- native response completion.

Portable middleware checks these capabilities and bypasses work already owned by the transport.

Files and streaming
-------------------

- Swoole/OpenSwoole uses native ``end()``, checked ``write()``, and ``sendfile(path, offset, length)`` where available.
- RoadRunner uses the boot-injected responder and supports string/generator output plus optional configured sendfile delegation.
- Workerman uses native response/file support for whole files and explicit chunk streaming otherwise.
- Writer failures are surfaced; they are not silently ignored.

Embedded frameworks
-------------------

If Laravel, Symfony, Slim, Foundation/Infbyte or another host owns response emission, do not use a Webrick emitter. Adapt and return the response to the host:

.. code:: php

   $webrickResponse = $kernel->handle($webrickRequest);

   return $responseAdapter->fromWebrick($webrickResponse);

Exactly one layer should own final headers/body emission, compression and transport completion.

Operational rules
-----------------

- Select the runtime once at process/worker bootstrap.
- Never share a native request/response handle across requests.
- Keep transport state out of reusable middleware and static registries.
- Let the transport own framing when it provides a native response API.
- Use ``DefaultEmitter`` only for synchronous SAPI boundaries.
- Do not create a full Webrick request solely for compiled-kernel routing or emission when the execution plan does not require one.
- Use persistent-runtime adapters with ``CompiledRouterKernel`` for long-lived workers.

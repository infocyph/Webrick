Runwire Runtime
===============

Webrick 5 can run behind Runwire 1.x through its optional native runtime bridge. Runwire is **not** a production dependency of Webrick: ordinary SAPI/shared-hosting installs and Webrick's direct Swoole/OpenSwoole, RoadRunner and Workerman adapters remain first-class paths.

Choose the Runwire bridge when the application wants Runwire to own runtime selection, worker/process lifecycle, request cancellation/deadlines, drain/shutdown behavior and runtime metrics while Webrick remains the HTTP routing/application kernel.

Ownership boundary
------------------

The integration is intentionally layered:

.. code:: text

   Runwire host / runtime driver
       -> RunwireRuntimeApplicationFactory
       -> RunwireRuntimeApplication
       -> RuntimeServer
       -> RunwireRuntimeAdapter
       -> CompiledRouterKernel
       -> application routes

Responsibilities stay with one owner:

- **Runwire** owns runtime selection, native portable/prefork operation, supported host drivers, request lifecycle, cancellation/deadlines, admission, drain/shutdown and runtime metrics.
- **Webrick** owns request normalization into its runtime context, routing, middleware, application response semantics and exactly-once response-writer completion.
- **InterMix** owns logical DI scope identity and propagation.
- **The host application/framework** owns the application ``ContainerBuilder``, compiled release artifacts, configuration and the final runtime choice.

Do not add a second lifecycle engine, a second request-scope store or a second response completion path around this bridge.

Application factory handoff
---------------------------

A compiled host can reuse the same application-owned InterMix production container and Webrick kernel used by other deployment modes, then expose that kernel to Runwire:

.. code:: php

   use Infocyph\Runwire\Http\HttpRequest;
   use Infocyph\Runwire\Http\ResponseWriterInterface;
   use Infocyph\Webrick\Runtime\Http\RunwireRuntimeAdapter;
   use Infocyph\Webrick\Runtime\Http\RunwireRuntimeApplicationFactory;
   use Infocyph\Webrick\Runtime\Http\RuntimeServer;
   use Infocyph\Webrick\Runtime\InterMixRuntime;

   // $container and $kernel come from the application's coordinated
   // InterMix + Webrick compiled release.
   $interMixRuntime = new InterMixRuntime($container);
   $server = new RuntimeServer($kernel, new RunwireRuntimeAdapter());

   $applicationFactory = new RunwireRuntimeApplicationFactory(
       handler: static function (
           HttpRequest $request,
           ResponseWriterInterface $writer,
       ) use ($server): void {
           $server->handle($request, $writer);
       },
       requestCleanup: static function () use ($interMixRuntime): void {
           $interMixRuntime->resetCurrentExecutionScope();
       },
   );

Pass ``$applicationFactory`` to the Runwire-selected host/driver bootstrap. The Runwire runtime context is supplied to the factory by Runwire; Webrick does not infer it from SAPI names, extensions or environment variables on each request.

The cleanup callback is a defensive carrier-local reset after normal Webrick scope unwinding. It does not replace InterMix's own ``withinScope()`` / ``withinScopeContext()`` cleanup and must not become a request-ID keyed scope registry.

Response completion
-------------------

``RunwireRuntimeAdapter`` is the single owner of Runwire response-writer completion for a Webrick request. ``RunwireRuntimeApplication`` deliberately forwards requests to Runwire's lifecycle with lifecycle-level response completion disabled, even when a host calls ``handle(..., completeResponse: true)``.

This prevents duplicate ``end()`` calls and keeps lazy/streamed Webrick response production inside the owning request scope until body production finishes.

Streaming and backpressure
--------------------------

Request bodies remain incrementally readable through the Runwire body bridge. Response streaming uses Runwire's ``WriteResult`` and ``onDrain()`` contract; when transport pressure is reported, Webrick suspends only its response-production continuation until Runwire signals drain. Webrick does not create a second output queue.

Cancellation and deadlines remain authoritative in Runwire's request context. Webrick observes them while producing a response and stops output when cancellation becomes visible.

Deployment modes covered
------------------------

The Webrick 5 acceptance suite verifies the Runwire application factory against:

- native portable mode;
- native prefork mode;
- FPM host driver;
- FrankenPHP host driver;
- RoadRunner host driver;
- Swoole host driver.

HTTP/1.1 request/response parity is covered through the complete application bridge. HTTP/2 and HTTP/3 acceptance verifies request/stream isolation at the Runwire application boundary. Native QUIC remains a Runwire/runtime capability; Webrick does not require a QUIC extension and does not implement a QUIC stack.

Direct Webrick runtimes remain valid
------------------------------------

Selecting Runwire is optional. Existing Webrick runtime adapters are not proxied through Runwire:

- synchronous Apache/FPM/LiteSpeed-style applications may continue to use the compiled kernel plus ``DefaultEmitter``;
- Webrick's direct Swoole/OpenSwoole, RoadRunner and Workerman adapters remain available;
- a host that already owns request/response adaptation may continue to use the framework integration boundary directly.

Choose one runtime/emission path at bootstrap and keep it stable for the worker lifecycle.

Foundation / application handoff
--------------------------------

Foundation or another framework should consume the Runwire bridge from outside Webrick rather than becoming a Webrick dependency.

The host integration should:

1. own one application ``ContainerBuilder`` and contribute Webrick/application definitions to that graph;
2. compile InterMix and Webrick artifacts as one immutable release set;
3. boot one ``CompiledRouterKernel`` per worker/process lifecycle;
4. choose direct SAPI/direct Webrick runtime adapters or the Runwire factory at host bootstrap;
5. wire the existing InterMix runtime reset into Runwire's request-cleanup slot for persistent execution;
6. avoid a second response emitter/completion path;
7. preserve Webrick's zero-scope compiled route path when a route needs neither a ``Request`` nor scoped DI.

No Foundation, Infbyte or application package is required by Webrick for this contract.

Release checklist
-----------------

- Compile and deploy the coordinated InterMix + Webrick release artifacts before worker start.
- Keep Runwire optional unless the selected host runtime actually uses it.
- Instantiate ``RuntimeServer`` / ``RunwireRuntimeApplicationFactory`` once per application lifecycle, not per request.
- Let Runwire own cancellation, deadlines, admission, drain and shutdown.
- Let Webrick own HTTP response semantics and writer completion exactly once.
- Use Runwire's request-cleanup lifecycle slot for the defensive InterMix carrier reset.
- Do not retain native request/response handles, Webrick ``Request`` objects or scoped services in process-global/static state.
- Benchmark the selected runtime with representative traffic; microbenchmarks describe bridge overhead, not sustainable end-to-end throughput.

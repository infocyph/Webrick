Runwire Runtime
===============

Webrick 6 can run behind Runwire 2.1 through its optional native runtime bridge. Runwire is **not** a production dependency of Webrick: ordinary SAPI/shared-hosting installs and Webrick's direct Swoole/OpenSwoole, RoadRunner and Workerman adapters remain first-class paths.

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

InterMix 11 borrowed request scope
----------------------------------

When the host also uses InterMix's Runwire integration, register its runtime
inputs on the application ``ContainerBuilder`` before the graph is finalized:

.. code:: php

   use Infocyph\InterMix\Integration\Runwire\RunwireIntegration;
   use Infocyph\Webrick\Runtime\Http\RunwireInterMixScopeBridge;

   RunwireInterMixScopeBridge::registerInputs($builder);

After Runwire selects a concrete ``RuntimeContext``, bind the host-owned
``RunwireIntegration`` to that exact runtime and supply it to
``RunwireRuntimeAdapter``:

.. code:: php

   $interMixRunwire = new RunwireIntegration($container);
   $interMixRunwire->bind($runtimeContext);

   $adapter = new RunwireRuntimeAdapter(
       runtimeContext: $runtimeContext,
       interMix: $interMixRunwire,
   );
   $server = new RuntimeServer($kernel, $adapter);

For a host that already owns a live InterMix ``ScopeContext``, supply a
``scopeContext`` resolver to the adapter. Webrick then attaches that exact
scope through InterMix 11 instead of opening a second request scope. An optional
``coroutineScope`` resolver can expose the exact Runwire ``CoroutineScope`` to
injected consumers when the selected runtime supports Runwire coroutines.

The bridge rejects completed request contexts, mismatched runtime identities and
an InterMix integration belonging to a different container. Without this
optional bridge, compiled routes keep the ordinary Webrick scope behavior and
zero-scope routes stay on the direct fast path.

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

The cleanup callback is a defensive carrier-local reset after normal scope unwinding. It does not replace InterMix 11's own ``withinScope()`` / ``withinScopeContext()`` cleanup and must not become a request-ID keyed scope registry.

Response completion
-------------------

``RunwireRuntimeAdapter`` is the single owner of Runwire response-writer completion for a Webrick request. ``RunwireRuntimeApplication`` deliberately forwards requests to Runwire's lifecycle with lifecycle-level response completion disabled, even when a host calls ``handle(..., completeResponse: true)``.

This prevents duplicate ``end()`` calls and keeps lazy/streamed Webrick response production inside the owning request scope until body production finishes.

Runwire 2.1 terminal observers remain authoritative for lifecycle completion:
writer ``onTerminal()`` observers fire exactly once, including late
registration after the response has already ended. ``RunwireRuntimeApplication``
also forwards Runwire's ``healthy()`` and ``healthFailure()`` state so host
health transitions are not reimplemented in Webrick.

Webrick owns only its inner I/O continuation Fiber. Body-readiness and
backpressure callbacks may resume that inner Fiber; ordinary handler suspension
is returned to the caller-owned Fiber and Webrick never resumes a
scheduler-owned Fiber behind its owner.

Request bodies and forms
------------------------

Runwire may dispatch an application after the HTTP request head is normalized while body bytes are still arriving. ``RunwireRequestBodyStream`` therefore keeps the body incremental and, only inside the managed Runwire application continuation, suspends the owning request Fiber until body data, body completion or request cancellation becomes visible. It does not spin, block the process or introduce a second input queue.

Ordinary routing and request creation still do not consume the body. APIs that explicitly require the complete payload, such as Webrick raw/JSON/XML parsing, may consume the remaining bounded Runwire stream and wait for later chunks as needed. A caller that reads only a prefix leaves the remainder unread.

``application/x-www-form-urlencoded`` payloads are parsed lazily by Webrick's request layer when the selected runtime did not already provide parsed form data. If form ``_method`` routing is explicitly enabled, the Runwire adapter consumes that URL-encoded form only because routing itself requires it, then replays the same bytes/parsed values into the eventual Webrick ``Request`` so the body is not lost.

Native Runwire ``multipart/form-data`` has a narrower Webrick 6 boundary. Runwire 2.1 exposes the multipart payload as a bounded request stream; Webrick does **not** add a second multipart decoder, temporary-file manager or upload-storage policy in this runtime adapter. The raw multipart body remains available to the consuming framework/application decoder. Direct adapters such as SAPI, Swoole/OpenSwoole, Workerman or RoadRunner may continue to pass host-parsed uploaded-file structures when their native request API already provides them. A future Webrick-native multipart subsystem, if desired, should be designed and security-reviewed independently rather than hidden inside the Runwire transport adapter.

Streaming, backpressure and cancellation
----------------------------------------

Response streaming uses Runwire's ``WriteResult`` and ``onDrain()`` contract. When a non-terminal ``start()`` or ``write()`` is accepted but pressured, Webrick suspends only its response-production continuation until Runwire signals drain or the authoritative Runwire request context is cancelled. Webrick does not create a second output queue.

Cancellation or deadline expiry wakes an input/body wait or response-pressure wait so the request lifecycle can unwind immediately. Webrick stops further response production, does not call ``end()`` after cancellation, and Runwire's lifecycle performs normal request completion/cleanup.

A pressured terminal ``end()`` is already an accepted terminal response from Webrick's application-lifetime perspective. Webrick therefore does not retain request-scoped application state merely to wait for the lower transport buffer to flush after the writer has ended.

Deployment modes covered
------------------------

The Webrick 6 acceptance suite verifies the Runwire application factory against:

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
5. when using the InterMix 11 Runwire integration, bind it to the exact Runwire runtime and borrow the host request/scope context rather than creating a parallel scope;
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
- Use Runwire's request-cleanup lifecycle slot only for defensive carrier reset; request/scope ownership stays with Runwire + InterMix 11.
- Keep native Runwire request bodies streaming; only explicit full-payload consumers should materialize them.
- Treat native Runwire multipart decoding/upload storage as an application/framework concern in Webrick 6 rather than assuming host-populated upload arrays.
- Do not retain native request/response handles, Webrick ``Request`` objects or scoped services in process-global/static state.
- Benchmark the selected runtime with representative traffic; microbenchmarks describe bridge overhead, not sustainable end-to-end throughput.

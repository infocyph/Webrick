Logging And Telemetry
=====================

Use ``TelemetryMiddleware`` directly when you only need a short one-off setup. Use ``TelemetryOptions`` when you want one reusable telemetry profile across kernels, tests, or environment-specific bootstraps.

One-Off Configuration
---------------------

.. code:: php

   use Infocyph\Webrick\Middleware\TelemetryMiddleware;

   $kernel = RouterKernel::bootWithRegistrar(
       log: $logger,
       matcher: $matcher,
       register: $register,
       preGlobal: [
           new TelemetryMiddleware(
               $logger,
               addXResponseTime: true,
               emitRequestId: true,
               emitTraceparentHeader: true,
           ),
       ],
   );

Prefer this when:

- the setup is local to one kernel
- you only need a few non-default flags

Reusable Profile
----------------

.. code:: php

   use Infocyph\Webrick\Middleware\TelemetryMiddleware;
   use Infocyph\Webrick\Support\TelemetryOptions;

   $telemetryOptions = new TelemetryOptions(
       log: $logger,
       requestIdHeader: 'Request-Id',
       emitTraceparentHeader: true,
       nelGroup: 'default',
       nelEndpoint: 'https://reports.example.com/nel',
   );

   $telemetry = TelemetryMiddleware::fromOptions($telemetryOptions);

   $kernel = RouterKernel::bootWithRegistrar(
       log: $logger,
       matcher: $matcher,
       register: $register,
       preGlobal: [$telemetry],
   );

Prefer this when:

- the constructor call is getting long
- you want the same telemetry setup in multiple kernels
- tests should reuse the exact same config object

Inspecting The Active Profile
-----------------------------

.. code:: php

   $activeOptions = $telemetry->options();

   echo $activeOptions->requestIdHeader; // Request-Id

This is useful when boot code decorates middleware and you want to verify the resolved telemetry profile in tests.

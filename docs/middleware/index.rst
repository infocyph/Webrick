Middleware
==========

Webrick’s pipeline is **pre-global → route middleware → handler → post-global unwind**. Keep the portable stack small and let compiled production prepare route/global middleware before traffic.

Core sets
---------

- **Pre-global**: gateway hardening, request limits, throttle, optional cookie encryption, negotiation, response cache, cache validators, telemetry where required.
- **Post-global**: compression, CORS/security policy application, Vary accumulation.
- **Development/test only**: response linter.
- **Explicit transformation only**: input sanitizer. Do not register blanket sanitization as a security control.

HTTP method normalization is performed at the request/runtime boundary once. There is no ``NormalizeMethodMiddleware`` in Webrick 5.

Why order matters
-----------------

- Reject invalid/oversized requests before expensive application work.
- Negotiate before handlers that consume negotiated request attributes.
- Cache/validator middleware can short-circuit before body construction.
- Compression runs after representation construction.
- Vary accumulation observes every policy that changes the selected representation.

Quick links
-----------

- `Cache Validators <./cache-validators.rst>`__
- `Compression <./compression.rst>`__
- `Cookie Encryption <./cookie-encryption.rst>`__
- `Negotiation <./negotiation.rst>`__
- `CORS & Policies <./cors-and-policies.rst>`__
- `Gateway Hardening <./gateway-hardening.rst>`__
- `Input Sanitizer <./input-sanitizer.rst>`__
- `Maintenance Mode <./maintenance-mode.rst>`__
- `Request Limits <./request-limits.rst>`__
- `Response Cache <./response-cache.rst>`__
- `Response Linter <./response-linter.rst>`__
- `Throttle <./throttle.rst>`__
- `Telemetry <./telemetry.rst>`__
- `Vary Accumulator <./vary-accumulator.rst>`__

Example development stack
-------------------------

.. code:: php

   preGlobal: [
       \Infocyph\Webrick\Middleware\GatewayHardeningMiddleware::class,
       \Infocyph\Webrick\Middleware\RequestLimitsMiddleware::class,
       \Infocyph\Webrick\Middleware\NegotiationMiddleware::class,
       \Infocyph\Webrick\Middleware\ResponseCacheMiddleware::class,
       \Infocyph\Webrick\Middleware\CacheValidatorsMiddleware::class,
   ],
   postGlobal: [
       \Infocyph\Webrick\Middleware\CompressionMiddleware::class,
       \Infocyph\Webrick\Middleware\CorsAndPoliciesMiddleware::class,
       \Infocyph\Webrick\Middleware\VaryAccumulatorMiddleware::class,
   ]

Production should compile the selected middleware graph through ``RouteCompiler`` / ``ReleaseCompiler`` and boot ``CompiledRouterKernel``.

.. toctree::
   :maxdepth: 2
   :hidden:
   :caption: Middleware

   overview
   aliases
   cache-validators
   compression
   cookie-encryption
   cors-and-policies
   gateway-hardening
   input-sanitizer
   maintenance-mode
   negotiation
   request-limits
   response-cache
   response-linter
   telemetry
   throttle
   vary-accumulator

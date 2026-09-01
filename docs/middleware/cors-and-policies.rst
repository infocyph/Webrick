CORS & Policies
===============

Handle cross-origin requests correctly and ship modern security headers from one place. This middleware takes care of **CORS** (preflight + simple requests) and **security policies** (CSP, Referrer-Policy, Permissions-Policy, etc.) consistently.

--------------

What it does
------------

- **CORS**

  - Responds to **preflight** ``OPTIONS`` with configured ``Access-Control-*`` headers
  - Adds ``Access-Control-Allow-Origin`` and friends on **actual** requests when allowed
  - Supports allowlists (exact origins, wildcards, or regex), allowed methods/headers, credentials and ``Vary: Origin``

- **Security headers**

  - ``Content-Security-Policy`` (CSP)
  - ``Referrer-Policy``
  - ``Permissions-Policy`` (a.k.a. Feature-Policy)
  - ``X-Content-Type-Options: nosniff``, ``X-Frame-Options``, ``Cross-Origin-Opener-Policy``, ``Cross-Origin-Resource-Policy``

- Plays nicely with **Vary Accumulator** and **Compression**

--------------

Wiring
------

Put it in **post-global** (so it can finalize headers after your handler), typically **before** the Vary accumulator:

.. code:: php

   $postGlobal = [
     \Infocyph\Webrick\Middleware\CompressionMiddleware::class,
     \Infocyph\Webrick\Middleware\CorsAndCorsAndPoliciesMiddleware::class,
     \Infocyph\Webrick\Middleware\VaryAccumulatorMiddleware::class,
   ];

If your middleware short-circuits **preflight OPTIONS** (common), it can also run early; just ensure final ``Vary`` is consolidated later.

--------------

Configuration (typical)
-----------------------

*(Adapt names to your constructor/options.)*

.. code:: php

   new \Infocyph\Webrick\Middleware\CorsAndCorsAndPoliciesMiddleware(
     cors: [
       'allow_origins'      => ['https://app.example.com', 'https://*.partner.com'], // or ['*'] (no credentials)
       'allow_methods'      => ['GET','POST','PUT','PATCH','DELETE'],
       'allow_headers'      => ['Content-Type','Authorization','X-Requested-With'],
       'expose_headers'     => ['Content-Length','ETag'],
       'allow_credentials'  => true,            // requires non-* origin echoes
       'max_age'            => 600,             // seconds for preflight caching
     ],
     policies: [
       'csp'                => "default-src 'self'; img-src 'self' data:; script-src 'self'; style-src 'self' 'unsafe-inline'",
       'referrer_policy'    => 'strict-origin-when-cross-origin',
       'permissions_policy' => 'geolocation=(), microphone=(), camera=()',
       'x_frame_options'    => 'DENY',          // or SAMEORIGIN
       'x_content_type'     => 'nosniff',
       'cross_origin_opener_policy'   => 'same-origin',
       'cross_origin_resource_policy' => 'same-origin',
     ]
   );

--------------

CORS behavior
-------------

Preflight (OPTIONS)
~~~~~~~~~~~~~~~~~~~

If a request contains ``Origin`` + ``Access-Control-Request-Method``, respond:

- ``Access-Control-Allow-Origin: <echoed origin>`` (if allowed)
- ``Access-Control-Allow-Methods: ...``
- ``Access-Control-Allow-Headers: ...``
- ``Access-Control-Allow-Credentials: true`` (only when echoing specific origin)
- ``Access-Control-Max-Age: 600`` (cache preflight)

Return **204** (no body) or **200** with empty body. Add ``Vary: Origin`` (and ``Vary: Access-Control-Request-Headers`` when appropriate).

Simple/actual requests
~~~~~~~~~~~~~~~~~~~~~~

If allowed, attach:

- ``Access-Control-Allow-Origin`` (echo origin or ``*``—but ``*`` forbids credentials)
- ``Access-Control-Allow-Credentials: true`` (only when echoing explicit origin)
- ``Access-Control-Expose-Headers`` (if exposing custom headers)

..

   Don’t reflect arbitrary origins; validate against an allowlist or pattern.

--------------

Security policies (quick primer)
--------------------------------

- **CSP** controls which sources can load (script, style, connect, img, frame). Start with a strict policy and relax as needed.
- **Referrer-Policy** protects sensitive URLs from leaking to external sites.
- **Permissions-Policy** turns off device features by default.
- **X-Frame-Options** protects from clickjacking (use ``DENY`` or ``SAMEORIGIN``).
- **X-Content-Type-Options: nosniff** prevents MIME-sniffing.
- **COOP/CORP** (Cross-Origin-Opener/Resource Policy) harden document isolation for modern browsers.

--------------

Examples
--------

Allow a single app origin with credentials
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

.. code:: php

   cors: {
     allow_origins: ['https://app.example.com'],
     allow_methods: ['GET','POST'],
     allow_headers: ['Content-Type','Authorization'],
     allow_credentials: true,
     max_age: 600
   }

Response for preflight:

::

   HTTP/1.1 204 No Content
   Access-Control-Allow-Origin: https://app.example.com
   Access-Control-Allow-Methods: GET, POST
   Access-Control-Allow-Headers: Content-Type, Authorization
   Access-Control-Allow-Credentials: true
   Access-Control-Max-Age: 600
   Vary: Origin

Public read-only API (no credentials)
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

.. code:: php

   cors: {
     allow_origins: ['*'],
     allow_methods: ['GET'],
     allow_headers: ['*'],
     allow_credentials: false,
   }

--------------

Interplay with other middleware
-------------------------------

- **Vary Accumulator**: ensure ``Origin`` gets added when you vary by it.
- **Throttle**: preflights shouldn’t consume user quotas—short-circuit quickly.
- **Compression**: headers-only preflights won’t be compressed; normal.

--------------

Testing
-------

.. code:: bash

   # Preflight
   curl -i -X OPTIONS \
     -H "Origin: https://app.example.com" \
     -H "Access-Control-Request-Method: POST" \
     -H "Access-Control-Request-Headers: Authorization, Content-Type" \
     http://127.0.0.1:8000/your-endpoint

   # Simple request
   curl -i -H "Origin: https://app.example.com" http://127.0.0.1:8000/your-endpoint

--------------

Troubleshooting
---------------

+--------------------------------+----------------------------------------------------+-------------------------------------------------------------------------------------------------------------------+
| Symptom                        | Likely cause                                       | Fix                                                                                                               |
+================================+====================================================+===================================================================================================================+
| Browser blocks with CORS error | Origin not allowed or credentials + ``*``          | Echo explicit origin and set ``allow_credentials: true``                                                          |
+--------------------------------+----------------------------------------------------+-------------------------------------------------------------------------------------------------------------------+
| Preflight not cached           | ``Access-Control-Max-Age`` missing                 | Configure ``max_age``                                                                                             |
+--------------------------------+----------------------------------------------------+-------------------------------------------------------------------------------------------------------------------+
| “Blocked by frame options”     | ``X-Frame-Options: DENY`` conflicts with embedding | Use ``SAMEORIGIN`` or set explicit ``frame-ancestors`` in CSP                                                     |
+--------------------------------+----------------------------------------------------+-------------------------------------------------------------------------------------------------------------------+
| CSP violations in console      | Policy too strict                                  | Relax specific directives (``script-src``, ``connect-src``, etc.) and prefer nonces/hashes over ``unsafe-inline`` |
+--------------------------------+----------------------------------------------------+-------------------------------------------------------------------------------------------------------------------+

--------------

Checklist
---------

- ☐ Define an explicit **origin allowlist** (avoid reflecting arbitrary origins)
- ☐ Use ``*`` only for public, non-credentialed endpoints
- ☐ Add ``Vary: Origin`` (accumulator can help)
- ☐ Ship sensible **security headers** (CSP, Referrer-Policy, Permissions-Policy, nosniff, frame options)
- ☐ Cache preflights with ``Access-Control-Max-Age``
- ☐ Expose only necessary headers with ``Access-Control-Expose-Headers``

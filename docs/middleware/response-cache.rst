Response Cache
==============

Serve cached responses for safe requests (typically **GET/HEAD**) without running handlers. This middleware provides a fast-path lookup and a coherent invalidation story that plays well with **ETag/Last-Modified** and **Compression**.

``ResponseCacheMiddleware`` consumes a PSR-6 pool. CacheLayer 4 is the optional Webrick-tested provider used when no explicit pool is supplied:

.. code:: bash

   composer require infocyph/cachelayer:^4.0

Core routing does not load, initialize, or require CacheLayer. Applications may pass any compatible ``Psr\Cache\CacheItemPoolInterface`` explicitly.

Persistent and prefork deployments
----------------------------------

Construct worker-local cache resources and client connections after a process
fork. The host owns connection lifecycle for Redis/Memcached/database-backed
pools and should inject the resulting PSR-6 pool into Webrick.

Do not silently replace an application-required shared cache/counter with
process-local memory when the shared backend is unavailable. Response caching
itself is an optimization and Webrick treats operational cache failures as
misses, but security/reliability policy for a required shared backend belongs to
the host application.

Webrick 6 rotates disposable/security-related namespaces for the CacheLayer 4
upgrade: response entries use ``webrick.hr.v4.``, throttle counters use
``webrick.th.v3.``, and encrypted-cookie backing entries use
``enc_cookie.v2.``. Mixed CacheLayer 3.x/4.x writers should not share those
entries; expire or migrate old backing state explicitly during rollout.

--------------

Configuration
-------------

.. code:: php

   use Infocyph\Webrick\Middleware\ResponseCacheMiddleware;
   use Infocyph\CacheLayer\Cache\Cache;

   $preGlobal[] = new ResponseCacheMiddleware(
       store: Cache::file('webrick.http'),        // PSR-6 cache pool
       ttlSeconds: 10,                            // Base TTL (micro-cache strategy)
       includeQuery: true,                        // Include query params in cache key
       maxBodyBytes: 1_048_576,                   // Max body size to cache (1MB)
       defaultVary: ['Accept', 'Accept-Language', 'Accept-Encoding'],
       skipWhenPersonalized: true,                // Don't cache Set-Cookie responses
       respectResponseCacheControl: true,         // Honor no-store/private directives
       avoidSetCookie: true                       // Skip caching if Set-Cookie present
   );

Constructor Parameters
~~~~~~~~~~~~~~~~~~~~~~

+---------------------------------+-----------------------------------------------+---------------------+--------------------------------------------------+
| Parameter                       | Type                                          | Default             | Description                                      |
+=================================+===============================================+=====================+==================================================+
| ``store``                       | ``?Psr\Cache\CacheItemPoolInterface``          | ``null``            | PSR-6 pool; CacheLayer 4 provides the default store |
+---------------------------------+-----------------------------------------------+---------------------+--------------------------------------------------+
| ``ttlSeconds``                  | ``int``                                       | ``10``              | Default TTL for cached responses                 |
+---------------------------------+-----------------------------------------------+---------------------+--------------------------------------------------+
| ``includeQuery``                | ``bool``                                      | ``true``            | Include query string in cache key                |
+---------------------------------+-----------------------------------------------+---------------------+--------------------------------------------------+
| ``maxBodyBytes``                | ``int``                                       | ``1048576``         | Maximum response body size to cache (bytes)      |
+---------------------------------+-----------------------------------------------+---------------------+--------------------------------------------------+
| ``defaultVary``                 | ``array<string>``                             | ``['Accept', ...]`` | Default Vary dimensions to include in key        |
+---------------------------------+-----------------------------------------------+---------------------+--------------------------------------------------+
| ``skipWhenPersonalized``        | ``bool``                                      | ``true``            | Skip caching if response varies by user          |
+---------------------------------+-----------------------------------------------+---------------------+--------------------------------------------------+
| ``respectResponseCacheControl`` | ``bool``                                      | ``true``            | Honor Cache-Control directives from response     |
+---------------------------------+-----------------------------------------------+---------------------+--------------------------------------------------+
| ``avoidSetCookie``              | ``bool``                                      | ``true``            | Never cache responses with Set-Cookie header     |
+---------------------------------+-----------------------------------------------+---------------------+--------------------------------------------------+

--------------

How It Keys
-----------

Webrick uses the versioned ``webrick.hr.v4.`` namespace followed by a compact base64url SHA-256 digest of this logical identity:

::

   {method}\0{scheme}\0{host[:port]}\0{path}\0{exact_raw_query}\0{media}\0{charset}\0{locale}\0{vary_surface}

**Example**:

::

   GET|example.com|/products/42|sort=price|application/json|utf-8|en|br|Accept:application/json|Accept-Language:en

**Hashed**: Uses a full SHA-256 digest so distinct request variants cannot silently share a cache entry:

::

   9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08

What Affects the Key
~~~~~~~~~~~~~~~~~~~~

1. **HTTP Method**: GET, HEAD (others not cached)
2. **Host + Path**: ``/users/42`` on ``api.example.com``
3. **Exact raw query string** (if ``includeQuery: true``): byte-distinct query spellings and pair order remain distinct; Webrick does not parse/re-serialize the query before hashing.
4. **Negotiated Content**:

   - Media type: ``application/json``
   - Charset: ``utf-8``
   - Locale: ``en``
   - Encoding: ``br`` (if cached post-compression)

5. **Vary Surface**: Actual header values for ``Vary`` dimensions

**Not Included** (by design):

- Request headers like ``User-Agent``, ``Referer``
- Credentials and cookies (those requests bypass this shared cache)
- Request body
- Time of request

--------------

Method semantics
----------------

GET and HEAD deliberately retain separate cache identities. Webrick supports explicit HEAD routes whose metadata may differ from GET, and this middleware cannot safely infer whether a HEAD match was explicit or implicit. Correctness takes precedence over sharing those entries.

--------------

Wiring
------

Place in **pre-global**, after negotiation and before cache validators (or alongside, depending on your strategy):

.. code:: php

   $preGlobal = [
     // hardening, telemetry, limits, throttle, cookies, normalize, sanitizer...
     \Infocyph\Webrick\Middleware\NegotiationMiddleware::class,
     \Infocyph\Webrick\Middleware\ResponseCacheMiddleware::class,
     \Infocyph\Webrick\Middleware\CacheValidatorsMiddleware::class,
     // ... router → post-globals (compression, CORS, vary) ...
   ];

On a warm hit the handler is skipped. Keep middleware ordering explicit and test it with the validators and compression policy used by your application.

--------------

What gets cached (and what doesn’t)
-----------------------------------

**Cacheable by default:**

- Supported status responses to anonymous **GET/HEAD** requests
- Responses without private state, unsafe ``Vary``, or streaming bodies

**Not cached:**

- Requests containing ``Authorization`` or ``Cookie``
- ``Set-Cookie`` responses
- ``Cache-Control: no-store`` or ``private`` (by default)
- Non-idempotent methods (POST/PUT/PATCH/DELETE)
- Streaming/SSE responses (size indeterminate)
- Responses larger than ``maxBodyBytes``
- ``Vary: *``, credential-based Vary, or Vary dimensions absent from the key

--------------

TTLs & invalidation
-------------------

The effective TTL starts at ``ttlSeconds`` and is capped by response ``s-maxage`` or ``max-age``. A zero effective TTL is not stored. Invalidation, tags, stale-while-revalidate, and stampede protection are backend/application policy; this middleware does not expose those APIs.

--------------

Cooperation with ETags & Compression
------------------------------------

Cached status, headers, and body are restored together. ``Accept-Encoding`` is in the default key surface, so compressed variants remain separate. Verify the chosen middleware ordering because it determines whether cached bytes are pre- or post-compression and whether validators are already attached.

--------------

Controlling cacheability in handlers
------------------------------------

Use headers to guide the middleware:

.. code:: php

   return Response::json($data)
     ->withHeader('Cache-Control', 'public, max-age=60'); // cache for 60s

Opt-out:

.. code:: php

   return Response::json($data)
     ->withHeader('Cache-Control', 'no-store');

Example
-------

Cached product page
~~~~~~~~~~~~~~~~~~~

.. code:: php

   Route::get('/products/{id:int}', function (int $id) {
     $product = repo()->getProduct($id); // DB call
     return Response::json($product)
       ->withHeader('Cache-Control', 'public, max-age=120');
   }, 'products.show');

Subsequent anonymous GETs within the effective TTL reuse the cached response. Cache backend read/write failures fail open: the request continues through the handler and a valid downstream response is preserved.

--------------

Troubleshooting
---------------

+---------------------------+---------------------------------------------------------+-----------------------------------------------------------------------------------+
| Symptom                   | Likely cause                                            | Fix                                                                               |
+===========================+=========================================================+===================================================================================+
| Fresh changes not visible | Entry remains within its TTL                            | Lower TTLs or clear the configured backend during deployment/update               |
+---------------------------+---------------------------------------------------------+-----------------------------------------------------------------------------------+
| Wrong variant served      | Missing ``Vary`` dimensions in key                      | Include ``media`` / ``locale`` / ``Accept-Encoding`` as appropriate               |
+---------------------------+---------------------------------------------------------+-----------------------------------------------------------------------------------+
| Auth request not cached   | Shared-cache privacy guard                              | Expected: credential and cookie requests bypass this middleware                   |
+---------------------------+---------------------------------------------------------+-----------------------------------------------------------------------------------+
| No caching happening      | Privacy signal, stream, size, status, or backend policy | Check request/response headers and constructor limits                             |
+---------------------------+---------------------------------------------------------+-----------------------------------------------------------------------------------+
| Double compression        | Cache/compression middleware ordered inconsistently     | Choose whether cache stores pre- or post-compression bytes and test that ordering |
+---------------------------+---------------------------------------------------------+-----------------------------------------------------------------------------------+

--------------

Checklist
---------

- ☐ Add ResponseCache early in **pre-global** (after negotiation)
- ☐ Confirm host, port, query, negotiation, and Vary dimensions match the application
- ☐ Respect ``Cache-Control``; set TTLs in handlers
- ☐ Coordinate with **ETag**/**Compression** strategies
- ☐ Clear or invalidate the supplied backend when underlying data changes
- ☐ Monitor the supplied backend and adjust TTLs from measured behavior

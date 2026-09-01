Vercel & Serverless
===================

Running Webrick in serverless/edge environments requires a slightly different shape: **single-binary boot**, **fast cold-start** and **stateless caches**. This page sketches patterns for Vercel, AWS Lambda (via API Gateway/Lambda@Edge) and Cloudflare Workers (edge JS).

   TL;DR: PHP is a great fit on serverless **containers** (Lambda with PHP Runtime, Vercel’s PHP runtime). For **edge JS-only** platforms (Cloudflare Workers), place Webrick behind an edge proxy or re-implement only the thinnest endpoints at the edge.

--------------

Principles
----------

- **Precompute**: ship a prebuilt **route cache** and classmap (Composer ``--classmap-authoritative``).
- **Light boot**: keep the front controller small; avoid dynamic scanning.
- **Immutable code**: no writes outside ``/tmp`` (or platform temp dir).
- **Stateless**: use external stores for session, response cache and rate limits.
- **Short timeouts**: streaming and long-running jobs are a poor fit; offload to queues/cron.

--------------

Vercel (Serverless Functions)
-----------------------------

Vercel supports PHP runtimes that execute per-request. Use the community PHP runtime (e.g., ``vercel-php``) or a custom runtime container.

Files
~~~~~

::

   api/
   └─ index.php          # your front controller (adapted)
   vercel.json
   composer.json
   vendor/
   .route-cache/     # prebuilt (checked into artifact)

``vercel.json`` (example)
~~~~~~~~~~~~~~~~~~~~~~~~~

.. code:: json

   {
     "functions": {
       "api/index.php": {
         "runtime": "vercel-php@0.6.0",
         "memory": 512,
         "maxDuration": 10
       }
     },
     "routes": [
       { "src": "/(.*)", "dest": "/api/index.php" }
     ]
   }

..

   Pin a runtime version; set ``memory`` and ``maxDuration`` per endpoint needs.

Front controller tweaks
~~~~~~~~~~~~~~~~~~~~~~~

- Do **not** write caches at runtime—**read** from ``.route-cache/`` if present.
- Put ephemeral files in ``sys_get_temp_dir()`` when absolutely necessary.

.. code:: php

   $kernel = RouterKernel::bootWithRegistrar(
     /* ... */
     /* ... */
       invoker: $invoker,
   );

What to avoid / adapt
~~~~~~~~~~~~~~~~~~~~~

- **Streaming/SSE**: Vercel serverless functions buffer responses; prefer short responses or move streams behind a persistent service.
- **File downloads**: write to temp and return, or stream from object storage (S3).
- **Response cache**: back with Redis (Upstash) or Vercel KV; don’t use in-memory cache.

--------------

AWS Lambda (API Gateway)
------------------------

Use a PHP Lambda runtime (Bref is the de-facto standard). It maps API Gateway requests to your PHP entrypoint.

Composer & Bref
~~~~~~~~~~~~~~~

.. code:: bash

   composer require bref/bref

``serverless.yml`` (Serverless Framework + Bref)
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

.. code:: yaml

   service: webrick
   provider:
     name: aws
     region: ap-south-1
     runtime: provided.al2
     environment:
       WEBRICK_SIGN_KEY: ${env:WEBRICK_SIGN_KEY}
       WEBRICK_COOKIE_KEY: ${env:WEBRICK_COOKIE_KEY}
   plugins:
     - ./vendor/bref/bref

   functions:
     http:
       handler: public/index.php
       layers:
         - ${bref:layer.php-84}
       events:
         - httpApi:
             method: ANY
             path: /{proxy+}
       timeout: 10
       memorySize: 512

**Front controller**: same as normal, but:

- Ensure **route cache** is bundled (read-only).
- Use ``/tmp`` for transient files.
- Prefer external stores: DynamoDB/ElastiCache/MemoryDB for rate limits and caches.

**Binary responses**: Configure API Gateway **binary media types** if returning images/files.

**Cold starts**: Keep dependency list lean; pre-warm with provisioned concurrency for critical paths.

--------------

Lambda@Edge / CloudFront
------------------------

If you need **signed URL verification** or simple rewrites at the edge:

- Keep Webrick in **origin** (Lambda or container).

- Use **Lambda@Edge** (Node/Python) functions to:

  - Rewrite ``/old/* → /new/*``
  - Add/remove headers (e.g., ``X-Forwarded-Proto``)
  - Early deny bots with cheap checks

- Let Webrick handle app logic, signed URLs, throttling, etc., at the origin.

--------------

Cloudflare Workers (and other JS-only edges)
--------------------------------------------

Workers don’t run PHP. Two common patterns:

1. **Edge proxy → origin PHP**

   - Worker handles **micro-decisions** (bot deny, geo-routing, A/B flags).
   - Proxies to your PHP origin (Fly.io, Render, ECS, etc.).
   - Cache **public GETs** at the edge using ``cache.put()`` with ``Vary`` that mirrors your app.

2. **Hybrid endpoints**

   - Implement **static** or **ultra-simple** endpoints at the edge (e.g., ``/status``, ``/robots.txt``).
   - All dynamic routes go to origin.

..

   Keep signatures cohesive: if generating **signed URLs** at the edge, share the **same key** via secrets and keep time skew in mind.

--------------

Externalizing state (must-do)
-----------------------------

- **Response Cache** → Redis/Upstash/ElastiCache (include ``media``, ``locale``, ``Accept-Encoding`` in keys if you store encoded bytes).
- **Throttle** → Redis atomic ops (INCR/LUA); do not use local memory.
- **Sessions** (if you use them) → Redis or JWT + encrypted cookies.
- **File storage** → S3/GCS; serve via signed URLs; Webrick endpoint only issues the link.

--------------

Observability on serverless
---------------------------

- **Request ID**: still emit ``X-Request-Id`` so logs correlate across cold starts.
- **Server-Timing**: useful, but some platforms strip it—verify.
- Centralize logs in CloudWatch (Lambda), Vercel logs, or a third-party collector.
- Export lightweight metrics via embedded statsd/OTEL where possible, or infer from access logs.

--------------

Timeouts & limits
-----------------

Cold starts, execution limits, streaming support, and compression behavior are platform- and plan-specific and change over time. Check the provider's current documentation during deployment, measure cold and warm requests, and avoid double compression when the edge already transforms the response. Ensure the ETag strategy accounts for any byte transformation.

--------------

Example: S3 download via signed URL
-----------------------------------

Instead of streaming large files from a function:

1. Webrick endpoint authenticates request and generates a **temporary** S3 presigned URL.
2. Client downloads directly from S3 (no compute time billed).

.. code:: php

   Route::get('/files/{id:int}', function ($r, int $id) {
     // check ACLs...
     $s3Url = issueS3PresignedUrl("files/{$id}.zip", ttl: 900);
     return Response::redirect($s3Url, 302);
   }, ['middleware'=>['verifySignedUrl']]);

--------------

Build & ship checklist (serverless)
-----------------------------------

- ☐ ``composer install --no-dev --classmap-authoritative``
- ☐ **Route cache prebuilt** and shipped with artifact
- ☐ No runtime writes except ``/tmp`` (or platform temp)
- ☐ Externalized **response cache**, **throttle**, **sessions**
- ☐ Short timeouts; avoid streaming; prefer redirects to object storage
- ☐ Observability headers & logs wired (``X-Request-Id``, Server-Timing if available)
- ☐ Secrets via platform store (Vercel env vars, AWS Secrets Manager/SSM)

--------------

Troubleshooting
---------------

+----------------------------------------+-----------------------------------+--------------------------------------------------------------+
| Symptom                                | Likely cause                      | Fix                                                          |
+========================================+===================================+==============================================================+
| Long cold starts                       | Heavy autoload or attribute scans | Prebuild route cache; use classmap authoritative; trim deps  |
+----------------------------------------+-----------------------------------+--------------------------------------------------------------+
| 502/timeout on large responses         | Platform buffering/limits         | Redirect to object storage; chunk on origin behind a CDN     |
+----------------------------------------+-----------------------------------+--------------------------------------------------------------+
| Throttle inconsistent across instances | Local/in-memory store             | Move to Redis/Upstash/Dynamo with atomic ops                 |
+----------------------------------------+-----------------------------------+--------------------------------------------------------------+
| Signed URLs invalid intermittently     | Clock skew                        | Ensure platform times are NTP-synced; add small TTL buffer   |
+----------------------------------------+-----------------------------------+--------------------------------------------------------------+
| “Works locally, 404 on platform”       | Wrong entry mapping               | Check ``vercel.json`` routes or API Gateway mapping template |
+----------------------------------------+-----------------------------------+--------------------------------------------------------------+

Request Limits
==============

RequestLimitsMiddleware enforces application-level HTTP header-count, header
byte-size, and request-body byte limits. It does NOT implement upload-duration,
idle-read, multipart-part, per-file, or connection timeouts. Enforce those
properties at the HTTP transport/reverse-proxy and application storage boundary.

Configure limits at the composition root and install the middleware in the
pre-global request pipeline, before expensive work.

Configuration
-------------

.. code-block:: php

   use Infocyph\Webrick\Middleware\RequestLimitsMiddleware;

   $preGlobal = [
       new RequestLimitsMiddleware(
           maxHeaderBytes: 8_192,
           maxHeaderCount: 100,
           maxBodyBytes: 10 * 1024 * 1024,
           bodyLimitVerbs: ['POST', 'PUT', 'PATCH', 'DELETE'],
       ),
   ];

Constructor parameters (these are the complete supported options):

.. list-table::
   :header-rows: 1
   :widths: 25 15 18 42

   * - Parameter
     - Type
     - Default
     - Behavior
   * - maxHeaderBytes
     - int
     - 8192
     - Aggregate header name, separator and value bytes. Zero disables the check.
   * - maxHeaderCount
     - int
     - 100
     - Number of header values, including repeated header fields. Zero disables.
   * - maxBodyBytes
     - int|null
     - null
     - Null resolves PHP post_max_size at construction; a resolved zero disables the body check.
   * - bodyLimitVerbs
     - array
     - POST, PUT, PATCH, DELETE
     - Normalize methods at construction; check only listed methods.

Negative numerical limits are rejected. A null maxBodyBytes is **not** an
unlimited-size guarantee; its effective value comes from the PHP INI setting.
Prefer explicit, deployment-appropriate values.

Enforcement and HTTP responses
------------------------------

**Headers:** A request above either positive configured header limit is
rejected with HTTP **431 Request Header Fields Too Large**. Each header value
counts as a field; byte accounting includes the header name, a two-byte
separator, and the value.

**Declared request length:** Content-Length must be an unsigned decimal.
An invalid value or simultaneous nonempty Content-Length and Transfer-Encoding
yields **400 Bad Request**. A declared body larger than the configured ceiling
yields **413 Payload Too Large**.

**Stream size:** Known body sizes are compared against the ceiling. For an
unknown-size stream, the middleware reads 8-KiB chunks and stops at the first
byte beyond the limit. A seekable stream has its original cursor restored.
An accepted non-seekable body is copied into a StringBody so the handler can
still consume it.

A chunked or otherwise unknown-length request is **not** automatically
rejected: its actual bytes are checked. Unknown-size bodies can temporarily
occupy memory proportional to the configured maximum plus one chunk. Prefer
transport-level early rejection and streaming upload handling for large bodies.

The body check applies only to bodyLimitVerbs and only when the resolved limit
is greater than zero. A request with no body-check verb in the set is unaffected
by the body limit. HTTP/1.x rejection may add Connection: close; HTTP/2 and
HTTP/3 must not receive this hop-by-hop header.

This middleware does not enforce multipart file count, part size, MIME
policy, filename policy, upload storage or a particular JSON error envelope.

Slow uploads and transport responsibilities
-------------------------------------------

There is no maxUploadSeconds or violateOnUnknownBody constructor parameter.
RequestLimitsMiddleware itself does not measure upload duration or generate
a 408 on timeout.

Enforce time/connection budgets in the active transport:

* Nginx: client_max_body_size and client_body_timeout, noting that a gap
  timeout does not necessarily impose a total upload duration.
* Apache: LimitRequestBody and configured request-read timeouts such as
  mod_reqtimeout RequestReadTimeout.
* Runwire: host-defined request-body byte limits, deadlines, cancellation,
  buffering and backpressure. When form method override is enabled, routing
  may need to read a URL-encoded POST body before route middleware runs.
* PHP-FPM: align post_max_size, upload_max_filesize and web-server limits.

The transport may return 408, close the connection, or issue another
transport-specific response. Never promise that middleware returns 408.

Use an explicit consistent budget for *every* body-read path, including
transport adapters and routing-time parsing. Validate slow/aborted, oversized,
chunked and ordinary requests on the deployed runtime.

Example deployment controls
---------------------------

.. code-block:: nginx

   client_max_body_size 10M;
   client_body_timeout 30s;

.. code-block:: apache

   LimitRequestBody 10485760

These examples configure an approximate 10-MiB body ceiling; exact application
and multipart overhead semantics depend on the transport. An upstream limit
stricter than the middleware can reject a request before it reaches Webrick.

For different API/upload limits, configure independent RequestLimitsMiddleware
instances in the appropriate pipelines. **Per-route middleware cannot raise
a smaller pre-global limit**: that check has already run. Do not disable
upstream request protection merely to allow large uploads.

.. code-block:: php

   $apiLimits = new RequestLimitsMiddleware(
       maxHeaderBytes: 4_096,
       maxHeaderCount: 50,
       maxBodyBytes: 1_048_576,
   );

   $uploadLimits = new RequestLimitsMiddleware(
       maxHeaderBytes: 8_192,
       maxHeaderCount: 100,
       maxBodyBytes: 104_857_600,
   );

The host chooses where each instance is installed. For uploads, use Pathwise
or another host-selected policy for storage, authorization, MIME checking and
the file lifecycle; Webrick owns the HTTP boundary.

Troubleshooting
---------------

* **431**: inspect total header field count and header bytes, plus earlier
  transport-level header constraints.
* **413**: inspect maxBodyBytes, declared length, actual stream bytes and
  upstream body ceilings.
* **400**: inspect invalid Content-Length or conflicting Transfer-Encoding.
* **Slow upload/aborted read**: investigate transport deadlines, idle
  timeouts and cancellation rather than a nonexistent middleware timer.

The middleware does not emit custom monitoring counters, access logs or
Server-Timing headers. Instrument those at the host's existing error and
telemetry boundaries. Never log raw bodies, secrets or unbounded client data.

For runtime integration, see the Nginx/Apache/FPM and Runwire deployment guides.

Upgrading from Webrick 6.0.4 to 6.1
===================================

This guide documents the 6.1 release candidate on
``feature/risk-admission``. It does **not** claim a released 6.1 tag.
Do not activate the candidate in production until every mandatory quality
and full runtime performance certification passes at the final source SHA.

Supported installation and dependencies
---------------------------------------

Webrick 6.1 targets PHP 8.4 and 8.5. The required runtime packages include
InterMix 11.2.1 or later within its 11.x constraint and PSR Cache/Log. Optional
integrations remain opt-in: CacheLayer 4.0.2+, Pathwise 4.2.1+ and Runwire
3.0.1+ are not required for an ordinary SAPI deployment.

Test the application without development dependencies and with its configured
middleware, plugins, route attributes, named arguments and compiled container.

Compiled production artifacts
-----------------------------

Build an InterMix graph and Webrick routes as a coordinated immutable release.
Current ``ReleaseCompiler`` emits SHA-256 source digests for both executable
artifacts. ``ReleaseArtifactLoader`` validates those digests before loading
their PHP. The manifest is **not signed**: keep its bytes, artifact files
and active release directories under trusted, read-only deployment ownership.

Do not promote only the router file, copy sidecars across builds or rewrite
active compiled artifacts in-place. In particular, ``xxh128`` fingerprints
are for compatibility/consistency, not attacker authentication. Existing
6.0.4 release artifacts should be rebuilt for the 6.1 application instead
of being reused across incompatible source or dependency sets.

See :doc:`../deployments/route-cache-warmup` for the manifest and safe
activation contract.

Request limits and Runwire form routing
---------------------------------------

``RequestLimitsMiddleware`` supports exactly ``maxHeaderBytes``,
``maxHeaderCount``, ``maxBodyBytes`` and ``bodyLimitVerbs``.
It does not implement ``maxUploadSeconds`` or ``violateOnUnknownBody``.
Header-limit failures return 431; overlarge bodies return 413; invalid or
conflicting length framing returns 400 when the relevant body check applies.

Configure slow-upload, overall deadline, connection and transport-level body
limits at Nginx/Apache/Runwire. If a host enables URL-encoded form ``_method``
routing, the Runwire bridge can read the form before route middleware. The
6.1 candidate now caps this early parse to **8 MiB** by default and rejects
larger forms with 413.

Hosts whose legitimate routing forms need a different ceiling can configure:

.. code-block:: php

   use Infocyph\Webrick\Runtime\Http\RunwireRuntimeAdapter;

   $adapter = new RunwireRuntimeAdapter(
       maxRoutingFormBytes: 1_048_576,
   );

Use a positive ceiling compatible with the host's transport request limits.
This is not a general upload limit, a multipart decoder, or a replacement for
transport deadline/cancellation policy.

See :doc:`../middleware/request-limits` and
:doc:`../deployments/runwire` for exact behavior.

Response-stream compatibility
-----------------------------

Scalar output returned by a callable supplied to ``Response::stream()``
remains string-coerced as in 6.0.4. The iterable producer path continues to
require strings for individual emitted chunks; an iterable yielding integers
is not a supported streaming producer.

Test lazy streams, headers, HEAD normalization, range responses, cancellation,
compressed responses and PSR adapters through the selected runtime before
activating a new worker generation.

Telemetry and risk admission
----------------------------

Generated trace, span and request identifiers use the cryptographic
``random_bytes()`` source. The middleware no longer silently falls back to
predictable ``uniqid()`` values if the source fails. If your application
intercepts telemetry exceptions, it must not mint predictable replacement IDs.

The access logger avoids formatting through the default ``NullLogger``;
logged URL paths are bounded and line-break escaped. Consumers of text access
logs should verify their parsing rules.

``RiskAdmissionMiddleware`` is an optional host-wired boundary returning
allow, challenge, deny or unavailable decisions. The application still owns
subject identity, authenticating users, provider integration, security state,
Redis durability and fail-closed policy. No Webrick route provides a hosted
challenge service by default.

Rolling deployment and rollback
-------------------------------

1. Build the application and dependencies in a new immutable release directory.
   Recompile InterMix and Webrick artifacts together; preserve the generated
   manifest and verified source SHA-256 identities.
2. Run PHPForge, PHPStan, Psalm, unit/integration tests, security checks, Sphinx,
   no-development-dependency consumer tests and direct runtime protocol tests.
3. Complete final performance certification on the exact candidate SHA. On
   ``feature/risk-admission``, this is invoked deliberately with the existing
   ``Runtime Release Certification`` ``workflow_dispatch``, rather than
   automatically on every intermediate code push. The 2% RPM, 15% p99 and
   32-MiB RSS budgets, complete response validation and 30-minute soak
   remain mandatory.
4. Drain current PHP-FPM or Runwire workers, switch the active application
   symlink/route to the prebuilt release, restart workers as appropriate for
   OPcache settings and verify health, metrics and transport behavior.
5. For rollback, switch to the **entire** previous immutable application,
   dependency and compiled-artifact generation. Drain the 6.1 workers before
   returning to 6.0.4. Do not mix artifact manifests, router source, DI
   containers or request-scoped services between generations.

No rollout should depend on a green analysis-only CI result while required
performance or security acceptance is missing. Retain the prior release and
operational evidence until the new generation has been proven healthy.

:orphan:

Webrick 5 — Deep Correctness Revision Plan
==========================================

Scope
-----

This revision tracks the confirmed issues discovered by the post-correctness full-library sweep on ``webrick-5/batch-1-correctness`` after the earlier HTTP/runtime correctness batches.

Baseline when the plan was created: ``35d93821d4c40f859b81217d771b5e78c78f29f3`` (``style ci``).

The goal is to close the remaining correctness, persistent-worker, HTTP grammar, routing/build, cache, interop, and destructive-operation risks before final certification. Webrick 5 may tighten incorrect public behavior; do not preserve malformed or ambiguous behavior solely for backward compatibility.

Implementation rule
-------------------

Resolve in batches. Each batch should close at least 15 confirmed items when that can be done without mixing unsafe unrelated changes. Prefer complete subsystem closure over arbitrary item-count splitting. Keep the request hot path free of reflection, request-path filesystem discovery, request-derived static state, and unnecessary serialization.

--------------

Required changes
----------------

1. Route-cache destructive path safety
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

Canonicalize deletion targets before destructive operations. Reject filesystem roots, Windows drive roots, current/parent directory equivalents, traversal-resolved roots, and unsafe unresolved paths. Recursive cleanup must receive an already validated canonical directory. Symlinks must not allow deletion outside the intended cache target.

2. Non-seekable request-body preservation
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

``RequestLimitsMiddleware`` must not drain an unknown-size non-seekable body and then pass an exhausted request downstream. Incrementally enforce the limit; if accepted, replace the consumed body with a buffered request body before calling the next handler.

3. Response-cache scheme isolation
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

Include normalized URI scheme in the response-cache key so HTTP and HTTPS representations cannot collide. Bump the cache-key prefix/version.

4. Signed URL ignored-parameter symmetry
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

Generation and verification must use identical canonical signing-query rules. Ignored query parameters remain in the emitted URL but are excluded from the signature payload on both sides.

5. Signed URL integer grammar
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

Expiry/leeway/TTL strings must use canonical decimal integer grammar. Do not accept scientific notation, signed strings, decimal fractions, or general PHP ``is_numeric()`` syntax where an HTTP/configuration integer is required.

6. Remove request-derived media-extension static cache
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

Remove the arbitrary-extension memo from ``MediaTypeEnum::fromExtension()``. Fixed lookup maps are safe; request-derived static keys are not.

7. Central media-type classification
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

Use one shared JSON/XML media-type classifier. Accept structured suffixes such as ``application/problem+json`` and ``application/vnd.foo+xml``; reject lookalikes such as ``application/jsonp``.

8. Generic added-header semantics
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

``Message::withAddedHeader()`` must append field-values without generic deduplication. Header-specific builders may deduplicate where their grammar requires it.

9. ``withUri(..., preserveHost: true)`` port preservation
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

When synthesizing a Host header because none existed, include the URI port exactly as the constructor/non-preserve path does.

10. Request input API isolation
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

Normal application-input APIs and validation must operate on parsed body + query input, not cookies/server/environment compatibility variables. Legacy magic property compatibility may remain separate.

11. Uploaded-file malformed input
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

Incomplete/malformed ``$_FILES`` leaves must not become ``UPLOAD_ERR_OK`` with an empty temp path. Funnel leaves through one safe construction path and default malformed entries to ``UPLOAD_ERR_NO_FILE`` or established explicit rejection behavior.

12. Stream initialization short writes
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

``Stream::openMemory()`` must loop until the entire payload is written or fail on zero-progress/error. Do not silently truncate on partial ``fwrite()``.

13. Strict request content metadata parsing
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

Validate ``Content-Length`` as a non-negative decimal integer. Parse quoted/unquoted charset parameters correctly and consistently.

14. Shared strict qvalue parsing
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

Use one RFC-style qvalue parser across request Accept helpers, ``ContentNegotiator``, language negotiation, and compression where applicable. An explicitly malformed ``q=`` must never become implicit ``q=1``.

15. Strict HTTP-date parser
~~~~~~~~~~~~~~~~~~~~~~~~~~~

Replace generic ``strtotime()`` use in HTTP conditional/date handling with one explicit HTTP-date parser shared by ``ConditionalValidator``, request-header helpers, and cache-validator middleware.

16. UAParser corrections
~~~~~~~~~~~~~~~~~~~~~~~~

Detect Android before generic Linux, normalize quoted Client Hint values, correct Edge token/version parsing, and keep fixed token tables immutable.

17. Response status invariant
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

Direct ``Response`` construction and status mutation must reject status codes outside Webrick's supported HTTP range (100–599).

18. Case-insensitive response helper headers
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

Response helpers must not inspect caller header arrays by exact PHP key casing. Normalize through the common header layer before checking/removing defaults and framing fields.

19. Cookie Max-Age exactness
~~~~~~~~~~~~~~~~~~~~~~~~~~~~

Store Max-Age independently from Expires so ``maxAge(3600)`` serializes as exactly ``Max-Age=3600`` instead of drifting with wall-clock time.

20. Cookie domain validation
~~~~~~~~~~~~~~~~~~~~~~~~~~~~

Reject malformed domains, schemes, ports, controls, whitespace, and URI delimiters. Normalize leading-dot compatibility deliberately.

21. Public Range helper unification
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

Do not maintain an unsafe second range parser. Delegate the public Range helper to the canonical ``RangeParser``/shared range semantics; reject malformed grammar and clamp valid excessive ends to EOF.

22. Externally supplied range validation
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

Validate any prebuilt range against representation length before seeking/streaming. Invalid supplied ranges must resolve to correct 416 semantics.

23. Case-insensitive range response headers
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

Normalize caller headers before range-specific ETag/Content-Type/Content-Length/Content-Range processing.

24. CLI emitter semantic alignment
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

CLI/test emission must share production HEAD/bodyless-status/streaming rules. It must not mask protocol defects by emitting bodies production runtimes suppress.

25. Runtime file fast-path metadata
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

Use ``FileBody``'s captured validated metadata instead of re-statting a file merely to retrieve known length during runtime response write.

26. CORS Private Network Access Vary
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

When PNA behavior can affect a preflight response, include ``Vary: Access-Control-Request-Private-Network``.

27. Telemetry request-ID validation
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

Use a single bounded visible-token request-ID normalization rule for telemetry, response headers, logging, tracing, and errors. Invalid incoming IDs should generate a fresh ID rather than be reflected.

28. PSR response streaming conversion
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

Producer-backed streaming responses must not silently become empty during PSR-7 conversion. Buffer explicitly or throw an explicit unsupported conversion error.

29. PSR source stream cursor preservation
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

Save and restore seekable native request/response stream positions in ``finally`` during bridge conversion.

30. PSR uploaded files
~~~~~~~~~~~~~~~~~~~~~~

Preserve uploaded-file trees during native→PSR server-request conversion when the necessary PSR factory is supplied; otherwise fail explicitly rather than silently dropping uploads.

31. Duplicate canonical route detection at registration
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

Maintain a canonical route identity index in ``Collection`` and reject duplicates atomically during registration. Matcher duplicate checks remain defense in depth.

32. IPv6 route constraint
~~~~~~~~~~~~~~~~~~~~~~~~~

Use a proper IPv6 validator (prefer ``filter_var(..., FILTER_FLAG_IPV6)``) so compressed IPv6 forms such as ``2001:db8::1`` and ``::1`` work.

33. Custom PCRE paired delimiters
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

Support paired PHP PCRE delimiters ``()``, ``[]``, ``{}``, ``<>`` in constraint parsing while preserving escaped delimiters/modifiers.

34. Build/runtime route-host grammar alignment
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

Make build-time route host canonicalization delegate to the same strict URI host normalization used at runtime.

35. Cache-Control delta-seconds grammar
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

All recognized delta-seconds must be decimal digits only with overflow-safe conversion; reject leading plus signs and other PHP numeric syntax.

36. Language helper hardening
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

Use the shared qvalue parser, handle an empty supported-language list explicitly, preserve stable preference ordering, and do not upgrade malformed qvalues.

37. RateLimit guards
~~~~~~~~~~~~~~~~~~~~

Reject negative limit/remaining/reset/retry-after values. Keep behavior deterministic for inconsistent values.

38. Vary token validation
~~~~~~~~~~~~~~~~~~~~~~~~~

Only accept ``*`` or valid HTTP field-name tokens in Vary builders/parsers.

39. Consolidate duplicated HTTP grammar parsers
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

After fixes, replace local simplified implementations of qvalues, HTTP dates, media types, ranges, header tokens, delta-seconds, and related grammars with canonical helpers where practical.

40. Repository-wide response-header casing audit
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

Remove raw case-sensitive caller-header operations such as direct ``isset($headers['Content-Type'])``/``unset($headers['Content-Length'])`` where maps have not already been canonicalized.

41. Strict numeric-configuration audit
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

Audit remaining ``is_numeric()`` and direct numeric string casts used for HTTP/configuration integer grammar. Tighten TTL, max-age, expiry, ports, limits, route-cache options, and signing leeway where applicable.

42. Persistent-worker static-state audit
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

Allow only immutable/fixed statics or boot-frozen configuration. No request-derived filenames, extensions, URI/header/path values, UA data, route params, client data, or response values may accumulate in process-global/static maps.

--------------

Regression coverage required
----------------------------

Add focused tests for every corrected behavior, including at minimum:

- dangerous route-cache path canonicalization;
- non-seekable body preservation below/at/above limit;
- HTTP/HTTPS response-cache isolation;
- ignored signed query round-trip and canonical expiry grammar;
- arbitrary extension inputs not growing static state;
- JSON/XML structured media suffixes;
- duplicate added headers;
- preserved Host port;
- cookie/server/env values not satisfying application-input validation;
- malformed uploads;
- short stream writes where testable;
- invalid Content-Length, quoted charset, strict qvalues and HTTP dates;
- Android/Client Hint/Edge UA cases;
- invalid response status;
- mixed-case caller headers;
- exact cookie Max-Age and invalid domains;
- malformed/excessive/supplied ranges;
- CLI HEAD/bodyless/streaming behavior;
- PNA Vary;
- request-ID validation;
- PSR cursor/streaming/upload behavior;
- duplicate canonical route registration;
- compressed IPv6 and paired-PCRE constraints;
- build-time invalid route hosts;
- Cache-Control numeric grammar;
- Language/RateLimit/Vary helpers.

--------------

Priority
--------

Batch A — safety, state and input integrity
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

1.  Route-cache destructive path protection.
2.  Non-seekable request-body preservation.
3.  Response-cache scheme isolation.
4.  Signed URL ignored-parameter symmetry.
5.  Signed URL integer grammar.
6.  Media extension static-cache removal.
7.  Central media-type classification.
8.  ``withAddedHeader()`` semantics.
9.  Host-port preservation.
10. Request input isolation.
11. Uploaded-file normalization.
12. Stream short-write handling.
13. Strict request content parsing.
14. Shared qvalues.
15. Strict HTTP dates.

Batch B — response/protocol/public helper correctness
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

16. UAParser.
17. Response status validation.
18. Response helper header casing.
19. Cookie Max-Age.
20. Cookie domain validation.
21. Range helper unification.
22. Supplied range validation.
23. Range header casing.
24. CLI emitter parity.
25. File fast-path metadata.
26. CORS PNA Vary.
27. Telemetry request ID.

Batch C — interop/build/consolidation
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

28. PSR streaming conversion.
29. PSR cursor preservation.
30. PSR uploads.
31. Duplicate canonical routes at registration.
32. IPv6 constraint.
33. Paired PCRE delimiters.
34. Route-host grammar alignment.
35. Cache-Control delta grammar.
36. Language helper.
37. RateLimit guards.
38. Vary validation.
39. Duplicated parser consolidation.
40. Header casing sweep.
41. Numeric configuration sweep.
42. Persistent static-state sweep.

--------------

Performance requirements
------------------------

Correctness fixes must preserve Webrick 5's runtime architecture:

- no request-path reflection;
- no normal-request filesystem discovery;
- no request-derived static memoization;
- no request-path route compilation;
- no unnecessary request-path serialization;
- build-time route/constraint/host validation stays at build time;
- non-seekable buffering only occurs when the transport has not already enforced the limit and the body size is unknown;
- shared parsers should be small stateless helpers/value objects.

--------------

Completion criteria
-------------------

This deep correctness revision is implementation-complete when all confirmed items above are either fixed or proven non-applicable, regression tests cover the corrected contracts, duplicated protocol grammars have been consolidated where appropriate, destructive filesystem paths are canonicalized before deletion, no silent body/interop data loss remains, and no request-derived persistent-worker static growth remains.

Final certification is a separate final phase and should run the full PHPForge stack without baselines or suppressions hiding real errors:

- Pest
- PHPProbe
- Pint
- PHPCS
- Deptrac
- PHPStan
- Psalm
- Rector dry-run
- Composer Normalize dry-run

After that, run persistent-worker/concurrency checks and performance benchmarks against the production compiled path.

Matcher Cache Reference
=======================

``RouteCache`` builds matcher cache artifacts only. It is a build-plane optimization for ``GeneratedMatcher``, ``FusedMatcher``, or ``ShardedMatcher``; it does not boot ``RouterKernel``, create URL services, or initialize an InterMix runtime.

The complete production application artifact is a separate concern handled by ``RouteCompiler``, ``RouterArtifactCompiler``, and ``ReleaseCompiler``.

Modes
-----

.. list-table:: Matcher-cache modes
   :header-rows: 1
   :widths: 14 16 25 45

   * - Matcher
     - Cache location
     - Measured role
     - Approximate guidance
   * - ``fused``
     - PHP file
     - **default/general production matcher**
     - Valid across the measured range; generally the safer warm-latency/artifact choice from roughly 2,250 routes onward.
   * - ``generated``
     - PHP file
     - **small-to-medium/simple-topology specialization**
     - Strong measured candidate through roughly 1,500 routes in the current synthetic envelope and near parity around 1,750–2,000; benchmark explicitly beyond that.
   * - ``sharded``
     - directory
     - **cold-boot / bounded-working-set specialization**
     - Evaluate once several-thousand-route cache boot or loaded state becomes material; particularly useful when workers touch only part of a large table.

Prefer an explicit ``matcher`` value in deployment tooling. Start from ``fused`` when the route topology has not been measured, but do not dismiss Generated for low-thousands simple route tables: the completed crossover study widened its evidence-based envelope substantially.

A practical starting guide is:

- **below ~1,000 routes:** Fused is the safe default; Generated is a strong benchmark candidate for simple/static/distinct route sets;
- **~1,000–1,500 routes:** benchmark Fused and Generated; the current synthetic envelope materially favored Generated;
- **~1,500–2,250 routes:** treat this as a crossover zone and benchmark both;
- **~2,250–5,000 routes:** normally prefer Fused; keep Generated only for a repeatable topology-specific win and begin considering Sharded if startup/working-set pressure appears;
- **~5,000–10,000 routes:** Fused for fully resident warm throughput, Sharded for lazy boot/residency; Generated is not a general choice;
- **10,000+ routes:** benchmark Fused and Sharded side by side.

Route count is not an automatic selector. Generated showed non-monotonic isolated results at some larger sizes, but at 5,000 routes the current envelope also exposed a severe generated-code cliff: about **69.001 µs** warm versus **1.745 µs** Fused, with a roughly **26.04 MB** cache artifact versus **9.76 MB** Fused. Sharded's final cached candidate-group memoization reduced its warm overhead substantially, bringing 5,000/10,000-route warm measurements to roughly **2.24/2.41 µs** while retaining lazy first-shard loading.

PHP API
-------

.. code:: php

   use Infocyph\Webrick\Support\RouteCache;

   $artifact = RouteCache::build([
       'matcher' => 'fused',
       'cache' => __DIR__ . '/../.route-cache/fused.php',
       'routes' => __DIR__ . '/../routes.php',
       'attributeDirs' => [
           'App\\Http\\' => __DIR__ . '/../src/Http',
       ],
   ]);

Supported build options:

+----------------------+---------------------------------------------------------------+
| Key                  | Meaning                                                       |
+======================+===============================================================+
| ``matcher``          | ``generated``, ``fused``, or ``sharded``                      |
+----------------------+---------------------------------------------------------------+
| ``cache``            | required output file/directory                                |
+----------------------+---------------------------------------------------------------+
| ``routes``           | route file; use this or ``register``                          |
+----------------------+---------------------------------------------------------------+
| ``register``         | registration callable; use this or ``routes``                 |
+----------------------+---------------------------------------------------------------+
| ``registrarOptions`` | build-time registrar options such as ``autoSlashRedirect``    |
+----------------------+---------------------------------------------------------------+
| ``signKey``          | optional build-time signing key exposed to legacy route files |
+----------------------+---------------------------------------------------------------+
| ``signedDefaultTtl`` | optional registrar signing default                            |
+----------------------+---------------------------------------------------------------+
| ``signedUrlConfig``  | optional ``SignedUrlConfig``/array                            |
+----------------------+---------------------------------------------------------------+
| ``urlBaseUri``       | optional registrar base URI                                   |
+----------------------+---------------------------------------------------------------+
| ``attributeDirs``    | namespace → directory attribute-discovery map                 |
+----------------------+---------------------------------------------------------------+
| ``attributeClasses`` | explicit attribute route classes                              |
+----------------------+---------------------------------------------------------------+
| ``logger``           | optional PSR-3 build logger                                   |
+----------------------+---------------------------------------------------------------+

There are no runtime middleware, alias-fallback, DI-container, or URL-service binding options. Those concerns do not belong to matcher-cache generation.

CLI
---

.. code:: bash

   php ./webrick route:cache --matcher=fused --cache=.route-cache/fused.php --routes=routes.php
   php ./webrick route:cache --matcher=generated --cache=.route-cache/generated.php --routes=routes.php
   php ./webrick route:cache --matcher=sharded --cache=.route-cache --routes=routes.php

Optional CLI inputs include ``--signkey``, ``--ttl``, ``--attr-dirs``, and ``--attr-classes``.

Build flow
----------

Matcher-cache generation is intentionally simple:

1. create a build-only ``Registrar`` and ``Collection``;
2. execute the route registration input inside a scoped ``Router`` facade binding;
3. compile route definitions once;
4. feed the compiled routes into the selected matcher;
5. enable cache-write mode and finalize the matcher;
6. atomically publish the matcher's cache format.

No request kernel, request scope, controller invocation, middleware pipeline, application container, or response emitter is created.

Cache contents
--------------

Depending on matcher mode, cache artifacts contain the matcher structures required to reconstruct routing state, including route descriptors, alias metadata, middleware alias requirements, constraints and generated/compiled matching data.

Fused persists the precompiled method-first/static-map and combined-PCRE matcher IR. Sharded persists the same matcher IR partitioned into immutable shards and loads only the required groups. Generated persists its separate generated-code strategy.

They do not contain application service instances, current request state, resolved middleware objects, native runtime handles, or an InterMix runtime.

Clearing
--------

.. code:: bash

   php ./webrick route:clear --matcher=fused --cache=.route-cache/fused.php
   php ./webrick route:clear --matcher=generated --cache=.route-cache/generated.php
   php ./webrick route:clear --matcher=sharded --cache=.route-cache

For sharded cache, ``--aggressive=1`` recursively clears generated artifacts while preserving the root ``.gitignore``.

Production release artifacts
----------------------------

Do not confuse matcher cache with the strict Webrick production artifact. ``ReleaseCompiler`` coordinates:

- the host-owned InterMix compiled runtime;
- Webrick compiled routes;
- execution plans and capabilities;
- route aliases;
- global middleware descriptors/tags;
- environment and configuration fingerprints;
- a release manifest containing trusted artifact digests.

``CompiledRouterKernel`` consumes that release artifact with the host-selected ``ProductionContainer``.

Deployment rules
----------------

- Build artifacts during CI/deployment, never during ordinary requests.
- Treat generated PHP cache/artifact files as trusted executable deployment data.
- Publish complete release sets atomically from the deployment layer.
- Keep runtime artifacts read-only to serving workers where possible.
- Rebuild matcher caches and production release artifacts after a Webrick major upgrade or route-schema change.
- Use Fused as the general default; benchmark Generated seriously for simple route sets into the low thousands, and evaluate Sharded around several thousand routes when startup/working-set cost becomes material.
